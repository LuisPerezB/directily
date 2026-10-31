<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Supply;

use App\Http\Resources\Supply\SupplyRunResource;
use App\Models\CoreRun;
use App\Models\ScenarioEvaluation;
use App\Models\TrainingJob;
use App\Services\Forecasting\Exceptions\EngineException;
use App\Services\Forecasting\Exceptions\InsufficientDataException;
use App\Services\Forecasting\Support\CoreServices;
use App\Services\Forecasting\TrainingJobRecorder;
use App\Services\Supply\SupplyEngineClient;
use App\Services\Supply\SupplyRunRecorder;
use App\Services\Supply\SupplyRunService;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Evaluador de escenarios (E2-15): compara estrategias sobre los mismos cortes, unidades y horizontes, con cada entrada
 * clasificada observada / pronosticada / supuesta y coherencia demanda–oferta–ventas atendibles–inventario–margen. Es una
 * simulación condicionada (no causal). El simulador de supuestos de la etapa 1 (`/scenarios/simulate`) se conserva.
 */
class ScenarioEvaluationController extends SupplyController
{
    public function evaluate(Request $request, SupplyRunService $service, SupplyEngineClient $engine, TrainingJobRecorder $jobs, SupplyRunRecorder $recorder): JsonResponse
    {
        CoreServices::assertFeature($this->tenant(), 'scenarios');
        $pct = fn (string $min, string $max) => ['nullable', 'numeric', "min:$min", "max:$max"];
        $series = fn (string $key, string $min, string $max) => [
            $key => ['nullable'],
            "$key.*" => ['numeric', "min:$min", "max:$max"],
        ];
        $rules = [
            'name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'horizon_days' => ['sometimes', 'integer', 'min:7', 'max:180'],
            'period_days' => ['sometimes', 'integer', Rule::in([1, 7, 14, 30])],
            'forecast_run_id' => ['nullable', 'integer', TenantRule::exists('forecast_runs')],
            'cost_forecast_run_id' => ['nullable', 'integer', TenantRule::exists('core_runs')],
            'filters' => ['sometimes', 'array'],
            'filters.branch_id' => ['nullable', 'integer', TenantRule::exists('branches')],
            'filters.category_id' => ['nullable', 'integer', TenantRule::exists('product_categories')],
            'filters.product_id' => ['nullable', 'integer', TenantRule::exists('products')],
            'undated_receipt_policy' => ['sometimes', Rule::in(['lead_time_end', 'observed_lead_time', 'exclude'])],
            'strategies' => ['required', 'array', 'min:1', 'max:8'],
            'strategies.*.key' => ['required', 'string', 'alpha_dash', 'max:40', 'distinct'],
            'strategies.*.name' => ['nullable', 'string', 'max:150'],
            'strategies.*.use_cost_forecast' => ['sometimes', 'boolean'],
            'strategies.*.plan_run_id' => ['nullable', 'integer', TenantRule::exists('core_runs')],
            'strategies.*.supply_fill_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'strategies.*.lead_time_delta_days' => ['sometimes', 'integer', 'min:-60', 'max:180'],
            'strategies.*.extra_supply' => ['sometimes', 'array', 'max:500'],
            'strategies.*.extra_supply.*.branch_id' => ['required', 'integer', TenantRule::exists('branches')],
            'strategies.*.extra_supply.*.product_id' => ['required', 'integer', TenantRule::exists('products')],
            'strategies.*.extra_supply.*.period' => ['required', 'integer', 'min:0', 'max:400'],
            'strategies.*.extra_supply.*.units' => ['required', 'numeric', 'min:0'],
        ];
        // Trayectorias: escalar o lista por período.
        foreach (['price_change_pct' => ['-0.9', '5'], 'discount_pct' => ['0', '0.95'], 'cost_change_pct' => ['-0.9', '5'], 'demand_change_pct' => ['-0.95', '5']] as $k => [$min, $max]) {
            $rules["strategies.*.$k"] = ['sometimes', 'nullable'];
            $rules["strategies.*.$k.*"] = ['numeric', "min:$min", "max:$max"];
        }
        $data = $request->validate($rules);
        foreach ($data['strategies'] as $s) {
            foreach (['price_change_pct', 'discount_pct', 'cost_change_pct', 'demand_change_pct'] as $k) {
                if (isset($s[$k]) && ! is_array($s[$k]) && ! is_numeric($s[$k])) {
                    abort(422, "strategies.*.$k debe ser un número o una lista de números por período.");
                }
            }
            if (isset($s['plan_run_id'])) {
                $plan = CoreRun::query()->find((int) $s['plan_run_id']);
                if ($plan === null || $plan->core !== 'supply' || $plan->operation !== 'plan' || $plan->status !== 'completed') {
                    abort(422, 'plan_run_id debe ser un plan de reposición completado de esta empresa.');
                }
            }
        }
        $run = $service->request($this->tenant(), $request->user(), 'scenario', array_filter($data, fn ($v) => $v !== null), 'user', dispatch: false);
        $job = TrainingJob::query()->where('core_run_id', $run->id)->firstOrFail();
        $jobs->markAttempt($job);
        try {
            $result = $engine->execute($run->id, (int) $this->tenant()->id, $job->id);
            $jobs->markSucceeded($job, $result);
        } catch (InsufficientDataException $e) {
            $jobs->markFailed($job, $e->getMessage());
            $recorder->markFailed($run, $e->getMessage());
            abort(422, $e->getMessage());
        } catch (EngineException $e) {
            $jobs->markFailed($job, $e->getMessage());
            $recorder->markFailed($run, $e->getMessage());
            abort(503, 'El motor no pudo evaluar los escenarios: '.$e->getMessage());
        }

        return $this->payload(CoreRun::query()->findOrFail($run->id), 201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return SupplyRunResource::collection(CoreRun::query()->where('core', 'scenario')->with('trainingJobs')->latest('id')->paginate($this->perPage($request, 20)));
    }

    public function show(int $id): JsonResponse
    {
        return $this->payload(CoreRun::query()->where('core', 'scenario')->findOrFail($id));
    }

    private function payload(CoreRun $run, int $status = 200): JsonResponse
    {
        $eval = ScenarioEvaluation::query()->where('core_run_id', $run->id)->first();

        return response()->json([
            'data' => ['run_id' => $run->id, 'status' => $run->status, 'name' => $eval?->name, 'horizon_days' => $eval?->horizon_days,
                'request' => $eval?->request, 'results' => $eval?->results],
            'envelope' => $this->envelope($run, ['result_type' => 'conditional_simulation']),
        ], $status);
    }
}
