<?php

declare(strict_types=1);

namespace App\Services\Decision\Generators;

use App\Models\CoreRun;
use App\Models\PurchaseBudget;
use App\Models\SupplyPlan;
use App\Models\SupplyPlanLine;
use App\Models\Tenant;
use App\Services\Decision\DecisionSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Restricción presupuestaria (V2.5 · W5-B), a partir de los faltantes del plan de abastecimiento vigente cuya causa determinista es el presupuesto
 * (`supply_plan_shortfalls.reason = budget`): qué serie producto × sucursal no se podrá abastecer con el presupuesto, cuántas unidades faltan y cuánto
 * costaría cubrirlas. No recalcula el plan. Siempre exige aprobación (sensible). El costo de cubrir el faltante usa el costo unitario de las líneas del plan
 * para esa serie; si no hay, queda sin dato (NULL), no se inventa.
 */
final class BudgetConstraintGenerator implements CandidateSource
{
    public function collect(Tenant $tenant, DecisionSettings $settings, CarbonImmutable $today): array
    {
        $planRun = CoreRun::query()->where('core', 'supply')->where('operation', 'plan')->where('status', 'completed')->where('run_type', 'production')
            ->orderByDesc('is_current')->orderByDesc('id')->first();
        $plan = $planRun === null ? null : SupplyPlan::query()->where('core_run_id', $planRun->id)->first();
        if ($plan === null || $planRun->as_of === null) {
            return ['groups' => [], 'skipped' => ['budget_constraint' => 'no_completed_supply_plan']];
        }
        $asOf = CarbonImmutable::parse($planRun->as_of->toDateString(), $settings->timezone())->startOfDay();
        if ($settings->expiresAt('budget_constraint', $asOf, 1)->lessThanOrEqualTo($today)) {
            return ['groups' => [], 'skipped' => ['budget_constraint' => 'source_expired']];
        }

        $rows = DB::table('supply_plan_shortfalls')->where('tenant_id', $tenant->getKey())->where('supply_plan_id', $plan->id)->where('reason', 'budget')
            ->groupBy('product_id', 'branch_id')->havingRaw('SUM(unmet) > 0')->orderByRaw('SUM(unmet) DESC')->orderBy('product_id')->orderBy('branch_id')->limit($settings->maxSupplyCandidates() * 2)
            ->selectRaw('product_id, branch_id, SUM(unmet) AS unmet, SUM(demand) AS demand, MIN(period_start) AS first_period, MAX(period_end) AS last_period, COUNT(*) AS periods')->get();

        $costs = [];
        foreach (SupplyPlanLine::query()->where('supply_plan_id', $plan->id)->where('line_type', 'purchase')->whereNotNull('unit_cost')->get() as $l) {
            $costs[$l->product_id.'|'.$l->branch_id][] = (float) $l->unit_cost;
        }
        $budgets = PurchaseBudget::query()->where('period_end', '>=', $asOf->toDateString())->orderBy('period_start')->get();

        $attributes = [];
        foreach ($rows as $r) {
            $key = $r->product_id.'|'.$r->branch_id;
            $unitCost = isset($costs[$key]) ? array_sum($costs[$key]) / count($costs[$key]) : null;
            $lastPeriod = CarbonImmutable::parse($r->last_period);
            $relevant = $budgets->filter(fn (PurchaseBudget $b) => ($b->branch_id === null || (int) $b->branch_id === (int) $r->branch_id)
                && $b->period_start->lessThanOrEqualTo($lastPeriod))->values();
            $attributes[] = [
                'kind' => 'budget_constraint', 'as_of' => $asOf->toDateString(), 'horizon_days' => max(1, (int) $asOf->diffInDays($lastPeriod)), 'entity_type' => 'product_branch',
                'product_id' => (int) $r->product_id, 'branch_id' => (int) $r->branch_id, 'source_core_run_id' => (int) $planRun->id,
                'source_forecast_run_id' => $plan->forecast_run_id, 'data_snapshot_id' => $planRun->data_snapshot_id,
                'model_versions' => ['supply_plan' => ['id' => $plan->id, 'objective' => $plan->objective, 'solver_status' => $plan->solver_status, 'model_versions' => $planRun->model_versions]],
                'policy' => 'budget_constraint:unmet_by_budget',
                'constraints' => [[
                    'code' => 'budget', 'unmet_units' => (float) $r->unmet, 'demand_units' => (float) $r->demand, 'periods' => (int) $r->periods,
                    'first_period' => (string) $r->first_period, 'last_period' => (string) $r->last_period,
                    'budgets' => $relevant->map(fn (PurchaseBudget $b) => ['id' => $b->id, 'amount' => $b->amount, 'period_start' => $b->period_start->toDateString(), 'period_end' => $b->period_end->toDateString()])->all(),
                ]],
                'assumptions' => [
                    'Faltante por presupuesto = unidades de demanda pronosticada que el plan vigente no cubre porque el presupuesto de compra lo impide (causa determinista del plan).',
                    'Las unidades faltantes se valoran al precio neto reciente (lectura as-of) como venta en riesgo; no es ingreso recuperado.',
                    $unitCost === null ? 'El plan no tiene líneas de compra con costo para esta serie: el costo de cubrir el faltante queda sin dato.'
                        : 'Costo de cubrir el faltante = unidades × costo unitario medio de las líneas de compra del plan para la serie.',
                ],
                'expected_units' => (float) $r->unmet, 'action_cost' => $unitCost === null ? null : round((float) $r->unmet * $unitCost, 4),
                'explanation' => [[
                    'code' => 'unmet_by_budget', 'label' => 'Demanda no cubierta por el presupuesto en el plan vigente', 'source' => 'supply_plan_shortfalls:supply_plan='.$plan->id,
                    'value' => array_filter(['unmet_units' => (float) $r->unmet, 'demand_units' => (float) $r->demand, 'unit_cost' => $unitCost, 'planner_status' => $plan->solver_status, 'currency' => $plan->currency], fn ($v) => $v !== null),
                ]],
                'next_step' => 'Revisar el presupuesto de compra del período o priorizar las series con mayor contribución; Predictily no compromete fondos.',
            ];
        }

        return ['groups' => [['kind' => 'budget_constraint', 'label' => 'budget_constraint', 'as_of' => $asOf, 'max' => $settings->maxSupplyCandidates(), 'attributes' => $attributes]], 'skipped' => []];
    }
}
