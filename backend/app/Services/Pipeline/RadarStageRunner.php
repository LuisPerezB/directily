<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Services\Customer\CustomerRunService;
use App\Services\Radar\CommercialOpportunityService;
use App\Services\Radar\Exceptions\RadarException;
use App\Services\Radar\MaterializationResult;
use App\Support\Tenancy\TenantContext;
use Throwable;

/**
 * Etapa «radar» del ciclo diario de UNA empresa (ADR-V26-01 · F1): comprueba de qué depende, propone las oportunidades y deja
 * constancia. La usan el comando `predictily:radar-materialize` (por hora, todas las empresas) y el trabajo RunRadarStage (al
 * terminar la predicción de la empresa), para que ambos decidan igual.
 *
 * Depende de la predicción de clientes de HOY:
 * - terminó bien → propone con ella.
 * - sigue en cola o en curso → `waiting`: no propone con la de ayer; se lanzará sola al terminar la predicción.
 * - falló → `blocked`: no propone; el motivo queda a la vista. Afecta solo a esa empresa.
 * - no se pidió hoy (programación manual, sin modelo) → propone con la vigente y lo anota (`no_prediction_today`).
 */
final class RadarStageRunner
{
    public function __construct(
        private readonly DailyPipeline $pipeline,
        private readonly CommercialOpportunityService $radar,
        private readonly CustomerRunService $runs,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  list<int>|null  $horizons  null = los de la predicción que el plan permite
     * @param  bool  $force  propone con la predicción vigente aunque la de hoy no esté lista (uso manual de quien opera)
     * @return array{status: string, reason: string|null, result: MaterializationResult|null}
     *
     * @throws RadarException si el Decision Engine no está disponible (condición global, no de la empresa)
     * @throws Throwable si proponer falla; la etapa queda anotada como `failed`
     */
    public function run(Tenant $tenant, ?array $horizons = null, bool $force = false): array
    {
        return $this->context->runAs($tenant, function () use ($tenant, $horizons, $force): array {
            $date = $this->pipeline->businessDate($tenant);
            $close = function (string $status, ?string $reason, array $extra = [], ?MaterializationResult $result = null) use ($tenant, $date): array {
                $this->pipeline->record($tenant, $date, PipelineStage::RADAR, $status, ['reason' => $reason] + $extra);

                return ['status' => $status, 'reason' => $reason, 'result' => $result];
            };

            if ($this->runs->allowedHorizons($tenant) === []) {
                return $close(PipelineStage::SKIPPED, 'plan_without_customer');
            }
            $todays = $this->pipeline->customerPredictionState($tenant, $date);
            $prediction = $todays['state'];
            if (! $force && $prediction === DailyPipeline::PREDICTION_WAITING) {
                return $close(PipelineStage::WAITING, 'prediction_in_progress', ['blocked_by' => PipelineStage::CUSTOMER_PREDICT]);
            }
            if (! $force && $prediction === DailyPipeline::PREDICTION_FAILED) {
                return $close(PipelineStage::BLOCKED, 'prediction_failed', ['blocked_by' => PipelineStage::CUSTOMER_PREDICT]);
            }
            $run = $this->radar->currentRun();
            if ($run === null) {
                return $close(PipelineStage::SKIPPED, 'no_prediction_run');
            }

            $source = ['source_ref' => 'core_run:'.$run->getKey()];
            $this->pipeline->record($tenant, $date, PipelineStage::RADAR, PipelineStage::RUNNING, $source);
            try {
                $result = $this->radar->propose($run, $horizons);
            } catch (Throwable $e) {
                $close(PipelineStage::FAILED, $e instanceof RadarException ? $e->errorCode : 'radar_failed', $source);

                throw $e;
            }

            // El radar propone con la predicción VIGENTE (is_current). Se anota si esa es, además, la de hoy.
            $ready = $prediction === DailyPipeline::PREDICTION_READY;
            $fromToday = $ready && (int) $todays['run']?->getKey() === (int) $run->getKey();
            $reason = match (true) {
                $result->failed > 0 => 'partial_errors',
                $ready && ! $fromToday => 'todays_prediction_not_current',
                ! $ready => 'no_prediction_today',
                default => null,
            };

            return $close(PipelineStage::SUCCEEDED, $reason, $source + ['detail' => [
                'candidates' => $result->candidates, 'created' => $result->created, 'existing' => $result->existing, 'failed' => $result->failed,
                'prediction_from_today' => $fromToday,
            ]], $result);
        });
    }
}
