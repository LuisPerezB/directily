<?php

declare(strict_types=1);

namespace App\Http\Resources\Analytics;

use App\Models\TrainingJob;
use App\Support\Results\JsonMap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TrainingJob */
class TrainingJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_type' => $this->job_type,
            'status' => $this->status,
            'stage' => $this->stage,
            'progress' => (int) $this->progress,
            'attempts' => (int) $this->attempts,
            'triggered_by' => $this->triggered_by,
            'error_message' => $this->error_message,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms !== null ? (int) $this->duration_ms : null,
            'result' => JsonMap::of($this->result),
            'forecast_run_id' => $this->forecast_run_id,
            'model_registry_id' => $this->model_registry_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
