<?php

declare(strict_types=1);

namespace App\Http\Resources\Supply;

use App\Http\Resources\Analytics\TrainingJobResource;
use App\Models\CoreRun;
use App\Support\Results\JsonMap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CoreRun */
class SupplyRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $job = $this->relationLoaded('trainingJobs') ? $this->trainingJobs->sortByDesc('id')->first() : null;

        return [
            'id' => $this->id,
            'core' => $this->core,
            'operation' => $this->operation,
            'version' => (int) $this->version,
            'run_type' => $this->run_type,
            'status' => $this->status,
            'result_type' => $this->result_type,
            'quality_status' => $this->quality_status,
            'uncertainty_source' => $this->uncertainty_source,
            'as_of' => $this->as_of?->toDateString(),
            'horizon_start' => $this->horizon_start?->toDateString(),
            'horizon_end' => $this->horizon_end?->toDateString(),
            // Mapa de opciones de la solicitud: objeto JSON también cuando está vacío.
            'options' => JsonMap::orEmpty($this->options),
            'config_version' => $this->config_version,
            'data_snapshot_id' => $this->data_snapshot_id,
            'summary' => JsonMap::of($this->summary),
            'assumptions' => $this->assumptions ?? [],
            'limitations' => $this->limitations ?? [],
            'is_current' => (bool) $this->is_current,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'training_job' => $job ? (new TrainingJobResource($job))->resolve($request) : null,
        ];
    }
}
