<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Services\Pipeline\OutcomesStageRunner;
use RuntimeException;

/** Medición de resultados de las recomendaciones de UNA empresa (ADR-V26-01 · F4). Lo lanza `predictily:outcomes-measure --queue`. */
final class RunOutcomesStage extends TenantStageJob
{
    protected function stage(): string
    {
        return PipelineStage::OUTCOMES;
    }

    protected function runStage(Tenant $tenant): void
    {
        $report = app(OutcomesStageRunner::class)->run($tenant);
        if ($report['errors'] !== []) {
            throw new RuntimeException('outcomes_partial_errors');
        }
    }
}
