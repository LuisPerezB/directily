<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Services\Pipeline\DecisionsStageRunner;
use RuntimeException;

/** Recomendaciones del Decision Engine de UNA empresa (ADR-V26-01 · F4). Lo lanza `predictily:decisions-generate --queue`. */
final class RunDecisionsStage extends TenantStageJob
{
    protected function stage(): string
    {
        return PipelineStage::DECISIONS;
    }

    protected function runStage(Tenant $tenant): void
    {
        $outcome = app(DecisionsStageRunner::class)->run($tenant);
        if ($outcome['report']['errors'] !== []) {
            throw new RuntimeException('decisions_partial_errors');
        }
    }
}
