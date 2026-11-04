<?php

declare(strict_types=1);

namespace App\Services\Decision\Generators;

use App\Models\Recommendation;
use App\Models\Tenant;
use App\Services\Analytics\RunResolver;
use App\Services\Customer\CustomerRunService;
use App\Services\Decision\AggregateDemandLoader;
use App\Services\Decision\DecisionSettings;
use App\Services\Decision\InventoryAvailabilityLoader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Incompatibilidad inventario-demanda (V2.5 · W5-B), con lo YA persistido:
 *
 *  - `shortage_vs_customer_demand`: la demanda esperada de los compradores del producto en el horizonte (suma de unidades esperadas del Customer Core)
 *    supera lo disponible (existencias − reservado, sin contar el tránsito) al corte. Faltante = demanda − disponible; se valora al precio medio
 *    ponderado de las predicciones y, con costo confiable, a contribución. Solo compradores identificados: el pronóstico agregado no se suma (son vistas distintas).
 *  - `excess_without_demand`: la política de reposición marcó `overstock` y la demanda es menor que la fracción configurada de lo disponible, donde la demanda es la
 *    MAYOR entre los compradores identificados y el pronóstico agregado de Demand Core (un producto sin compradores identificados puede tener demanda agregada: no se
 *    declara «sin demanda» por ausencia de datos). Si no se conoce NINGUNA de las dos, no hay recomendación (`demand_unknown`): la ausencia de datos no es demanda cero.
 *    SIN valor económico estimado (requeriría una tasa de mantener inventario por empresa: DISEÑADO) → score NULL, prioridad 5 (la más baja).
 * Disponibilidad desconocida (sin foto vigente) no genera recomendación: nunca se supone cero. Acotado por `max_supply_candidates`.
 */
final class InventoryMismatchGenerator implements CandidateSource
{
    public function __construct(private readonly InventoryAvailabilityLoader $inventory, private readonly AggregateDemandLoader $aggregate) {}

