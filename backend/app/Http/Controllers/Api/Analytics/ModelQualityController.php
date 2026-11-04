<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Requests\Analytics\ModelEvaluationsQueryRequest;
use App\Http\Requests\Analytics\PromotionDecisionsRequest;
use App\Http\Requests\Analytics\ShadowEvaluationsRequest;
use App\Http\Resources\Analytics\ModelQualityEvaluationResource;
use App\Http\Resources\Analytics\PromotionDecisionResource;
use App\Models\CoreRun;
use App\Models\ForecastRun;
use App\Models\ModelEvaluation;
use App\Models\ModelPromotionDecision;
use App\Services\Analytics\ModelQuality\EvaluationPresenter;
use App\Services\Analytics\ModelQuality\ShadowSummary;
use App\Services\Analytics\RunResolver;
use App\Services\Customer\CustomerRunService;
use App\Services\Forecasting\Support\CoreServices;
use App\Services\Plans\PlanLimitService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Calidad de los modelos (V2.5 · W3-D; V25-HOR-007, V25-AGD-011..014 y -016, V25-DEM-034). Solo lectura (`forecast.view`), aislada por empresa
 * (BelongsToTenant: un id de otra empresa responde 404), paginada y acotada por el plan (horizontes y Customer).
 *
 * - GET /models/evaluations          evaluaciones de calidad: backtest, ventanas de selección, métricas por segmento y horizonte (Demand), calibración por
 *                                    segmento y métricas de S05 (Customer) y evaluación prospectiva del shadow. Las vistas clásicas NO las muestran.
 * - GET /models/promotion-decisions  decisión de la política heredada y de la V2.5 por ejecución y alcance, cuál gobernó y los gates con su evidencia.
 * - GET /models/shadow-evaluations   challenger no servido evaluado contra lo realizado después del corte, con lo pendiente de evaluar.
 */
final class ModelQualityController extends AnalyticsController
{
    public function __construct(private readonly RunResolver $runs) {}

    public function evaluations(ModelEvaluationsQueryRequest $request): AnonymousResourceCollection
    {
        $tenant = $this->tenant();
        $core = $request->validated('core');
        $horizon = $request->intOrNull('horizon');
        $plan = app(PlanLimitService::class);
        if ($core === 'customer') {
            $plan->assertCustomer($tenant);   // 402: el plan no incluye el comportamiento de compra
        }
        if ($horizon !== null) {
            CoreServices::assertHorizon($tenant, $horizon);          // 402: un resultado de un horizonte fuera del plan no se revela
        }
        $runId = $request->intOrNull('run_id');
        $coreRunId = $request->intOrNull('core_run_id');
        $run = null;
        if ($runId !== null) {
            $run = ForecastRun::query()->find($runId) ?? throw new NotFoundHttpException('Ejecución de pronóstico no encontrada.');
        }
        if ($coreRunId !== null) {
            CoreRun::query()->find($coreRunId) ?? throw new NotFoundHttpException('Ejecución no encontrada.');
        }
        $dimension = $request->validated('dimension');
        $status = $request->validated('status');
        $allowedHorizons = CoreServices::allowedHorizons($tenant);
        $customerAllowed = $plan->allowsCustomer($tenant);
        $current = $runId === null && $coreRunId === null;   // sin ejecución indicada: la vigente de cada core (no se recorre todo el historial)
        if ($current) {
            $run = in_array($core, [null, 'demand'], true) ? $this->runs->current() : null;
        }

        $rows = ModelEvaluation::query()
            ->when($core !== null, fn (Builder $q) => $q->where('core', $core))
            ->when($core === null && ! $customerAllowed, fn (Builder $q) => $q->where('core', '<>', 'customer'))
            ->when($runId !== null, fn (Builder $q) => $q->where('forecast_run_id', $runId))
            ->when($coreRunId !== null, fn (Builder $q) => $q->where('core_run_id', $coreRunId))
            ->when($current, fn (Builder $q) => $this->limitToCurrentRuns($q, $core, $run, $customerAllowed))
            ->when($horizon !== null, fn (Builder $q) => $q->where('horizon_days', $horizon))
            ->when($horizon === null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('horizon_days')->orWhereIn('horizon_days', $allowedHorizons)))
            ->when($request->validated('evaluation_type'), fn (Builder $q, $v) => $q->where('evaluation_type', $v))
            ->when($request->validated('level'), fn (Builder $q, $v) => $q->where('level', $v))
            ->when($dimension !== null, fn (Builder $q) => $q->where('level', ModelEvaluation::LEVEL_SEGMENT)->whereRaw("split_part(level_key, ':', 1) = ?", [$dimension]))
            ->when($request->validated('level_key'), fn (Builder $q, $v) => $q->where('level_key', $v))
            ->when($request->validated('model_family'), fn (Builder $q, $v) => $q->where('model_family', $v))
            ->when($request->validated('is_selected') !== null, fn (Builder $q) => $q->where('is_selected', $request->boolean('is_selected')))
            ->when($request->validated('is_baseline') !== null, fn (Builder $q) => $q->where('is_baseline', $request->boolean('is_baseline')))
            ->when($status !== null, fn (Builder $q) => $q->whereRaw("metrics->>'status' = ?", [$status]))
            ->orderByRaw('COALESCE(forecast_run_id, core_run_id) DESC NULLS LAST')
            ->orderBy('level')->orderBy('level_key')->orderBy('horizon_days')->orderByDesc('is_selected')->orderBy('model_family')->orderBy('id')
            ->paginate($this->perPage($request, 25));
        // El recurso lee el umbral de la petición ACTUAL de la aplicación (no del FormRequest, que es otra instancia).
        request()->attributes->set('wape_min_denominator', $this->minDenominator($run));

