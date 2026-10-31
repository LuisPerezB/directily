<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Supply;

use App\Http\Resources\Supply\SupplyRunResource;
use App\Models\CoreRun;
use App\Models\ForecastRun;
use App\Models\SupplyPlan;
use App\Services\Supply\SupplyRunService;
use App\Support\Results\JsonMap;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Planes de reposición con restricciones (presupuesto, capacidades, lotes, mínimos, plazos). Asíncronos: crean core_run +
 * job en la cola `forecasting`. Son SUGERENCIAS: no emiten pedidos ni comprometen fondos.
 */
class PlanController extends SupplyController
{
    /** Claves de configuración que una solicitud puede sobrescribir (las demás se rechazan). */
    private const CONFIG_KEYS = [
        'holding_cost_rate_annual' => ['numeric', 'min:0', 'max:5'],
        'shortage_penalty_multiplier' => ['numeric', 'gt:1', 'max:100'],
        'safety_stock_weight' => ['numeric', 'min:0', 'lt:1'],
        'solver_time_limit_seconds' => ['integer', 'min:1', 'max:300'],
        'solver_mip_gap' => ['numeric', 'min:0', 'max:0.2'],
        'use_implicit_offers' => ['boolean'],
        'diagnose_unmet' => ['boolean'],
    ];

    public function store(Request $request, SupplyRunService $service): JsonResponse
    {
        $rules = [
            'objective' => ['sometimes', Rule::in(['min_cost', 'max_margin'])],
            'coverage_mode' => ['sometimes', Rule::in(['soft', 'hard'])],
            'forecast_run_id' => ['nullable', 'integer', TenantRule::exists('forecast_runs')],
            'horizon_days' => ['sometimes', 'integer', 'min:14', 'max:365'],
            'period_days' => ['sometimes', 'integer', Rule::in([1, 7, 14, 30])],
            'undated_receipt_policy' => ['sometimes', Rule::in(['lead_time_end', 'observed_lead_time', 'exclude'])],
            'overdue_receipt_policy' => ['sometimes', Rule::in(['lead_time_end', 'observed_lead_time', 'exclude'])],
            'priorities' => ['sometimes', 'array', 'max:5000'],
            'priorities.*' => ['numeric', 'gt:0', 'max:100'],
            'shortage_penalties' => ['sometimes', 'array', 'max:5000'],
            'shortage_penalties.*' => ['numeric', 'gt:0'],
            'config' => ['sometimes', 'array'],
        ];
        foreach (self::CONFIG_KEYS as $key => $rule) {
            $rules["config.$key"] = array_merge(['sometimes'], $rule);
        }
        $data = $request->validate($rules);
        if (isset($data['forecast_run_id'])) {
            $fr = ForecastRun::query()->findOrFail((int) $data['forecast_run_id']);
            if ($fr->status !== ForecastRun::STATUS_COMPLETED) {
                abort(422, 'El pronóstico indicado no está completado.');
            }
        }
        $unknown = array_diff(array_keys((array) $request->input('config', [])), array_keys(self::CONFIG_KEYS));
        if ($unknown !== []) {
            abort(422, 'Claves de configuración no permitidas: '.implode(', ', $unknown));
        }
        foreach (['priorities', 'shortage_penalties'] as $map) {
            foreach (array_keys((array) ($data[$map] ?? [])) as $productId) {
                if (! is_numeric($productId)) {
                    abort(422, "Las claves de {$map} deben ser identificadores de producto.");
                }
            }
        }
        $options = array_filter($data, fn ($v) => $v !== null);
        $run = $service->request(
            tenant: $this->tenant(),
            user: $request->user(),
            kind: 'plan',
            options: $options,
            triggeredBy: $request->user()?->currentAccessToken() instanceof PersonalAccessToken ? 'api' : 'user',
        );

        return (new SupplyRunResource($run))->response()->setStatusCode(202);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $runs = CoreRun::query()->where('core', 'supply')->where('operation', 'plan')->with('trainingJobs')
            ->when($request->query('status'), fn ($q, $st) => $q->where('status', (string) $st))
            ->latest('id')->paginate($this->perPage($request, 20));

        return SupplyRunResource::collection($runs);
    }

