<?php

declare(strict_types=1);

namespace App\Services\Forecasting;

use App\Models\Alert;
use App\Models\ForecastRun;
use App\Models\TrainingJob;
use Carbon\CarbonImmutable;

/**
 * Transiciones de estado de training_jobs / forecast_runs del lado de Laravel.
 * El motor actualiza el progreso fino; Laravel registra inicio de intentos,
 * fallos de comunicación y cierres defensivos (si el motor no llegó a marcarlos).
 */
class TrainingJobRecorder
{
    public function markAttempt(TrainingJob $job): void
    {
        $job->forceFill([
            'attempts' => $job->attempts + 1,
            'started_at' => $job->started_at ?? CarbonImmutable::now(),
        ])->save();
    }

    public function markRunning(TrainingJob $job, ?string $stage = null): void
    {
        $job->forceFill([
            'status' => TrainingJob::STATUS_RUNNING,
            'stage' => $stage ?? $job->stage,
            'started_at' => $job->started_at ?? CarbonImmutable::now(),
        ])->save();
    }

    /** @param array<string, mixed> $result */
    public function markSucceeded(TrainingJob $job, array $result): void
    {
        $job->refresh();
        if ($job->status === TrainingJob::STATUS_SUCCEEDED && $job->result !== null) {
            return; // el motor ya lo cerró
        }
        $finished = CarbonImmutable::now();
        $job->forceFill([
            'status' => TrainingJob::STATUS_SUCCEEDED,
            'progress' => 100,
            'result' => $job->result ?? $result,
            'finished_at' => $job->finished_at ?? $finished,
            'duration_ms' => $job->duration_ms ?? $this->durationMs($job, $finished),
            'error_message' => null,
        ])->save();
    }

    public function markFailed(TrainingJob $job, string $message): void
    {
        $job->refresh();
        if ($job->status === TrainingJob::STATUS_SUCCEEDED) {
            return;
        }
        $finished = CarbonImmutable::now();
        $job->forceFill([
            'status' => TrainingJob::STATUS_FAILED,
            'error_message' => mb_substr($message, 0, 2000),
            'finished_at' => $finished,
            'duration_ms' => $this->durationMs($job, $finished),
        ])->save();
    }

    public function markRunFailed(ForecastRun $run, string $message): void
    {
        $run->refresh();
        if ($run->status === ForecastRun::STATUS_COMPLETED) {
            return;
        }
        $run->forceFill([
            'status' => ForecastRun::STATUS_FAILED,
            'error_message' => mb_substr($message, 0, 2000),
            'completed_at' => CarbonImmutable::now(),
            'is_current' => false,
        ])->save();

        Alert::raise([
            'type' => 'run_failed',
            'severity' => 'warning',
            'title' => "El pronóstico v{$run->version} no pudo completarse",
            'message' => $message,
            'payload' => ['forecast_run_id' => $run->id, 'version' => $run->version],
            'related_type' => 'forecast_run',
            'related_id' => $run->id,
            'dedup_key' => "run_failed:{$run->id}",
        ]);
    }

    private function durationMs(TrainingJob $job, CarbonImmutable $finished): ?int
    {
        return $job->started_at ? (int) max(0, $job->started_at->diffInMilliseconds($finished, true)) : null;
    }
}
