<?php

declare(strict_types=1);

namespace App\Services\Radar;

use App\Models\CoreRun;
use App\Models\ForecastRun;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Analytics\RunResolver;
use App\Services\Customer\CustomerRunService;
use App\Services\Decision\AggregateDemandLoader;
use App\Services\Decision\DecisionSettings;
use App\Services\Decision\InventoryAvailabilityLoader;
use App\Services\Plans\PlanLimitService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Preparación de abastecimiento para atender las oportunidades (V2.5 · W5-A; V25-AGD-015; §10): por producto, lo disponible al corte (existencias − reservado,
 * separado de lo que está en tránsito), la demanda esperada de los compradores identificados (incluido lo ya comprometido) y la demanda agregada pronosticada,
 * la cobertura en días y si el horizonte es «atendible». Solo lee lo persistido (inventory_snapshots, customer_predictions, forecast_values); no calcula
 * planes de compra: eso es del Supply Core.
 *
 * La disponibilidad es la MISMA que usa el Decision Engine al valorar una oportunidad ({@see InventoryAvailabilityLoader}, W5-B): una sola definición, sin duplicarla.
 * Honestidad: sin fotografía de inventario vigente al corte el estado es `unknown` y el producto NO es atendible ni inatendible (se desconoce); nunca se
 * presenta un cero. Sin demanda pronosticada, la cobertura es `null` (no «infinita»).
 */
final class SupplyReadiness
{
    public function __construct(
        private readonly InventoryAvailabilityLoader $inventory,
        private readonly AggregateDemandLoader $aggregateLoader,
        private readonly RunResolver $forecastRuns,
        private readonly CustomerRunService $customerRuns,
        private readonly PlanLimitService $plans,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{product_id?: int|null, category_id?: int|null}  $filters
     * @return array{paginator: LengthAwarePaginator, meta: array<string, mixed>}
     */
    public function readiness(array $filters, int $horizon, string $asOf, int $perPage, int $pageNumber): array
    {
        $tenant = $this->context->require();
        $paginator = Product::query()
            ->when($filters['product_id'] ?? null, fn (Builder $q, $v) => $q->whereKey($v))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $v) => $q->where('category_id', $v))
            ->where('is_active', true)->orderBy('name')->orderBy('id')
            ->paginate($perPage, ['id', 'sku', 'name', 'unit', 'category_id'], 'page', $pageNumber);
        $products = collect($paginator->items());
        $ids = $products->pluck('id')->map(fn ($id) => (int) $id)->all();

        $stock = $this->stock($tenant, $ids, $asOf);
        $forecastRun = $this->forecastRuns->current();
        $aggregate = $this->aggregateDemand($forecastRun, $ids, $horizon);
        $customerRun = $this->plans->allowsCustomer($tenant) ? $this->customerRuns->latestPrediction() : null;
        $buyers = $this->buyersDemand($customerRun, $ids, $horizon);

        $items = $products->map(function (Product $p) use ($stock, $aggregate, $buyers, $horizon, $asOf) {
            $id = (int) $p->id;
            $inv = $stock[$id];
            $agg = $aggregate[$id] ?? null;
            $buy = $buyers[$id] ?? null;
            // Lo que se compara con lo disponible es la MAYOR de las dos vistas de demanda (compradores identificados y pronóstico agregado): ninguna incluye a la otra, y
            // decir «suficiente» frente a solo los compradores identificados subestimaría la demanda del producto.
            $buyUnits = $buy['total_view_quantity'] ?? null;
            $aggUnits = $agg['units'] ?? null;
            $required = $buyUnits === null && $aggUnits === null ? null : max((float) ($buyUnits ?? 0.0), (float) ($aggUnits ?? 0.0));
            $basis = match (true) {
                $buyUnits !== null && $aggUnits !== null => 'max_of_buyers_and_aggregate',
                $buyUnits !== null => 'identified_buyers_only',
                $aggUnits !== null => 'aggregate_forecast',
                default => null,
            };
            $covers = ($inv['status'] ?? 'unknown') === 'known' && $required !== null ? (float) $inv['available'] + 1e-9 >= $required : null;
            $daily = $agg !== null && $agg['units'] > 0 ? $agg['units'] / $horizon : null;
            $coverage = ($inv['status'] ?? 'unknown') === 'known' && $daily !== null ? round((float) $inv['available'] / $daily, 1) : null;

            return [
                'product' => ['id' => $id, 'sku' => $p->sku, 'name' => $p->name, 'unit' => $p->unit, 'category_id' => $p->category_id !== null ? (int) $p->category_id : null],
                'horizon_days' => $horizon, 'as_of' => $asOf,
                'inventory' => $inv,
                'buyers_demand' => $buy,
                'aggregate_demand' => $agg === null ? null : $agg + ['daily_average' => $daily === null ? null : round($daily, 4)],
                'coverage_days' => $coverage,
                'serviceable' => [
                    'status' => $covers === null ? 'unknown' : ($covers ? 'sufficient' : 'insufficient'),
                    'value' => $covers,
                    'required_units' => $required === null ? null : round((float) $required, 4),
                    'required_basis' => $basis,
                    'required_basis_note' => match ($basis) {
                        'identified_buyers_only' => 'Solo hay demanda de compradores identificados (sin pronóstico agregado del producto): «suficiente» significa que cubre a esos compradores, no necesariamente toda la demanda.',
                        'aggregate_forecast' => 'Solo hay pronóstico agregado (sin compradores identificados en el horizonte).',
                        'max_of_buyers_and_aggregate' => 'La mayor entre la demanda de compradores identificados y el pronóstico agregado.',
                        default => null,
                    },
                    'available' => $inv['available'] ?? null,
                    'reason' => match (true) {
                        ($inv['status'] ?? 'unknown') !== 'known' => 'Sin fotografía de inventario vigente al corte: se desconoce, no es cero.',
                        $required === null => 'Sin demanda esperada para el horizonte: no hay con qué comparar la disponibilidad.',
                        default => null,
                    },
                ],
            ];
        })->values()->all();

