<?php

declare(strict_types=1);

namespace App\Jobs\Forecasting;

use App\Jobs\Middleware\SetTenantContext;
use App\Models\ForecastRun;
use App\Models\Tenant;
use App\Models\TrainingJob;
use App\Services\Forecasting\EngineClient;
use App\Services\Forecasting\Exceptions\EngineException;
use App\Services\Forecasting\TrainingJobRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ejecuta el pipeline de pronóstico en el motor Python (docs/06 §4.1).
 *
 * - Corre en un worker de cola: el usuario nunca espera el entrenamiento.
 * - Reintenta ante errores transitorios (conexión, 5xx, 409); NO reintenta ante
 *   datos insuficientes (422) ni errores de configuración (401/404).
 * - IMPORTANTE: la conexión Redis debe tener retry_after > timeout de este Job
 *   (REDIS_QUEUE_RETRY_AFTER, ver .env.example) para evitar ejecuciones duplicadas.
 */
class RunForecastPipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(
        public int $tenantId,
        public int $forecastRunId,
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
        return [new SetTenantContext()];
    }

    public function handle(EngineClient $engine, TrainingJobRecorder $recorder): void
    {
        $run = ForecastRun::query()->find($this->forecastRunId);
        $job = TrainingJob::query()->find($this->trainingJobId);
        if ($run === null || $job === null || $job->forecast_run_id !== $run->id) {
            Log::warning('RunForecastPipeline: ejecución o trabajo inexistente para la empresa', [
                'tenant_id' => $this->tenantId, 'run_id' => $this->forecastRunId, 'job_id' => $this->trainingJobId,
            ]);

            return;
        }
        if ($run->status === ForecastRun::STATUS_COMPLETED) {
            $recorder->markSucceeded($job, ['run_id' => $run->id, 'status' => 'completed']);

            return;
        }

        $recorder->markAttempt($job);
        $options = (array) ($run->options ?? []);

        try {
            $result = $engine->executeRun(
                runId: $run->id,
                tenantId: $this->tenantId,
                trainingJobId: $job->id,
                horizons: (array) ($options['horizons'] ?? [$run->horizon_days]),
                cutoffDate: $options['cutoff_date'] ?? null,
                options: array_filter([
                    'seed' => $options['seed'] ?? null,
                    'force_models' => $options['force_models'] ?? null,
                    'max_backtest_windows' => $options['max_backtest_windows'] ?? null,
                ], fn ($v) => $v !== null),
            );
        } catch (EngineException $e) {
            if (! $e->isRetryable()) {
                $this->fail($e);

                return;
            }
            throw $e;
        }

        $recorder->markSucceeded($job, $result);
        $run->refresh();
        if (! $run->isFinished()) {
            // Defensa: el motor respondió OK pero no cerró el run.
            $run->forceFill([
                'status' => ForecastRun::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        app(TenantContext::class)->runAs($tenant, function () use ($exception): void {
            $recorder = app(TrainingJobRecorder::class);
            $message = $exception?->getMessage() ?: 'El trabajo de pronóstico falló.';
            $job = TrainingJob::query()->find($this->trainingJobId);
            $run = ForecastRun::query()->find($this->forecastRunId);
            if ($job !== null) {
                $recorder->markFailed($job, $message);
            }
            if ($run !== null) {
                $recorder->markRunFailed($run, $message);
            }
        });
    }
}