    /** Plan completo: envoltura común + líneas, demanda no atendida, restricciones, alternativas e infactibilidad. */
    public function show(Request $request, int $id): JsonResponse
    {
        $run = CoreRun::query()->where('core', 'supply')->where('operation', 'plan')->with('trainingJobs')->findOrFail($id);
        $envelope = $this->envelope($run, ['result_type' => 'optimized_plan']);
        $run_payload = (new SupplyRunResource($run))->resolve($request);
        $plan = SupplyPlan::query()->where('core_run_id', $run->id)->first();
        if ($plan === null) {
            return response()->json(['data' => ['run' => $run_payload, 'plan' => null, 'lines' => [], 'shortfalls' => []], 'envelope' => $envelope]);
        }
        $lines = $plan->lines()->with(['product:id,sku,name', 'branch:id,code,name', 'supplier:id,code,name'])
            ->when($request->boolean('actionable_now'), fn ($q) => $q->where('actionable_now', true))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', (int) $request->input('product_id')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', (int) $request->input('supplier_id')))
            ->orderBy('order_date')->orderBy('id')->limit(5000)->get();
        $shortfalls = $plan->shortfalls()->with(['product:id,sku,name', 'branch:id,code,name'])->orderBy('period_start')->orderBy('product_id')->limit(5000)->get();
        $details = (array) $plan->details;

        return response()->json([
            'data' => [
                'run' => $run_payload,
                'plan' => [
                    'id' => $plan->id, 'objective' => $plan->objective, 'coverage_mode' => $plan->coverage_mode,
                    'solver_status' => $plan->solver_status, 'mip_gap' => $plan->mip_gap, 'objective_value' => $plan->objective_value,
                    'solve_seconds' => $plan->solve_seconds, 'currency' => $plan->currency,
                    'total_purchase_cost' => $plan->total_purchase_cost, 'total_holding_cost' => $plan->total_holding_cost,
                    'total_shortage_penalty' => $plan->total_shortage_penalty, 'unmet_units_total' => $plan->unmet_units_total,
                    'demand_units_total' => $plan->demand_units_total, 'forecast_run_id' => $plan->forecast_run_id,
                    'is_optimal' => $plan->solver_status === 'optimal',
                    'note' => 'Sugerencias de reposición: Predictily no envía pedidos ni compromete fondos.',
                ],
                'lines' => $lines->map(fn ($l) => [
                    'id' => $l->id, 'line_type' => $l->line_type, 'branch_id' => $l->branch_id,
                    'branch' => $l->branch ? ['id' => $l->branch->id, 'code' => $l->branch->code, 'name' => $l->branch->name] : null,
                    'product_id' => $l->product_id,
                    'product' => $l->product ? ['id' => $l->product->id, 'sku' => $l->product->sku, 'name' => $l->product->name] : null,
                    'supplier_id' => $l->supplier_id,
                    'supplier' => $l->supplier ? ['id' => $l->supplier->id, 'code' => $l->supplier->code, 'name' => $l->supplier->name] : null,
                    'offer_id' => $l->offer_id, 'recipe_id' => $l->recipe_id, 'quantity' => $l->quantity, 'unit' => $l->unit,
                    'offer_quantity' => $l->offer_quantity, 'offer_unit' => $l->offer_unit,
                    'order_date' => $l->order_date?->toDateString(), 'expected_receipt_date' => $l->expected_receipt_date?->toDateString(),
                    'unit_cost' => $l->unit_cost, 'line_cost' => $l->line_cost, 'currency' => $l->currency,
                    'actionable_now' => (bool) $l->actionable_now, 'binding_constraints' => $l->binding_constraints,
                    'justification' => $l->justification, 'assumptions' => JsonMap::of($l->assumptions),
                ])->values(),
                'shortfalls' => $shortfalls->map(fn ($s) => [
                    'branch_id' => $s->branch_id, 'product_id' => $s->product_id,
                    'product' => $s->product ? ['id' => $s->product->id, 'sku' => $s->product->sku, 'name' => $s->product->name] : null,
                    'period_start' => $s->period_start?->toDateString(), 'period_end' => $s->period_end?->toDateString(),
                    'demand' => $s->demand, 'unmet' => $s->unmet, 'reason' => $s->reason,
                ])->values(),
                'constraints' => $details['constraints'] ?? [],
                // Mapas del motor (objeto JSON aunque estén vacíos); null = el bloque no aplica a este plan.
                'solver' => JsonMap::of($details['solver'] ?? null),
                'diagnosis' => JsonMap::of($details['diagnosis'] ?? null),
                'infeasibility' => JsonMap::of($details['infeasibility'] ?? null),
                'alternative_plan' => JsonMap::of($details['alternative_plan'] ?? null),
                'offers' => $details['offers'] ?? [],
                'comparison_coverage' => JsonMap::of($details['report']['comparison_coverage'] ?? null),
                'offer_diagnostics' => $details['report']['offer_diagnostics'] ?? [],
                'excluded_nodes' => $details['report']['excluded_nodes'] ?? [],
                'production' => JsonMap::of($details['report']['production'] ?? ['status' => 'not_configured']),
                'receipts_summary' => JsonMap::of($details['report']['receipts_summary'] ?? null),
                'balance' => $details['balance'] ?? [],
                'periods' => $details['periods'] ?? [],
            ],
            'envelope' => $envelope,
        ]);
    }
}
