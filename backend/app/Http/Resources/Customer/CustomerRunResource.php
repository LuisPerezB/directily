<?php

declare(strict_types=1);

namespace App\Http\Resources\Customer;

use App\Http\Resources\Analytics\TrainingJobResource;
use App\Models\CoreRun;
use App\Support\Results\ResultEnvelope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CoreRun */
class CustomerRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $job = $this->relationLoaded('trainingJobs') ? $this->trainingJobs->sortByDesc('id')->first() : null;

        return [
            'id' => $this->id,
            'operation' => $this->operation,
            'version' => (int) $this->version,
            'run_type' => $this->run_type,
            'status' => $this->status,
            'quality_status' => $this->quality_status,
            'result_type' => $this->result_type,
            'triggered_by' => $this->triggered_by,
            'as_of' => $this->as_of?->toDateString(),
            'horizons' => $this->horizons,
            'is_current' => (bool) $this->is_current,
            'config_version' => $this->config_version,
            'data_snapshot_id' => $this->data_snapshot_id !== null ? (int) $this->data_snapshot_id : null,
            'model_versions' => $this->model_versions,
            'summary' => $this->summary,
            'assumptions' => $this->assumptions ?? [],
            'limitations' => $this->limitations ?? [],
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'envelope' => ResultEnvelope::fromCoreRun($this->resource),
            'training_job' => $job ? new TrainingJobResource($job) : null,
        ];
    }
}
