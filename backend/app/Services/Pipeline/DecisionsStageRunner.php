<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Services\Decision\DecisionGenerator;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Etapa «decisiones» del ciclo diario de UNA empresa (ADR-V26-01 · F1 y F4): genera las recomendaciones y deja constancia. La usan
 * el comando `predictily:decisions-generate` y el trabajo RunDecisionsStage, para que ambos se comporten igual.
 *
 * Las decisiones NO se bloquean por la predicción de clientes: reposición, inventario y presupuesto no dependen de ella. Pero si la
 * predicción de hoy no está lista, las recomendaciones de cliente salen de la última disponible: se anota (`customer_prediction_not_ready`)
 * en vez de terminar «bien» sin decirlo.
 */
final class DecisionsStageRunner
{
    public function __construct(private readonly DecisionGenerator $generator, private readonly DailyPipeline $pipeline) {}

    /**
     * @return array{report: array{tenant_id: int, dry_run: bool, created: array<string, int>, existing: array<string, int>, invalid: int, skipped: array<string, string>, errors: list<string>}, prediction: string, prediction_not_ready: bool}
     *
     * @throws Throwable si la generación falla por completo; la etapa queda anotada como `failed`
     */
    public function run(Tenant $tenant): array
    {
        $date = $this->pipeline->businessDate($tenant);
        try {
            $report = $this->generator->generate($tenant, CarbonImmutable::now($tenant->timezone ?: 'America/Guayaquil'), false);
        } catch (Throwable $e) {
            $this->pipeline->record($tenant, $date, PipelineStage::DECISIONS, PipelineStage::FAILED, ['reason' => 'decisions_failed']);

            throw $e;
        }

        try {
            $prediction = $this->pipeline->customerPredictionState($tenant, $date)['state'];
        } catch (Throwable) {
            $prediction = 'unknown';
        }
        $notReady = in_array($prediction, [DailyPipeline::PREDICTION_WAITING, DailyPipeline::PREDICTION_FAILED], true);
        $this->pipeline->record($tenant, $date, PipelineStage::DECISIONS, PipelineStage::SUCCEEDED, [
            'reason' => match (true) {
                $report['errors'] !== [] => 'partial_errors',
                $notReady => 'customer_prediction_not_ready',
                default => null,
            },
            'detail' => [
                'created' => array_sum($report['created']), 'existing' => array_sum($report['existing']), 'invalid' => $report['invalid'],
                'errors' => count($report['errors']), 'customer_prediction' => $prediction,
            ],
        ]);

        return ['report' => $report, 'prediction' => $prediction, 'prediction_not_ready' => $notReady];
    }
}
