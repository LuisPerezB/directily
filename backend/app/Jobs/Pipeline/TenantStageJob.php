<?php

declare(strict_types=1);

namespace App\Jobs\Pipeline;

use App\Models\Tenant;
use App\Services\Pipeline\PipelineQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Base de los trabajos «una etapa del ciclo diario para UNA empresa» (ADR-V26-01 · F4). Antes cada etapa recorría todas las empresas
 * en serie dentro del planificador: una empresa lenta retrasaba a las siguientes y la que fallaba esperaba al día siguiente.
 *
 * - Un trabajo por empresa: su fallo queda en `failed_jobs` con su alerta operativa y no afecta a las demás.
 * - Reintenta una vez; las etapas son idempotentes, así que repetir no duplica nada.
 * - Único por empresa y etapa mientras está en cola (el barrido por hora y el lanzamiento por evento no se duplican).
 * - Cada etapa deja constancia en `pipeline_stages` desde su ejecutor (App\Services\Pipeline\*StageRunner).
 * - Si la etapa termina con errores parciales, el trabajo se marca fallido con un código estable: quien opera debe enterarse.
 */
abstract class TenantStageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 1200;

    public function __construct(public int $tenantId)
    {
        $this->onQueue(PipelineQueue::name());
    }

    /** Nombre de la etapa (PipelineStage::*). */
    abstract protected function stage(): string;

    abstract protected function runStage(Tenant $tenant): void;

    public function uniqueId(): string
    {
        return $this->stage().'-stage:'.$this->tenantId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [120];
    }

    public function handle(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null || $tenant->status !== 'active') {
            return;
        }
        $this->runStage($tenant);
    }
}