        $paginator->setCollection(collect($items));

        return ['paginator' => $paginator, 'meta' => [
            'as_of' => $asOf, 'horizon_days' => $horizon,
            'forecast_run' => $forecastRun === null ? null : ['id' => (int) $forecastRun->id, 'version' => (int) $forecastRun->version, 'cutoff_date' => $forecastRun->cutoff_date?->toDateString()],
            'customer_run' => $customerRun === null ? null : ['id' => (int) $customerRun->id, 'version' => (int) $customerRun->version, 'as_of' => $customerRun->as_of?->toDateString()],
            'definitions' => [
                'available' => 'Existencias físicas − reservado por sucursal y producto (fotografía más reciente ≤ corte y no más antigua que inventory_max_age_days de la empresa), sumadas; una sucursal negativa aporta 0. No incluye lo que está en tránsito. Sin fotografía vigente: unknown.',
                'in_transit' => 'Compras en tránsito según la fotografía de inventario; se informa aparte y no cuenta como disponible.',
                'buyers_demand' => 'Σ de max(esperanza, comprometido) de los compradores modelados (Customer Core); el comprometido ya viene de pedidos confirmados y no se suma dos veces.',
                'aggregate_demand' => 'Σ del pronóstico diario agregado del producto (todas las sucursales o la indicada) en los primeros H días de la ejecución vigente.',
                'coverage_days' => 'Disponible ÷ demanda diaria promedio pronosticada; null si se desconoce el inventario o no hay demanda pronosticada.',
                'serviceable' => 'sufficient si lo disponible cubre la MAYOR entre la demanda de compradores identificados y el pronóstico agregado del horizonte (required_basis lo dice; con solo compradores identificados, identified_buyers_only); insufficient si no; unknown si falta inventario o demanda. No es una orden de compra.',
            ],
        ]];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function stock(Tenant $tenant, array $ids, string $asOf): array
    {
        $this->inventory->flush();   // la lectura de una petición no reutiliza una caché anterior del proceso
        $date = CarbonImmutable::parse($asOf)->startOfDay();
        $this->inventory->prime($tenant, DecisionSettings::forTenant($tenant), $date, $ids);
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->inventory->forProduct($tenant, DecisionSettings::forTenant($tenant), $date, $id);
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function aggregateDemand(?ForecastRun $run, array $ids, int $horizon): array
    {
        $out = [];
        foreach ($this->aggregateLoader->units($run, $ids, $horizon) as $productId => $units) {
            $out[$productId] = ['forecast_run_id' => (int) $run->id, 'units' => $units];
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function buyersDemand(?CoreRun $run, array $ids, int $horizon): array
    {
        if ($run === null || $ids === [] || ! in_array($horizon, array_map('intval', (array) $run->horizons), true)) {
            return [];
        }
        $rows = DB::table('customer_predictions')
            ->where('tenant_id', (int) $run->tenant_id)->where('core_run_id', $run->id)->where('horizon_days', $horizon)->whereIn('product_id', $ids)
            ->selectRaw('product_id, COUNT(*) AS pairs, SUM(expected_quantity) AS expected_quantity, SUM(committed_quantity) AS committed_quantity, '
                .'SUM(total_view_quantity) AS total_view_quantity, COUNT(expected_units) AS s05_pairs, SUM(expected_units) AS expected_units')
            ->groupBy('product_id')->get();
        $out = [];
        foreach ($rows as $r) {
            $s05 = (int) $r->s05_pairs;
            $out[(int) $r->product_id] = [
                'core_run_id' => (int) $run->id, 'pairs' => (int) $r->pairs, 'expected_quantity' => round((float) $r->expected_quantity, 4),
                'committed_quantity' => round((float) $r->committed_quantity, 4), 'total_view_quantity' => round((float) $r->total_view_quantity, 4),
                'expected_units' => $s05 > 0 ? round((float) $r->expected_units, 4) : null, 'pairs_with_s05' => $s05,
            ];
        }

        return $out;
    }
}