    public function collect(Tenant $tenant, DecisionSettings $settings, CarbonImmutable $today): array
    {
        $run = app(CustomerRunService::class)->latestPrediction();
        if ($run === null || $run->as_of === null) {
            return ['groups' => [], 'skipped' => ['inventory_mismatch' => 'no_customer_prediction_run']];
        }
        $asOf = CarbonImmutable::parse($run->as_of->toDateString(), $settings->timezone())->startOfDay();
        $runHorizons = array_map('intval', (array) $run->horizons);
        $h = collect($settings->horizons())->first(fn (int $x) => in_array($x, $runHorizons, true));
        if ($h === null) {
            return ['groups' => [], 'skipped' => ['inventory_mismatch' => 'horizon_not_in_run']];
        }
        if ($settings->expiresAt('inventory_mismatch', $asOf, $h)->lessThanOrEqualTo($today)) {
            return ['groups' => [], 'skipped' => ['inventory_mismatch' => 'source_expired']];
        }
        $max = $settings->maxSupplyCandidates();

        $forecast = app(RunResolver::class)->current();
        $overstock = $forecast === null ? collect() : Recommendation::query()->where('forecast_run_id', $forecast->id)->where('status', 'open')->where('type', 'overstock')
            ->whereNotNull('available')->orderByRaw('available DESC')->orderBy('id')->limit($max * 2)->get()->groupBy('product_id');

        // Compradores identificados: los productos de mayor demanda esperada (acotado) y, aparte, los productos en exceso (que pueden tener muy poca).
        $demand = $this->buyersDemand($tenant, $run->id, $h, null, $max * 10)
            ->union($this->buyersDemand($tenant, $run->id, $h, $overstock->keys()->map(fn ($id) => (int) $id)->all(), null));
        $aggregate = $this->aggregate->units($forecast, $overstock->keys()->map(fn ($id) => (int) $id)->all(), $h);

        $productIds = $demand->keys()->merge($overstock->keys())->map(fn ($id) => (int) $id)->unique()->values()->all();
        $this->inventory->prime($tenant, $settings, $asOf, $productIds);

        $shortages = [];
        $excess = [];
        $skipped = [];
        foreach ($demand as $productId => $d) {
            $availability = $this->inventory->forProduct($tenant, $settings, $asOf, (int) $productId);
            if ($availability['status'] !== 'known') {
                $skipped['availability_unknown'] = 'availability_unknown';

                continue;
            }
            if ((float) $d->units > (float) $availability['available'] && (float) $d->units > 0) {
                $gap = (float) $d->units - (float) $availability['available'];
                $shortages[] = [
                    'kind' => 'inventory_mismatch', 'as_of' => $asOf->toDateString(), 'horizon_days' => $h, 'entity_type' => 'product', 'product_id' => (int) $productId,
                    'source_core_run_id' => (int) $run->id, 'data_snapshot_id' => $run->data_snapshot_id, 'policy' => 'inventory_mismatch:shortage_vs_customer_demand',
                    'availability' => $availability, 'expected_units' => $gap, 'unit_price' => $d->avg_price === null ? null : (float) $d->avg_price,
                    'constraints' => [['code' => 'demand_exceeds_availability', 'expected_demand_units' => (float) $d->units, 'available_units' => (float) $availability['available'], 'shortfall_units' => $gap]],
                    'assumptions' => [
                        'Demanda esperada = suma de las unidades esperadas de los compradores modelados en el horizonte (Customer Core); no se suma la demanda agregada ni lo comprometido.',
                        'Disponible = existencias − reservado al corte, sin contar compras en tránsito.',
                        'El faltante se valora al precio medio ponderado de las predicciones; no es ingreso recuperado.',
                    ],
                    'explanation' => [[
                        'code' => 'demand_vs_availability', 'label' => 'Demanda esperada de compradores frente a lo disponible', 'source' => 'customer_predictions:core_run='.$run->id.'; inventory_snapshots',
                        'value' => ['expected_demand_units' => (float) $d->units, 'available_units' => (float) $availability['available'], 'shortfall_units' => $gap,
                            'customers' => (int) $d->customers, 'committed_units' => (float) $d->committed, 'in_transit_units' => (float) $availability['in_transit']],
                    ]],
                    'next_step' => 'Revisar si la reposición en curso llega a tiempo o si hay que priorizar a los clientes con mayor contribución; Predictily no emite pedidos.',
                ];
            }
        }
        foreach ($overstock as $productId => $recs) {
            $availability = $this->inventory->forProduct($tenant, $settings, $asOf, (int) $productId);
            $branchAvailable = (float) $recs->sum(fn (Recommendation $r) => max((float) $r->available, 0.0));
            $buyers = $demand->get($productId);
            $aggregateUnits = $aggregate[(int) $productId] ?? null;
            if ($buyers === null && $aggregateUnits === null) {
                // Ni compradores identificados ni pronóstico agregado: la demanda se DESCONOCE. Sin datos no hay «exceso sin demanda».
                $skipped['demand_unknown'] = 'demand_unknown';

                continue;
            }
            $buyerUnits = (float) ($buyers->units ?? 0.0);
            $units = max($buyerUnits, (float) ($aggregateUnits ?? 0.0));
            if ($branchAvailable > 0 && $units < $settings->excessDemandRatio() * $branchAvailable) {
                $excess[] = [
                    'kind' => 'inventory_mismatch', 'as_of' => $asOf->toDateString(), 'horizon_days' => $h, 'entity_type' => 'product', 'product_id' => (int) $productId,
                    'source_core_run_id' => (int) $run->id, 'source_forecast_run_id' => (int) $forecast->id, 'data_snapshot_id' => $run->data_snapshot_id,
                    'policy' => 'inventory_mismatch:excess_without_demand', 'availability' => $availability['status'] === 'known' ? $availability : [
                        'status' => 'known', 'source' => 'recommendations', 'available' => $branchAvailable, 'branches_counted' => $recs->count(), 'in_transit_counted_as_available' => false,
                    ],
                    'constraints' => [['code' => 'overstock_without_demand', 'expected_demand_units' => $units, 'available_units' => $branchAvailable, 'ratio_threshold' => $settings->excessDemandRatio()]],
                    'assumptions' => [
                        'La política de reposición clasificó el producto como exceso (overstock) en '.$recs->count().' sucursal(es).',
                        'Demanda = la mayor entre los compradores identificados (Customer Core) y el pronóstico agregado (Demand Core) en el horizonte; son vistas distintas y no se suman.',
                        'Sin valor económico estimado: requiere una tasa de mantener inventario por empresa (DISEÑADO). El score queda en blanco, no en cero.',
                    ],
                    'explanation' => [[
                        'code' => 'excess_vs_demand', 'label' => 'Exceso de inventario frente a la demanda esperada', 'source' => 'recommendations:overstock; customer_predictions:core_run='.$run->id.'; forecast_values',
                        'value' => ['available_units_in_overstock_branches' => $branchAvailable, 'expected_demand_units' => $units, 'buyers_demand_units' => $buyers === null ? null : $buyerUnits,
                            'aggregate_demand_units' => $aggregateUnits, 'ratio_threshold' => $settings->excessDemandRatio(),
                            'days_of_cover' => $recs->map(fn (Recommendation $r) => $r->days_of_cover)->filter()->values()->all()],
                    ]],
                    'next_step' => 'Revisar redistribución, promoción o pausa de compras del producto; la decisión y su ejecución son del equipo.',
                ];
            }
        }
        // Las de mayor faltante primero y acotadas; el exceso (sin score) por mayor disponible.
        usort($shortages, fn (array $a, array $b) => $b['expected_units'] <=> $a['expected_units'] ?: $a['product_id'] <=> $b['product_id']);

        return ['groups' => [
            ['kind' => 'inventory_mismatch', 'label' => 'inventory_mismatch:shortage', 'as_of' => $asOf, 'max' => $max, 'attributes' => $shortages],
            ['kind' => 'inventory_mismatch', 'label' => 'inventory_mismatch:excess', 'as_of' => $asOf, 'max' => $max, 'attributes' => $excess],
        ], 'skipped' => $skipped];
    }

