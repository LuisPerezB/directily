<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

use App\Models\ModelEvaluation;
use App\Models\ModelPromotionDecision;
use App\Services\Analytics\DemandEnvelope;
use App\Services\Analytics\ModelQuality\EvaluationPresenter;
use App\Services\Analytics\RunResolver;
use App\Services\Customer\CustomerEnvelope;
use App\Services\Customer\CustomerRunService;
use App\Services\Forecasting\Support\CoreServices;
use App\Services\Plans\PlanLimitService;
use App\Support\Horizons\HorizonCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Calidad de los modelos servidos (GET /models/evaluations y /models/promotion-decisions, W3-D): la evaluación global del modelo elegido y de su referencia por horizonte
 * y las decisiones de promoción vigentes, de la corrida actual de Demand o del último entrenamiento de Customer. Solo lectura de lo que el motor evaluó: una fila con datos
 * insuficientes sale SIN cifras de error (nunca ceros) y un WAPE inestable ya viene marcado. Con el plan que corresponda (horizontes y Customer).
 */
final class ModelQualityTool extends ReadTool
{
    private const KEYS = ['id', 'core', 'model_family', 'label', 'evaluation_type', 'level', 'horizon_days', 'is_baseline', 'is_selected', 'status', 'figures_available', 'wape', 'mae', 'rmse', 'bias', 'coverage_80', 'coverage_95', 'n_obs', 'n_windows'];

    public function __construct(private readonly RunResolver $runs, private readonly DemandEnvelope $envelopes, private readonly CustomerRunService $customerRuns) {}

    public function name(): string
    {
        return 'model_quality';
    }

    public function description(): string
    {
        return 'Calidad ya evaluada de los modelos de la empresa: error (WAPE), sesgo y cobertura de intervalos del modelo elegido frente a su referencia por horizonte, y qué decisiones de promoción '
            .'están vigentes. Una evaluación con datos insuficientes no trae cifras de error. Puede consultarse el pronóstico de demanda o el comportamiento de compra.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'core' => ['type' => 'string', 'enum' => ['demand', 'customer'], 'description' => 'Qué modelo consultar; por omisión, demanda.'],
                'horizon' => $this->horizonSchema(HorizonCatalog::demand()),
                'limit' => $this->limitSchema(),
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'core' => ['sometimes', 'string', Rule::in(['demand', 'customer'])],
            'horizon' => ['sometimes', 'integer', Rule::in(HorizonCatalog::demand())],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('conversation.limits.max_rows_per_tool', 10)],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $tenant = $context->tenant;
        $core = (string) ($arguments['core'] ?? 'demand');
        $horizon = isset($arguments['horizon']) ? (int) $arguments['horizon'] : null;
        if ($core === 'customer') {
            app(PlanLimitService::class)->assertCustomer($tenant);
        }
        if ($horizon !== null) {
            CoreServices::assertHorizon($tenant, $horizon);
        }
        $allowed = CoreServices::allowedHorizons($tenant);

        if ($core === 'customer') {
            $run = $this->customerRuns->latestEvaluation();
            $envelope = $run === null ? null : CustomerEnvelope::forRun($run, $tenant, $horizon);
            $scope = fn (Builder $q) => $q->where('core', 'customer')->where('core_run_id', $run?->id);
            $minDenominator = null;
        } else {
            $run = $this->runs->resolveCompleted(null);
            $envelope = $run === null ? null : $this->envelopes->for($run, $horizon, ['result_type' => 'evaluation']);
            $scope = fn (Builder $q) => $q->where('core', 'demand')->where('forecast_run_id', $run?->id);
            $configured = data_get($run?->effective_config, 'config.wape_min_denominator');
            $minDenominator = is_numeric($configured) ? (float) $configured : EvaluationPresenter::DEFAULT_WAPE_MIN_DENOMINATOR;
        }
        if ($run === null) {
            return ToolResult::noData('Aún no hay una evaluación de modelos para esta empresa.');
        }

        $rows = ModelEvaluation::query()->tap($scope)
            ->where('level', 'overall')
            ->where(fn (Builder $w) => $w->where('is_selected', true)->orWhere('is_baseline', true))
            ->when($horizon !== null, fn (Builder $q) => $q->where('horizon_days', $horizon))
            ->when($horizon === null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('horizon_days')->orWhereIn('horizon_days', $allowed)))
            ->orderBy('horizon_days')->orderByDesc('is_selected')->orderBy('model_family')->orderBy('id')
            ->limit($this->limit($arguments, 10))->get();

        $decisions = ModelPromotionDecision::query()->where('is_effective', true)->tap($scope)
            ->where(fn (Builder $w) => $w->where('scope_key', 'not like', 'horizon:%')->orWhereIn('scope_key', array_map(fn (int $h) => "horizon:{$h}", $allowed)))
            ->orderBy('scope_key')->orderBy('policy')->limit(10)->get(['scope_key', 'policy', 'policy_version', 'decision']);

        $data = [
            'core' => $core,
            'evaluations' => $rows->map(fn (ModelEvaluation $e): array => array_intersect_key(EvaluationPresenter::row($e, $minDenominator), array_flip(self::KEYS)))->values()->all(),
            'promotion_decisions' => $decisions->map(fn ($d): array => ['scope_key' => $d->scope_key, 'policy' => $d->policy, 'policy_version' => $d->policy_version, 'decision' => $d->decision])->values()->all(),
            'note' => 'Una evaluación con figures_available = false no trae cifras de error (datos insuficientes); no se interpretan como cero.',
        ];
        if ($rows->isEmpty()) {
            return ToolResult::noData('No hay evaluaciones globales del modelo para ese filtro.', $envelope, $data);
        }

        return ToolResult::ok($data, $envelope);
    }
}
