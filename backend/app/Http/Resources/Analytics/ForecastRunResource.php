<?php

declare(strict_types=1);

namespace App\Http\Resources\Analytics;

use App\Models\ForecastRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ForecastRun */
class ForecastRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => (int) $this->version,
            'run_type' => $this->run_type,
            'status' => $this->status,
            'triggered_by' => $this->triggered_by,
            'horizon_days' => (int) $this->horizon_days,
            'frequency' => $this->frequency,
            'cutoff_date' => $this->cutoff_date?->toDateString(),
            'data_from' => $this->data_from?->toDateString(),
            'data_to' => $this->data_to?->toDateString(),
            'feature_set_version' => $this->feature_set_version,
            'strategy' => $this->strategy,
            'is_current' => (bool) $this->is_current,
            'options' => $this->options,
            'selection' => $this->selection,
            'summary' => $this->summary,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'training_job' => $this->whenLoaded('trainingJob', fn () => $this->trainingJob ? new TrainingJobResource($this->trainingJob) : null),
        ];
    }
}