    /**
     * Demanda esperada de compradores identificados por producto (Customer Core): unidades, comprometido, nº de compradores y precio medio ponderado.
     *
     * @param  list<int>|null  $productIds  null = todos (con tope `$limit`, los de mayor demanda primero)
     * @return Collection<int, object>
     */
    private function buyersDemand(Tenant $tenant, int $runId, int $horizon, ?array $productIds, ?int $limit): Collection
    {
        if ($productIds === []) {
            return collect();
        }
        $query = DB::table('customer_predictions as p')
            ->join('customers as c', fn ($j) => $j->on('c.id', '=', 'p.customer_id')->on('c.tenant_id', '=', 'p.tenant_id'))
            ->where('p.tenant_id', $tenant->getKey())->where('p.core_run_id', $runId)->where('p.horizon_days', $horizon)
            ->whereNotNull('p.purchase_probability')->where('c.is_generic', false)->whereNull('c.deleted_at')
            ->when($productIds !== null, fn ($q) => $q->whereIn('p.product_id', $productIds))
            ->groupBy('p.product_id')
            ->selectRaw('p.product_id, SUM(COALESCE(p.expected_units, p.expected_quantity)) AS units, SUM(p.committed_quantity) AS committed, COUNT(DISTINCT p.customer_id) AS customers,
                SUM(COALESCE(p.expected_units, p.expected_quantity) * p.assumed_unit_price) / NULLIF(SUM(CASE WHEN p.assumed_unit_price IS NOT NULL THEN COALESCE(p.expected_units, p.expected_quantity) END), 0) AS avg_price');
        if ($limit !== null) {
            $query->orderByRaw('SUM(COALESCE(p.expected_units, p.expected_quantity)) DESC')->orderBy('p.product_id')->limit($limit);
        }

        return $query->get()->keyBy('product_id');
    }
}
