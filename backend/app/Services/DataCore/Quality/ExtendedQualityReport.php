<?php

declare(strict_types=1);

namespace App\Services\DataCore\Quality;

use App\Models\Tenant;
use App\Services\DataCore\Coverage\CoverageService;
use App\Services\DataCore\Units\UnitNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reporte de calidad ampliado del core de Datos (§6.C): cobertura temporal por fuente, días sin ventas frente a
 * ausencia de registros, compradores sin identificador estable, cambios de código de producto, cambios históricos
 * de precio y unidades incompatibles. Calculado bajo demanda, siempre filtrado por empresa. No es un puntaje de precisión.
 */
final class ExtendedQualityReport
{
    private const MAX_RANGE_DAYS = 3660;

    public function __construct(private readonly CoverageService $coverage) {}

    /** @return array<string, mixed> */
    public function build(Tenant $tenant): array
    {
        $tz = $tenant->timezone ?: 'America/Guayaquil';
        $today = CarbonImmutable::now($tz)->toDateString();
        $sections = [
            'temporal_coverage' => $this->temporalCoverage($tenant->id, $today),
            'sales_days' => $this->salesDays($tenant->id, $today),
            'customer_identity' => $this->customerIdentity($tenant->id),
            'product_code_changes' => $this->productCodeChanges($tenant->id),
            'price_history' => $this->priceHistory($tenant->id),
            'units' => $this->units($tenant->id),
        ];
        $statuses = array_column($sections, 'status');

        return [
            'generated_at' => now()->toIso8601String(),
            'as_of_local_date' => $today,
            'overall_status' => in_array('warning', $statuses, true) ? 'warning' : 'ok',
            'sections' => $sections,
            'notes' => [
                'El reporte describe la calidad de los datos cargados; no mide la precisión de ningún pronóstico.',
                'Fuera de la cobertura declarada o inferida, un día sin registros es AUSENTE, no cero.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function temporalCoverage(int $tenantId, string $today): array
    {
        $observed = [
            'sales' => DB::selectOne("SELECT MIN(sale_date) AS d_from, MAX(sale_date) AS d_to, COUNT(DISTINCT sale_date) AS days FROM sales WHERE tenant_id = ? AND status = 'completed' AND document_type <> 'credit_note'", [$tenantId]),
            'inventory' => DB::selectOne('SELECT MIN(snapshot_date) AS d_from, MAX(snapshot_date) AS d_to, COUNT(DISTINCT snapshot_date) AS days FROM inventory_snapshots WHERE tenant_id = ?', [$tenantId]),
            'commitments' => DB::selectOne('SELECT MIN(issued_at::date) AS d_from, MAX(issued_at::date) AS d_to, COUNT(DISTINCT issued_at::date) AS days FROM commercial_commitments WHERE tenant_id = ?', [$tenantId]),
            'promotions' => DB::selectOne('SELECT MIN(starts_on) AS d_from, MAX(ends_on) AS d_to, COUNT(*) AS days FROM promotions WHERE tenant_id = ? AND deleted_at IS NULL', [$tenantId]),
        ];
        $entities = [];
        $warning = false;
        foreach ($observed as $entity => $row) {
            $effective = $this->coverage->effective($entity);
            $hasData = $row->d_from !== null;
            $status = match (true) {
                ! $hasData => 'no_data',
                $effective['rule'] === 'none' => 'undeclared',
                $effective['complete_through'] !== null && $row->d_to > $effective['complete_through'] => 'data_beyond_coverage',
                $effective['complete_through'] !== null && $effective['complete_through'] < CarbonImmutable::parse($today)->subDays(7)->toDateString() => 'stale',
                default => $effective['basis'] === 'declared' ? 'declared' : 'inferred',
            };
            $warning = $warning || in_array($status, ['undeclared', 'stale', 'data_beyond_coverage'], true);
            $entities[$entity] = [
                'observed_from' => $row->d_from, 'observed_to' => $row->d_to, 'days_with_records' => (int) $row->days,
                'coverage' => $effective, 'status' => $status,
                'message' => match ($status) {
                    'undeclared' => 'No se declaró hasta cuándo está completa esta fuente: los días sin registros se tratan como AUSENTES, no como cero.',
                    'stale' => 'La cobertura de la fuente tiene más de 7 días de antigüedad.',
                    'data_beyond_coverage' => 'Hay datos posteriores a la fecha de cobertura: actualice la cobertura de la fuente.',
                    default => null,
                },
            ];
        }

        return ['status' => $warning ? 'warning' : 'ok', 'entities' => $entities];
    }

    /** @return array<string, mixed> */
    private function salesDays(int $tenantId, string $today): array
    {
        $row = DB::selectOne("SELECT MIN(sale_date) AS d_from, MAX(sale_date) AS d_to FROM sales WHERE tenant_id = ? AND status = 'completed' AND document_type <> 'credit_note'", [$tenantId]);
        if ($row->d_from === null) {
            return ['status' => 'no_data', 'message' => 'No hay ventas cargadas.'];
        }
        $effective = $this->coverage->effective('sales');
        $covered = $effective['complete_through'] !== null;
        // Con cobertura declarada, el rango empieza donde la fuente es completa (aunque aún no hubiera ventas); sin ella, en la primera venta.
        $from = $covered && $effective['complete_from'] !== null ? $effective['complete_from'] : $row->d_from;
        $observedTo = $row->d_to;
        $to = $covered ? min($effective['complete_through'], $today) : min($observedTo, $today);
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > self::MAX_RANGE_DAYS) {
            $from = CarbonImmutable::parse($to)->subDays(self::MAX_RANGE_DAYS)->toDateString();
        }
        $missing = collect(DB::select(
            "SELECT d::date AS day FROM generate_series(?::date, ?::date, interval '1 day') AS g(d) "
            ."WHERE NOT EXISTS (SELECT 1 FROM sales s WHERE s.tenant_id = ? AND s.sale_date = g.d::date AND s.status = 'completed' AND s.document_type <> 'credit_note') ORDER BY 1",
            [$from, $to, $tenantId],
        ))->pluck('day')->all();
        $span = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;

        // Tramos consecutivos sin ventas.
        $runs = [];
        $start = null;
        $prev = null;
        foreach ($missing as $day) {
            if ($prev !== null && CarbonImmutable::parse($prev)->addDay()->toDateString() === $day) {
                $prev = $day;

                continue;
            }
            if ($start !== null) {
                $runs[] = [$start, $prev];
            }
            $start = $prev = $day;
        }
        if ($start !== null) {
            $runs[] = [$start, $prev];
        }
        $longest = collect($runs)->map(fn (array $r) => ['from' => $r[0], 'to' => $r[1], 'days' => (int) CarbonImmutable::parse($r[0])->diffInDays(CarbonImmutable::parse($r[1])) + 1])
            ->sortByDesc('days')->take(5)->values()->all();
        $afterCoverage = $covered && $today > $effective['complete_through'] ? (int) CarbonImmutable::parse($effective['complete_through'])->diffInDays(CarbonImmutable::parse($today)) : null;

        return [
            'status' => $covered ? 'ok' : 'warning',
            'range' => ['from' => $from, 'to' => $to, 'days' => $span],
            'days_without_sales' => count($missing),
            // Dentro de una cobertura completa, un día sin ventas es un CERO observado (cierre o sin demanda).
            // Sin cobertura declarada no se puede distinguir: son días AUSENTES (no se deben convertir en ceros).
            'classification' => $covered ? 'observed_zero_days' : 'absent_days_coverage_unknown',
            'observed_zero_days' => $covered ? count($missing) : 0,
            'absent_days' => $covered ? ($afterCoverage ?? 0) : count($missing),
            'days_after_coverage' => $afterCoverage,
            'longest_gaps' => $longest,
            'message' => $covered
                ? 'La fuente declara datos completos hasta '.$effective['complete_through'].': los días sin ventas en ese período son ceros observados; los posteriores son ausentes.'
                : 'Sin cobertura declarada: los días sin ventas NO se pueden distinguir de datos ausentes y no se convertirán en ceros de demanda.',
        ];
    }

    /** @return array<string, mixed> */
    private function customerIdentity(int $tenantId): array
    {
        $docs = DB::selectOne(
            "SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE s.customer_id IS NULL) AS without_customer, COUNT(*) FILTER (WHERE c.is_generic) AS generic "
            ."FROM sales s LEFT JOIN customers c ON c.tenant_id = s.tenant_id AND c.id = s.customer_id WHERE s.tenant_id = ? AND s.status = 'completed' AND s.document_type <> 'credit_note'",
            [$tenantId],
        );
        $customers = DB::selectOne(
            'SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE is_generic) AS generic, COUNT(*) FILTER (WHERE NOT is_generic AND tax_id_hash IS NULL) AS without_tax_id FROM customers WHERE tenant_id = ? AND deleted_at IS NULL',
            [$tenantId],
        );
        $total = (int) $docs->total;
        $unstable = (int) $docs->without_customer + (int) $docs->generic;
        $share = $total > 0 ? round($unstable / $total, 4) : null;

        return [
            'status' => $share !== null && $share > 0.3 ? 'warning' : 'ok',
            'documents_total' => $total,
            'documents_without_customer' => (int) $docs->without_customer,
            'documents_with_generic_customer' => (int) $docs->generic,
            'share_without_stable_identifier' => $share,
            'customers_total' => (int) $customers->total,
            'generic_customers' => (int) $customers->generic,
            'customers_without_tax_id' => (int) $customers->without_tax_id,
            'message' => 'Las ventas sin cliente o con código genérico (consumidor final) no representan un comprador recurrente: el comportamiento de compra las trata como población anónima.',
        ];
    }

    /** @return array<string, mixed> */
    private function productCodeChanges(int $tenantId): array
    {
        $groups = DB::select(
            "SELECT lower(regexp_replace(btrim(name), '\\s+', ' ', 'g')) AS norm_name, json_agg(id ORDER BY id) AS ids, json_agg(sku ORDER BY id) AS skus "
            .'FROM products WHERE tenant_id = ? AND deleted_at IS NULL GROUP BY 1 HAVING COUNT(*) > 1 ORDER BY COUNT(*) DESC, 1 LIMIT 50',
            [$tenantId],
        );
        $result = [];
        foreach ($groups as $group) {
            $ids = array_map('intval', json_decode((string) $group->ids, true) ?: []);
            $skus = json_decode((string) $group->skus, true) ?: [];
            $periods = DB::select('SELECT product_id, MIN(sale_date) AS first_sale, MAX(sale_date) AS last_sale FROM sale_items WHERE tenant_id = ? AND product_id IN ('.implode(',', $ids).') GROUP BY product_id ORDER BY MIN(sale_date)', [$tenantId]);
            $overlap = false;
            $previousEnd = null;
            foreach ($periods as $period) {
                if ($previousEnd !== null && $period->first_sale <= $previousEnd) {
                    $overlap = true;
                }
                $previousEnd = $period->last_sale;
            }
            $result[] = [
                'name' => $group->norm_name, 'skus' => $skus,
                'pattern' => count($periods) < 2 ? 'insufficient_sales' : ($overlap ? 'parallel_codes_possible_duplicate' : 'sequential_codes_possible_code_change'),
                'periods' => array_map(fn ($p) => ['product_id' => (int) $p->product_id, 'first_sale' => $p->first_sale, 'last_sale' => $p->last_sale], $periods),
            ];
        }

        return [
            'status' => $result === [] ? 'ok' : 'warning',
            'basis' => 'heuristic_same_normalized_name',
            'groups_detected' => count($result),
            'groups' => array_slice($result, 0, 20),
            'message' => $result === []
                ? 'No se detectaron productos con el mismo nombre y códigos distintos. No hay historial de códigos en la fuente: un cambio de código con otro nombre no se puede detectar.'
                : 'Productos con el mismo nombre y códigos distintos: pueden ser un cambio de código (su historia se partiría en dos series) o duplicados. Revise y unifique antes de pronosticar.',
        ];
    }

    /** @return array<string, mixed> */
    private function priceHistory(int $tenantId): array
    {
        $rows = DB::select(
            'SELECT product_id, COUNT(DISTINCT unit_price) AS n_prices, MIN(unit_price) AS min_price, MAX(unit_price) AS max_price, MIN(sale_date) AS first_sale, MAX(sale_date) AS last_sale '
            .'FROM sale_items WHERE tenant_id = ? AND is_return = false AND unit_price > 0 GROUP BY product_id',
            [$tenantId],
        );
        $varying = array_filter($rows, fn ($r) => (int) $r->n_prices >= 2);
        $big = array_filter($varying, fn ($r) => (float) $r->max_price / max((float) $r->min_price, 0.0001) - 1 > 0.2);
        usort($varying, fn ($a, $b) => ((float) $b->max_price / max((float) $b->min_price, 0.0001)) <=> ((float) $a->max_price / max((float) $a->min_price, 0.0001)));
        $history = DB::selectOne('SELECT COUNT(*) AS n, COUNT(*) FILTER (WHERE known_at IS NULL) AS unknown_known_at FROM product_price_history WHERE tenant_id = ?', [$tenantId]);
        $names = $varying === [] ? [] : DB::table('products')->where('tenant_id', $tenantId)->whereIn('id', array_map(fn ($r) => (int) $r->product_id, array_slice($varying, 0, 10)))->pluck('sku', 'id')->all();

        return [
            'status' => 'ok',
            'products_with_sales' => count($rows),
            'products_with_price_variation' => count($varying),
            'products_with_variation_over_20pct' => count($big),
            'price_history_rows' => (int) $history->n,
            'price_history_rows_without_known_at' => (int) $history->unknown_known_at,
            'top_variation' => array_map(fn ($r) => [
                'product_id' => (int) $r->product_id, 'sku' => $names[$r->product_id] ?? null, 'distinct_prices' => (int) $r->n_prices,
                'min_price' => round((float) $r->min_price, 4), 'max_price' => round((float) $r->max_price, 4),
                'spread_pct' => round(((float) $r->max_price / max((float) $r->min_price, 0.0001) - 1) * 100, 1),
            ], array_slice($varying, 0, 10)),
            'message' => 'Los precios históricos se toman del precio ORIGINAL de cada línea de venta; el precio actual del producto nunca se usa para reconstruir el pasado.',
        ];
    }

    /** @return array<string, mixed> */
    private function units(int $tenantId): array
    {
        $conversions = DB::table('unit_conversions')->where('tenant_id', $tenantId)->get(['product_id', 'from_unit', 'to_unit']);
        $declared = function (?int $productId, ?string $a, ?string $b) use ($conversions): bool {
            $a = UnitNormalizer::normalize($a);
            $b = UnitNormalizer::normalize($b);
            foreach ($conversions as $c) {
                if (($c->product_id === null || (int) $c->product_id === $productId)
                    && (($c->from_unit === $a && $c->to_unit === $b) || ($c->from_unit === $b && $c->to_unit === $a))) {
                    return true;
                }
            }

            return false;
        };
        $costs = DB::select(
            'SELECT h.product_id, p.sku, h.unit AS cost_unit, p.unit AS product_unit, COUNT(*) AS rows FROM purchase_cost_history h JOIN products p ON p.tenant_id = h.tenant_id AND p.id = h.product_id '
            .'WHERE h.tenant_id = ? AND h.unit IS NOT NULL GROUP BY h.product_id, p.sku, h.unit, p.unit',
            [$tenantId],
        );
        $incompatible = [];
        foreach ($costs as $row) {
            if (UnitNormalizer::normalize($row->cost_unit) !== UnitNormalizer::normalize($row->product_unit)) {
                $incompatible[] = ['source' => 'purchase_cost_history', 'product_id' => (int) $row->product_id, 'sku' => $row->sku, 'unit' => $row->cost_unit, 'product_unit' => $row->product_unit,
                    'rows' => (int) $row->rows, 'has_declared_conversion' => $declared((int) $row->product_id, $row->cost_unit, $row->product_unit)];
            }
        }
        $converted = (int) DB::table('commercial_commitment_items')->where('tenant_id', $tenantId)->whereNotNull('source_unit')->count();
        $mixed = DB::select(
            "SELECT COALESCE(c.name, '(sin categoría)') AS category, json_agg(DISTINCT lower(p.unit)) AS units FROM products p LEFT JOIN product_categories c ON c.tenant_id = p.tenant_id AND c.id = p.category_id "
            .'WHERE p.tenant_id = ? AND p.deleted_at IS NULL GROUP BY 1 HAVING COUNT(DISTINCT lower(p.unit)) > 1 ORDER BY 1 LIMIT 20',
            [$tenantId],
        );
        $unconverted = array_filter($incompatible, fn (array $i) => ! $i['has_declared_conversion']);

        return [
            'status' => $unconverted === [] ? 'ok' : 'warning',
            'incompatible_units' => array_slice($incompatible, 0, 30),
            'incompatible_without_conversion' => count($unconverted),
            'commitment_lines_converted' => $converted,
            'categories_with_mixed_units' => array_map(fn ($r) => ['category' => $r->category, 'units' => json_decode((string) $r->units, true) ?: []], $mixed),
            'declared_conversions' => $conversions->count(),
            'message' => 'Una unidad distinta de la del producto sin conversión declarada es un error: no hay conversiones silenciosas. Las categorías con unidades mezcladas no deben sumarse como si fueran la misma unidad.',
        ];
    }
}
