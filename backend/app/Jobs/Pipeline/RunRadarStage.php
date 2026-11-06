<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Services\Pipeline\RadarStageRunner;
use RuntimeException;

/**
 * Radar de UNA empresa (ADR-V26-01 · F1 y F4). Lo lanzan el fin de la predicción de clientes de la empresa y el barrido por hora
 * (`predictily:radar-materialize --queue`). Si la predicción de hoy no está lista, la etapa queda en espera o bloqueada (lo decide
 * RadarStageRunner) y el trabajo termina sin error: el fallo de la predicción ya generó su propia alerta.
 */
final class RunRadarStage extends TenantStageJob
{
    protected function stage(): string
    {
        return PipelineStage::RADAR;
    }

    protected function runStage(Tenant $tenant): void
    {
        $result = app(RadarStageRunner::class)->run($tenant)['result'];
        if ($result !== null && $result->failed > 0) {
            throw new RuntimeException('radar_partial_errors');
        }
    }
}
