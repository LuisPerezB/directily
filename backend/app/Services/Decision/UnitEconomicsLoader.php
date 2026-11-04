<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;

/**
 * Precio neto y costo unitario CONOCIDOS de un producto a una fecha de corte, con la confiabilidad del costo (V2.5 · W5-B).
 *
 * Lee las versiones de ventas vigentes tal como se conocían el día del corte (`sales_line_versions`, la misma regla as-of de W1 que usa el motor:
 * `max(sale_date, día_local(known_from)) ≤ corte` y `known_until` abierta o posterior). No usa `products.current_cost` (es el valor de HOY, fuga al pasado).
 *
 * Fuente 1: costo histórico en las líneas de venta de la ventana. Confiable solo si (todas, parámetros por empresa «a aprobar en el piloto»):
 *   - al menos `min_observations` líneas con costo conocido y positivo (`insufficient_cost_observations`),
 *   - la cobertura (líneas con costo / líneas) ≥ `min_coverage` (`cost_coverage_low`),
 *   - el coeficiente de variación del costo ≤ `max_cv` (`cost_dispersion_high`).
 * Fuente 2 (solo si la 1 no es confiable): historial de costos de compra del último año con `known_at` conocido y anterior al corte, mismas reglas de observaciones y dispersión.
 * Sin ninguna fuente confiable el costo es NULL con los motivos de ambas: la contribución no se calcula.
 */
#[Scoped]
final class UnitEconomicsLoader
{
    /** @var array<string, UnitEconomics> */
    private array $cache = [];

    public function flush(): void
    {
        $this->cache = [];
    }

    /** @param list<int> $productIds */
    public function prime(Tenant $tenant, DecisionSettings $settings, CarbonImmutable $asOf, array $productIds): void
    {
        $missing = array_values(array_filter(array_unique($productIds), fn (int $id) => ! isset($this->cache[$this->key($tenant, $asOf, $id)])));
        foreach (array_chunk($missing, 500) as $chunk) {
            $sales = $this->salesStats($tenant, $settings, $asOf, $chunk);
            $purchases = $this->purchaseStats($tenant, $settings, $asOf, $chunk);
            foreach ($chunk as $productId) {
                $this->cache[$this->key($tenant, $asOf, $productId)] = $this->resolve($productId, $sales[$productId] ?? null, $purchases[$productId] ?? null, $settings);
            }
        }
    }

    public function forProduct(Tenant $tenant, DecisionSettings $settings, CarbonImmutable $asOf, int $productId): UnitEconomics
    {
        $this->prime($tenant, $settings, $asOf, [$productId]);

        return $this->cache[$this->key($tenant, $asOf, $productId)];
    }

