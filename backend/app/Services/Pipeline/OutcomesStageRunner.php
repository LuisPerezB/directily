<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Services\Outcomes\OutcomeMeasurementService;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Etapa «medición de resultados» del ciclo diario de UNA empresa (ADR-V26-01 · F4): mide las recomendaciones cuya ventana ya cerró y
 * deja constancia. La usan el comando `predictily:outcomes-measure` y el trabajo RunOutcomesStage. No depende de ninguna otra etapa
 * del día: mide lo que ya ocurrió.
 */
final class OutcomesStageRunner
{
    public function __construct(private readonly OutcomeMeasurementService $service, private readonly DailyPipeline $pipeline) {}

    /**
     * @param  int|null  $lag  margen de espera en días (null = el de la empresa)
     * @return array<string, mixed> el informe de OutcomeMeasurementService::measure()
     *
     * @throws Throwable si la medición falla por completo; la etapa queda anotada como `failed`
     */
    public function run(Tenant $tenant, ?int $lag = null): array
    {
        $date = $this->pipeline->businessDate($tenant);
        try {
            $report = $this->service->measure($tenant, CarbonImmutable::now(), false, $lag);
        } catch (Throwable $e) {
            $this->pipeline->record($tenant, $date, PipelineStage::OUTCOMES, PipelineStage::FAILED, ['reason' => 'outcomes_failed']);

            throw $e;
        }
        $errors = count((array) ($report['errors'] ?? []));
        $this->pipeline->record($tenant, $date, PipelineStage::OUTCOMES, PipelineStage::SUCCEEDED, [
            'reason' => $errors > 0 ? 'partial_errors' : null,
            'detail' => ['measured' => (int) ($report['measured'] ?? 0), 'errors' => $errors],
        ]);

        return $report;
    }
}