        return ModelQualityEvaluationResource::collection($rows);
    }

    /**
     * Sin `run_id` ni `core_run_id`: solo la ejecución vigente de Demand y el último entrenamiento de Customer (si el plan lo incluye y el `core` lo permite).
     * Si no hay ninguna, no hay filas (nunca se recorre todo el historial).
     *
     * @param  Builder<ModelEvaluation>  $query
     */
    private function limitToCurrentRuns(Builder $query, ?string $core, ?ForecastRun $run, bool $customerAllowed): void
    {
        $customer = $customerAllowed && in_array($core, [null, 'customer'], true) ? app(CustomerRunService::class)->latestEvaluation() : null;
        if ($run === null && $customer === null) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->where(function (Builder $w) use ($run, $customer): void {
            if ($run !== null) {
                $w->orWhere(fn (Builder $d) => $d->where('core', 'demand')->where('forecast_run_id', $run->id));
            }
            if ($customer !== null) {
                $w->orWhere(fn (Builder $c) => $c->where('core', 'customer')->where('core_run_id', $customer->id));
            }
        });
    }

    /** Umbral `wape_min_denominator` de la ejecución (su configuración efectiva) o el valor por defecto del motor. */
    private function minDenominator(?ForecastRun $run): float
    {
        $configured = data_get($run?->effective_config, 'config.wape_min_denominator');

        return is_numeric($configured) ? (float) $configured : EvaluationPresenter::DEFAULT_WAPE_MIN_DENOMINATOR;
    }

    public function promotionDecisions(PromotionDecisionsRequest $request): AnonymousResourceCollection
    {
        $tenant = $this->tenant();
        $core = $request->validated('core');
        $plan = app(PlanLimitService::class);
        if ($core === 'customer') {
            $plan->assertCustomer($tenant);
        }
        $scope = $request->validated('scope');
        $allowedHorizons = CoreServices::allowedHorizons($tenant);
        if (is_string($scope) && preg_match('/^horizon:(\d+)$/', $scope, $m) === 1) {
            CoreServices::assertHorizon($tenant, (int) $m[1]);   // 402: el alcance de un horizonte fuera del plan no se revela
        }
        $run = null;
        $runId = $request->intOrNull('run_id');
        $coreRunId = $request->intOrNull('core_run_id');
        $none = false;
        if ($runId !== null) {
            $run = ForecastRun::query()->find($runId) ?? throw new NotFoundHttpException('Ejecución de pronóstico no encontrada.');
        } elseif ($coreRunId !== null) {
            CoreRun::query()->find($coreRunId) ?? throw new NotFoundHttpException('Ejecución no encontrada.');
        } elseif ($core === 'customer') {
            $coreRunId = app(CustomerRunService::class)->latestEvaluation()?->id;   // sin ejecución indicada: el último entrenamiento de Customer
            $none = $coreRunId === null;
        } elseif ($core === null || $core === 'demand') {
            $run = $this->runs->current();   // sin ejecución indicada: la de Demand vigente (las anteriores, con run_id)
            $none = $run === null;           // sin ejecución vigente no hay decisiones que mostrar (no se devuelve todo el historial)
        }

        $page = ModelPromotionDecision::query()
            ->when($none, fn (Builder $q) => $q->whereRaw('1 = 0'))
            ->when($core !== null, fn (Builder $q) => $q->where('core', $core))
            ->when($core === null && ! $plan->allowsCustomer($tenant), fn (Builder $q) => $q->where('core', '<>', 'customer'))
            ->when($run !== null, fn (Builder $q) => $q->where('forecast_run_id', $run->id))
            ->when($coreRunId !== null, fn (Builder $q) => $q->where('core_run_id', $coreRunId))
            ->tap(fn (Builder $q) => $this->withinPlanHorizons($q, $allowedHorizons))
            ->when($request->validated('policy'), fn (Builder $q, $v) => $q->where('policy', $v))
            ->when($scope, fn (Builder $q, $v) => $q->where('scope_key', $v))
            ->when($request->validated('decision'), fn (Builder $q, $v) => $q->where('decision', $v))
            ->when($request->validated('is_effective') !== null, fn (Builder $q) => $q->where('is_effective', $request->boolean('is_effective')))
            ->orderByRaw('COALESCE(forecast_run_id, core_run_id) DESC NULLS LAST')
            ->orderByRaw("CASE WHEN scope_key = 'global' THEN 0 WHEN scope_key LIKE 'class:%' THEN 1 ELSE 2 END")
            ->orderBy('scope_key')->orderBy('policy')->orderBy('id')
            ->paginate($this->perPage($request, 50));

        return PromotionDecisionResource::collection($page)->additional([
            'run' => $run === null ? null : $this->runRef($run),
            'summary' => $run === null ? null : $this->decisionSummary($run, $allowedHorizons),
        ]);
    }

    /**
     * Los alcances `horizon:<H>` de un horizonte que el plan no incluye no se revelan (familias, gates ni métricas). Otros alcances no se tocan.
     *
     * @param  Builder<ModelPromotionDecision>  $query
     * @param  list<int>  $allowedHorizons
     */
    private function withinPlanHorizons(Builder $query, array $allowedHorizons): void
    {
        $query->where(fn (Builder $w) => $w->where('scope_key', 'not like', 'horizon:%')
            ->orWhereIn('scope_key', array_map(fn (int $h) => "horizon:{$h}", $allowedHorizons)));
    }

    public function shadowEvaluations(ShadowEvaluationsRequest $request): AnonymousResourceCollection
    {
        $tenant = $this->tenant();
        $horizon = $request->intOrNull('horizon');
        if ($horizon !== null) {
            CoreServices::assertHorizon($tenant, $horizon);
        }
        $runId = $request->intOrNull('run_id');
        $run = $runId !== null
            ? (ForecastRun::query()->find($runId) ?? throw new NotFoundHttpException('Ejecución de pronóstico no encontrada.'))
            : $this->runs->current();
        $allowedHorizons = CoreServices::allowedHorizons($tenant);

        $rows = ModelEvaluation::query()
            ->where('core', 'demand')->where('evaluation_type', ModelEvaluation::TYPE_SHADOW_PROSPECTIVE)
            ->when($run !== null, fn (Builder $q) => $q->where('forecast_run_id', $run->id), fn (Builder $q) => $q->whereRaw('1 = 0'))
            ->when($horizon !== null, fn (Builder $q) => $q->where('horizon_days', $horizon))
            ->when($horizon === null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('horizon_days')->orWhereIn('horizon_days', $allowedHorizons)))
            ->when($request->validated('level'), fn (Builder $q, $v) => $q->where('level', $v))
            ->when($request->validated('model_family'), fn (Builder $q, $v) => $q->where('model_family', $v))
            ->orderBy('model_family')->orderByRaw("CASE level WHEN 'overall' THEN 0 ELSE 1 END")->orderBy('horizon_days')->orderBy('id')
            ->paginate($this->perPage($request, 50));

        // El recurso lee el umbral de la petición ACTUAL de la aplicación (no del FormRequest, que es otra instancia).
        request()->attributes->set('wape_min_denominator', $this->minDenominator($run));

        return ModelQualityEvaluationResource::collection($rows)->additional([
            'run' => $run === null ? null : $this->runRef($run),
            // Qué predicciones no servidas existen y cuáles esperan datos realizados (nunca los valores pronosticados).
            'shadow_forecasts' => $run === null ? [] : ShadowSummary::forRuns([(int) $run->id]),
        ]);
    }

    /** @return array<string, mixed> */
    private function runRef(ForecastRun $run): array
    {
        return [
            'id' => $run->id,
            'version' => (int) $run->version,
            'run_type' => $run->run_type,
            'cutoff_date' => $run->cutoff_date?->toDateString(),
            'is_current' => (bool) $run->is_current,
        ];
    }

    /**
     * Resumen de las decisiones de UNA ejecución: qué política gobernó lo servido, las huellas de ambas, y en cuántos alcances la V2.5 habría
     * servido un modelo distinto al de la política heredada. Los alcances informativos (por horizonte) no cuentan: no gobiernan lo servido.
     *
     * @param  list<int>  $allowedHorizons
     * @return array<string, mixed>|null
     */
    private function decisionSummary(ForecastRun $run, array $allowedHorizons): ?array
    {
        $rows = ModelPromotionDecision::query()->where('forecast_run_id', $run->id)->where('core', 'demand')
            ->tap(fn (Builder $q) => $this->withinPlanHorizons($q, $allowedHorizons))
            ->get(['scope_key', 'policy', 'policy_version', 'decision', 'is_effective', 'metrics']);
        if ($rows->isEmpty()) {
            return null;
        }
        $configured = data_get($run->effective_config, 'promotion.governing_policy');
        $governing = is_string($configured) ? $configured : null;
        $effective = $rows->filter(fn (ModelPromotionDecision $d) => $d->is_effective && ! ($d->metrics['informational'] ?? false));
        if ($governing === null) {
            $governing = $effective->pluck('policy')->countBy()->sortDesc()->keys()->first();
        }
        $byPolicy = [];
        foreach (['legacy', 'v25'] as $policy) {
            $mine = $rows->where('policy', $policy);
            $byPolicy[$policy] = [
                'policy_version' => $mine->first()?->policy_version,
                'decisions' => $mine->count(),
                'effective' => $mine->where('is_effective', true)->count(),
                'by_decision' => $mine->countBy('decision')->all(),
            ];
        }
        $compared = 0;
        $different = [];
        foreach ($rows->where('policy', 'legacy')->reject(fn (ModelPromotionDecision $d) => $d->metrics['informational'] ?? false) as $legacy) {
            $v25 = $rows->first(fn (ModelPromotionDecision $d) => $d->policy === 'v25' && $d->scope_key === $legacy->scope_key);
            if ($v25 === null) {
                continue;
            }
            $compared++;
            $a = $legacy->metrics['served_family'] ?? null;
            $b = $v25->metrics['served_family'] ?? null;
            if ($a !== $b) {
                $different[] = ['scope_key' => $legacy->scope_key, 'legacy_served_family' => $a, 'v25_served_family' => $b];
            }
        }

        return [
            'governing_policy' => $governing,
            'governing_source' => is_string($configured) ? 'run_config' : 'decisions',
            'policies' => (object) $byPolicy,
            'agreement' => ['scopes_compared' => $compared, 'same_served' => $compared - count($different), 'different' => $different],
        ];
    }
}