    private function key(Tenant $tenant, CarbonImmutable $asOf, int $productId): string
    {
        return $tenant->getKey().'|'.$asOf->toDateString().'|'.$productId;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private function salesStats(Tenant $tenant, DecisionSettings $settings, CarbonImmutable $asOf, array $ids): array
    {
        $zone = $tenant->timezone ?: 'UTC';
        $window = $settings->costReliability()['window_days'];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $known = 'v.unit_cost IS NOT NULL AND v.unit_cost > 0';
        $rows = DB::select(
            "SELECT v.product_id, COUNT(*) AS n_lines, SUM(v.quantity) AS qty, SUM(v.net_amount) AS net,
                    COUNT(*) FILTER (WHERE {$known}) AS n_cost,
                    SUM(v.quantity * v.unit_cost) FILTER (WHERE {$known}) AS cost_total,
                    SUM(v.quantity) FILTER (WHERE {$known}) AS cost_qty,
                    AVG(v.unit_cost) FILTER (WHERE {$known}) AS cost_avg,
                    STDDEV_POP(v.unit_cost) FILTER (WHERE {$known}) AS cost_sd
             FROM sales_line_versions v
             WHERE v.tenant_id = ? AND v.product_id IN ({$in})
               AND v.status = 'completed' AND v.document_type IN ('invoice','sales_note','other') AND NOT v.is_return
               AND v.sale_date <= ?::date AND v.sale_date > (?::date - CAST(? AS integer))
               AND GREATEST(v.sale_date, CAST(v.known_from AT TIME ZONE CAST(? AS text) AS date)) <= ?::date
               AND (v.known_until IS NULL OR (CAST(v.known_until AT TIME ZONE CAST(? AS text) AS date) - 1) >= ?::date)
             GROUP BY v.product_id",
            [$tenant->getKey(), ...$ids, $asOf->toDateString(), $asOf->toDateString(), $window, $zone, $asOf->toDateString(), $zone, $asOf->toDateString()],
        );

        return collect($rows)->mapWithKeys(fn ($r) => [(int) $r->product_id => $r])->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private function purchaseStats(Tenant $tenant, DecisionSettings $settings, CarbonImmutable $asOf, array $ids): array
    {
        $zone = $tenant->timezone ?: 'UTC';
        $window = max($settings->costReliability()['window_days'], 365);   // las compras son menos frecuentes que las ventas: ventana de al menos un año
        $in = implode(',', array_fill(0, count($ids), '?'));
        // Conocido antes del fin del día del corte (medianoche local de C+1); known_at NULL = desconocido → no entra.
        $rows = DB::select(
            "SELECT p.product_id, COUNT(*) AS n, AVG(p.unit_cost) AS cost_avg, STDDEV_POP(p.unit_cost) AS cost_sd
             FROM purchase_cost_history p
             WHERE p.tenant_id = ? AND p.product_id IN ({$in}) AND p.unit_cost > 0
               AND p.valid_from <= ?::date AND p.valid_from > (?::date - CAST(? AS integer))
               AND p.known_at IS NOT NULL AND CAST(p.known_at AT TIME ZONE CAST(? AS text) AS date) <= ?::date
             GROUP BY p.product_id",
            [$tenant->getKey(), ...$ids, $asOf->toDateString(), $asOf->toDateString(), $window, $zone, $asOf->toDateString()],
        );

        return collect($rows)->mapWithKeys(fn ($r) => [(int) $r->product_id => $r])->all();
    }

    private function resolve(int $productId, ?object $sales, ?object $purchases, DecisionSettings $settings): UnitEconomics
    {
        $rules = $settings->costReliability();
        $price = $sales !== null && (float) $sales->qty > 0 ? round((float) $sales->net / (float) $sales->qty, 6) : null;
        $stats = ['window_days' => $rules['window_days'], 'sales_lines' => (int) ($sales->n_lines ?? 0), 'rules' => $rules];
        $reasons = [];

        if ($sales === null) {
            $reasons[] = 'no_sales_history';
        } else {
            $nCost = (int) $sales->n_cost;
            $coverage = (int) $sales->n_lines > 0 ? $nCost / (int) $sales->n_lines : 0.0;
            $cv = $nCost > 1 && (float) $sales->cost_avg > 0 ? (float) $sales->cost_sd / (float) $sales->cost_avg : ($nCost === 1 ? 0.0 : null);
            $stats += ['sales_lines_with_cost' => $nCost, 'cost_coverage' => round($coverage, 4), 'cost_cv' => $cv === null ? null : round($cv, 4)];
            $problems = [];
            if ($nCost === 0) {
                $problems[] = 'no_cost_in_sales_lines';
            } else {
                if ($nCost < $rules['min_observations']) {
                    $problems[] = 'insufficient_cost_observations';
                }
                if ($coverage < $rules['min_coverage']) {
                    $problems[] = 'cost_coverage_low';
                }
                if ($cv !== null && $cv > $rules['max_cv']) {
                    $problems[] = 'cost_dispersion_high';
                }
            }
            if ($problems === []) {
                $cost = round((float) $sales->cost_total / (float) $sales->cost_qty, 6);

                return new UnitEconomics($productId, $price, $cost, 'sales_lines', true, [], $stats);
            }
            array_push($reasons, ...$problems);
        }

        if ($purchases !== null) {
            $n = (int) $purchases->n;
            $cv = $n > 1 && (float) $purchases->cost_avg > 0 ? (float) $purchases->cost_sd / (float) $purchases->cost_avg : ($n === 1 ? 0.0 : null);
            $stats += ['purchase_observations' => $n, 'purchase_cost_cv' => $cv === null ? null : round($cv, 4)];
            if ($n >= $rules['min_observations'] && ($cv === null || $cv <= $rules['max_cv'])) {
                return new UnitEconomics($productId, $price, round((float) $purchases->cost_avg, 6), 'purchase_cost_history', true, [], $stats);
            }
            $reasons[] = $n < $rules['min_observations'] ? 'purchase_history_insufficient' : 'purchase_history_dispersion_high';
        } else {
            $reasons[] = 'no_purchase_cost_history';
        }

        return new UnitEconomics($productId, $price, null, null, false, array_values(array_unique($reasons)), $stats);
    }
}
