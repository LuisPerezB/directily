<?php

declare(strict_types=1);

namespace App\Services\Decision\Generators;

use App\Models\CoreRun;
use App\Models\Recommendation;
use App\Models\SupplyPlan;
use App\Models\SupplyPlanLine;
use App\Models\SupplyPlanShortfall;
use App\Models\Tenant;
use App\Services\Analytics\RunResolver;
use App\Services\Decision\DecisionSettings;
use Carbon\CarbonImmutable;

/**
 * Reposición a partir de lo YA persistido (V2.5 · W5-B), sin recalcular pronósticos ni planes:
 *
 *  - Base: `recommendations` (etapa 1: política de reposición por serie) de la corrida de pronóstico vigente, tipos `reorder` y `stockout_risk`, abiertas.
 *  - Enriquecimiento: si el plan de abastecimiento vigente se construyó sobre ESA corrida de pronóstico (`supply_plans.forecast_run_id`), sus líneas de compra
 *    para la misma serie fijan cantidad, fecha, proveedor y costo (el plan respeta MOQ, presupuesto y capacidad) y sus restricciones activas y faltantes por
 *    presupuesto pasan a `constraints` (una compra con faltante por presupuesto es «sobre presupuesto» y exige aprobación). Un plan de otra corrida no se mezcla.
 *  - Valor: unidades en riesgo = max(demanda media en plazo + revisión − disponible, 0) (cifras persistidas de la recomendación; usa la media, no la
 *    varianza) × precio neto reciente as-of; la contribución solo con costo confiable (lo decide el DecisionEngine). Es la venta que se pierde si NO se
 *    repone: no es ingreso recuperado. El desembolso de la compra se informa en `action_cost` como capital comprometido (no se resta del score).
 */
