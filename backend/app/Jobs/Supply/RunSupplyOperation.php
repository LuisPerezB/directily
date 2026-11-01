<?php

declare(strict_types=1);

namespace App\Jobs\Supply;

use App\Jobs\Middleware\SetTenantContext;
use App\Models\CoreRun;
use App\Models\Tenant;
use App\Models\TrainingJob;
use App\Services\Forecasting\Exceptions\EngineException;
use App\Services\Forecasting\TrainingJobRecorder;
use App\Services\Supply\SupplyEngineClient;
use App\Services\Supply\SupplyRunRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base de los Jobs de decisiones: ejecuta la corrida en el motor (`/v1/supply/runs/{id}/execute`).
 *
 * - Idempotente: una corrida completada no se vuelve a ejecutar; el motor reemplaza resultados parciales de un
 *   intento anterior (plan y líneas se reescriben por corrida), por lo que un reintento nunca duplica filas.
 * - Reintenta ante errores transitorios (conexión, 5xx, 409); NO ante datos insuficientes ni errores de configuración.
 * - La conexión Redis debe tener retry_after > timeout del Job (ver REDIS_QUEUE_RETRY_AFTER).
 */
abstract class RunSupplyOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(
        public int $tenantId,
        public int $coreRunId,
        public int $trainingJobId,
    ) {
        $this->timeout = (int) config('engine.timeout', 900) + 60;
        $this->onQueue((string) config('engine.queue', 'forecasting'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 180];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }

    public function handle(SupplyEngineClient $engine, TrainingJobRecorder $jobs): void
    {
        $run = CoreRun::query()->whereIn('core', ['supply', 'cost', 'scenario'])->find($this->coreRunId);
        $job = TrainingJob::query()->find($this->trainingJobId);
        if ($run === null || $job === null || (int) $job->core_run_id !== (int) $run->id) {
            Log::warning('Decisiones: corrida o trabajo inexistente para la empresa', [
                'tenant_id' => $this->tenantId, 'core_run_id' => $this->coreRunId, 'job_id' => $this->trainingJobId,
            ]);

            return;
        }
        if ($run->status === 'completed') {
            $jobs->markSucceeded($job, ['core_run_id' => $run->id, 'status' => 'completed']);

            return;
        }
        $jobs->markAttempt($job);
        try {
            $result = $engine->execute($run->id, $this->tenantId, $job->id);
        } catch (EngineException $e) {
            if (! $e->isRetryable()) {
                $this->fail($e);

                return;
            }
            throw $e;
        }
        $jobs->markSucceeded($job, $result);
        $run->refresh();
        if (! in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            // Defensa: el motor respondió OK pero no cerró la corrida.
            $run->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        app(TenantContext::class)->runAs($tenant, function () use ($exception): void {
            $message = $exception?->getMessage() ?: 'El trabajo de decisiones falló.';
            $job = TrainingJob::query()->find($this->trainingJobId);
            $run = CoreRun::query()->whereIn('core', ['supply', 'cost', 'scenario'])->find($this->coreRunId);
            if ($job !== null) {
                app(TrainingJobRecorder::class)->markFailed($job, $message);
            }
            if ($run !== null) {
                app(SupplyRunRecorder::class)->markFailed($run, $message);
            }
        });
    }
}
