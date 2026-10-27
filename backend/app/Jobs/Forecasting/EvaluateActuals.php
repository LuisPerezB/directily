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

/**
 * Compara pronósticos pasados con ventas reales y detecta deterioro (docs/06 §4.3).
 */
class EvaluateActuals implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 720;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $tenantId,
        public int $lookbackRuns = 10,
        public string $triggeredBy = 'schedule',
    ) {
        $this->onQueue((string) config('engine.queue', 'forecasting'));
    }

    public function uniqueId(): string
    {
        return 'evaluate-actuals:'.$this->tenantId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [120];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetTenantContext()];
    }

    public function handle(EngineClient $engine, TrainingJobRecorder $recorder): void
    {
        $job = TrainingJob::query()->create([
            'job_type' => TrainingJob::TYPE_EVALUATE_ACTUALS,
            'status' => TrainingJob::STATUS_QUEUED,
            'triggered_by' => $this->triggeredBy,
            'params' => ['lookback_runs' => $this->lookbackRuns],
            'queued_at' => now(),
        ]);
        $recorder->markAttempt($job);
        $recorder->markRunning($job, 'evaluating');

        try {
            $result = $engine->evaluateActuals($this->tenantId, $job->id, $this->lookbackRuns);
        } catch (EngineException $e) {
            $recorder->markFailed($job, $e->getMessage());
            if (! $e->isRetryable()) {
                $this->fail($e);

                return;
            }
            throw $e;
        }

        $recorder->markSucceeded($job, $result);
    }
}