final class ReplenishmentGenerator implements CandidateSource
{
    public function collect(Tenant $tenant, DecisionSettings $settings, CarbonImmutable $today): array
    {
        $run = app(RunResolver::class)->current();
        if ($run === null || $run->status !== 'completed' || $run->cutoff_date === null) {
            return ['groups' => [], 'skipped' => ['replenishment' => 'no_completed_forecast_run']];
        }
        $asOf = CarbonImmutable::parse($run->cutoff_date->toDateString(), $settings->timezone())->startOfDay();
        $expires = $settings->expiresAt('replenishment', $asOf, 1);
        if ($expires->lessThanOrEqualTo($today)) {
            return ['groups' => [], 'skipped' => ['replenishment' => 'source_expired']];
        }

        $skipped = [];
        $planRun = CoreRun::query()->where('core', 'supply')->where('operation', 'plan')->where('status', 'completed')->where('run_type', 'production')
            ->orderByDesc('is_current')->orderByDesc('id')->first();
        $plan = $planRun === null ? null : SupplyPlan::query()->where('core_run_id', $planRun->id)->first();
        if ($plan !== null && (int) $plan->forecast_run_id !== (int) $run->id) {
            $skipped['supply_plan'] = 'plan_not_built_on_current_forecast_run';
            $plan = null;
        }
        $lines = [];
        $budget = [];
        if ($plan !== null) {
            foreach (SupplyPlanLine::query()->where('supply_plan_id', $plan->id)->where('line_type', 'purchase')->where('order_date', '<=', $expires->toDateString())->orderBy('order_date')->orderBy('id')->get() as $l) {
                $lines[$l->product_id.'|'.$l->branch_id][] = $l;
            }
            foreach (SupplyPlanShortfall::query()->where('supply_plan_id', $plan->id)->where('reason', 'budget')->get() as $s) {
                $budget[$s->product_id.'|'.$s->branch_id][] = $s;
            }
        }

        $attributes = [];
        $recs = Recommendation::query()->where('forecast_run_id', $run->id)->where('status', 'open')->whereIn('type', ['reorder', 'stockout_risk'])
            ->whereNotNull('available')->where('suggested_qty', '>', 0)
            // Acotada: las de mayor probabilidad de quiebre primero; el tope por grupo y el score deciden el resto.
            ->orderByRaw('stockout_probability DESC NULLS LAST')->orderBy('id')->limit($settings->maxSupplyCandidates() * 2)->get();
        foreach ($recs as $rec) {
            $key = $rec->product_id.'|'.$rec->branch_id;
            $atRisk = max((float) $rec->demand_lead_time - (float) $rec->available, 0.0);
            $planLines = $lines[$key] ?? [];
            $planQty = array_sum(array_map(fn (SupplyPlanLine $l) => (float) $l->quantity, $planLines));
            $planCost = $planLines === [] || in_array(null, array_map(fn (SupplyPlanLine $l) => $l->line_cost, $planLines), true) ? null : array_sum(array_map(fn (SupplyPlanLine $l) => (float) $l->line_cost, $planLines));
            $constraints = [];
            foreach ($planLines as $l) {
                foreach ((array) $l->binding_constraints as $c) {
                    $constraints[] = is_array($c) ? $c : ['code' => (string) $c];
                }
            }
            $unmetBudget = array_sum(array_map(fn (SupplyPlanShortfall $s) => (float) $s->unmet, $budget[$key] ?? []));
            if ($unmetBudget > 0) {
                $constraints[] = ['code' => 'over_budget', 'unmet_units_by_budget' => $unmetBudget, 'note' => 'El plan no pudo cubrir toda la compra con el presupuesto vigente: la reposición exige aprobación.'];
            }
            $first = $planLines[0] ?? null;
            $qty = $planLines !== [] ? $planQty : (float) $rec->suggested_qty;
            $cost = $planLines !== [] ? $planCost : ($rec->estimated_investment === null ? null : (float) $rec->estimated_investment);
            $attributes[] = [
                'kind' => 'replenishment', 'as_of' => $asOf->toDateString(), 'horizon_days' => (int) $rec->lead_time_days + (int) $rec->review_period_days,
                'entity_type' => 'product_branch', 'product_id' => (int) $rec->product_id, 'branch_id' => (int) $rec->branch_id,
                'source_forecast_run_id' => (int) $run->id, 'source_core_run_id' => $plan?->core_run_id, 'data_snapshot_id' => $planRun?->data_snapshot_id,
                'model_versions' => array_filter([
                    'forecast_run' => ['id' => $run->id, 'version' => $run->version, 'strategy' => $run->strategy, 'selection' => $run->selection],
                    'supply_plan' => $plan === null ? null : ['id' => $plan->id, 'objective' => $plan->objective, 'solver_status' => $plan->solver_status, 'model_versions' => $planRun?->model_versions],
                ], fn ($v) => $v !== null),
                'probability' => $rec->stockout_probability === null ? null : (float) $rec->stockout_probability,
                'interval' => ['demand_lead_time' => ['mean' => (float) $rec->demand_lead_time, 'std' => $rec->demand_lead_time_std === null ? null : (float) $rec->demand_lead_time_std]],
                'assumptions' => [
                    'Unidades en riesgo = max(demanda media en plazo + revisión − disponible, 0), con cifras persistidas de la recomendación de reposición (usa la media, no la varianza).',
                    'Las unidades en riesgo se valoran al precio neto reciente (lectura as-of) como venta que se pierde si no se repone; no es ingreso recuperado ni ventas causadas.',
                    'El desembolso de la compra es capital comprometido: se informa en action_cost y no se resta del score.',
                ],
                'constraints' => $constraints,
                'availability' => [
                    'status' => 'known', 'source' => 'recommendations', 'snapshot_date' => $rec->inventory_snapshot_date?->toDateString(),
                    'on_hand' => (float) $rec->on_hand, 'reserved' => (float) $rec->reserved, 'available' => max((float) $rec->available, 0.0),
                    'in_transit' => (float) $rec->in_transit, 'in_transit_counted_as_available' => false, 'branches_counted' => 1,
                ],
                'expected_units' => $atRisk, 'action_cost' => $cost,
                'explanation' => [
                    $this->factor('stockout_risk', 'Riesgo de quiebre en plazo + revisión', $rec->risk_level, $rec, [
                        'type' => $rec->type, 'stockout_probability' => $rec->stockout_probability === null ? null : (float) $rec->stockout_probability,
                        'days_of_cover' => $rec->days_of_cover === null ? null : (float) $rec->days_of_cover, 'stockout_date_estimate' => $rec->stockout_date_estimate?->toDateString(),
                    ]),
                    $this->factor('reorder_policy', 'Política de reposición (nivel de servicio, plazo y stock de seguridad)', null, $rec, [
                        'service_level' => (float) $rec->service_level, 'lead_time_days' => $rec->lead_time_days, 'lead_time_source' => $rec->lead_time_source,
                        'review_period_days' => $rec->review_period_days, 'safety_stock' => (float) $rec->safety_stock, 'reorder_point' => (float) $rec->reorder_point,
                        'demand_lead_time' => (float) $rec->demand_lead_time, 'available' => (float) $rec->available, 'units_at_risk' => $atRisk,
                    ]),
                    $this->factor('suggested_quantity', $planLines !== [] ? 'Cantidad del plan de abastecimiento vigente' : 'Cantidad sugerida por la política de reposición', $qty, $rec, array_filter([
                        'source' => $planLines !== [] ? 'supply_plan_lines' : 'recommendations', 'policy_suggested_qty' => (float) $rec->suggested_qty,
                        'plan_id' => $plan?->id, 'order_date' => $first?->order_date?->toDateString(), 'expected_receipt_date' => $first?->expected_receipt_date?->toDateString(),
                        'supplier_id' => $first?->supplier_id, 'unit_cost' => $first?->unit_cost, 'unit' => $first?->unit,
                    ], fn ($v) => $v !== null)),
                ],
                'next_step' => $planLines !== []
                    ? sprintf('Revisar y, si se aprueba, emitir en el ERP el pedido de %s unidades (primera orden sugerida el %s). Predictily no emite pedidos.', rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'), $first->order_date->toDateString())
                    : null,
            ];
        }

        return ['groups' => [['kind' => 'replenishment', 'label' => 'replenishment', 'as_of' => $asOf, 'max' => $settings->maxSupplyCandidates(), 'attributes' => $attributes]], 'skipped' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function factor(string $code, string $label, mixed $value, Recommendation $rec, array $detail): array
    {
        return array_filter(['code' => $code, 'label' => $label, 'value' => $value, 'source' => 'recommendations:id='.$rec->id, 'detail' => $detail === [] ? null : $detail], fn ($v) => $v !== null);
    }
}
