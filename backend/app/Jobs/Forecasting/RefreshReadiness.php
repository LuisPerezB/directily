<?php

declare(strict_types=1);

namespace App\Jobs\Forecasting;

use App\Jobs\Middleware\SetTenantContext;
use App\Models\TrainingJob;
use App\Services\Forecasting\EngineClient;
use App\Services\Forecasting\Exceptions\EngineException;
use App\Services\Forecasting\TrainingJobRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Perfila las series de la empresa y su calidad para pronóstico (docs/06 §4.2).
 * Se despacha al completar una importación. Único por empresa mientras está en cola.
 */
class RefreshReadiness implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 420;

    public int $uniqueFor = 600;

    public function __construct(
        public int $tenantId,
        public ?int $trainingJobId = null,
    ) {
        $this->onQueue((string) config('engine.queue', 'forecasting'));
    }

    public function uniqueId(): string
    {
        return 'readiness:'.$this->tenantId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetTenantContext()];
    }

    public function handle(EngineClient $engine, TrainingJobRecorder $recorder): void
    {
        $job = $this->resolveTrainingJob();
        $recorder->markAttempt($job);
        $recorder->markRunning($job, 'profiling');

        try {
            $result = $engine->readiness($this->tenantId, $job->id);
        } catch (EngineException $e) {
            if (! $e->isRetryable() || $this->attempts() >= $this->tries) {
                $recorder->markFailed($job, $e->getMessage());
                if (! $e->isRetryable()) {
                    $this->fail($e);

                    return;
                }
            }
            throw $e;
        }

        $recorder->markSucceeded($job, $result);
    }

    public function failed(?Throwable $exception): void
    {
        // El estado del training_job ya quedó registrado en handle(); sin alerta:
        // la falta de perfilado no bloquea al usuario y se reintenta en la siguiente importación.
    }

    private function resolveTrainingJob(): TrainingJob
    {
        if ($this->trainingJobId !== null) {
            $job = TrainingJob::query()->find($this->trainingJobId);
            if ($job !== null) {
                return $job;
            }
        }
        $job = TrainingJob::query()->create([
            'job_type' => TrainingJob::TYPE_READINESS,
            'status' => TrainingJob::STATUS_QUEUED,
            'triggered_by' => 'system',
            'params' => [],
            'queued_at' => now(),
        ]);
        $this->trainingJobId = $job->id;

        return $job;
    }
}
