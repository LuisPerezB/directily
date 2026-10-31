<?php

declare(strict_types=1);

namespace App\Http\Resources\Analytics;

use App\Models\ForecastRun;
use App\Services\Analytics\DemandEnvelope;
use App\Support\Results\JsonMap;
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
            'options' => JsonMap::orEmpty($this->options),
            'selection' => JsonMap::of($this->selection),
            'summary' => JsonMap::of($this->summary),
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'training_job' => $this->whenLoaded('trainingJob', fn () => $this->trainingJob ? new TrainingJobResource($this->trainingJob) : null),
            // Etapa 2 (aditivo): huella, configuración efectiva y manifiesto del protocolo de evaluación.
            'config_version' => $this->config_version,
            'data_snapshot_id' => $this->data_snapshot_id !== null ? (int) $this->data_snapshot_id : null,
            'effective_config' => JsonMap::of($this->effective_config),
            // El manifiesto es voluminoso: solo en el detalle (GET /forecast-runs/{id}); también en /accuracy/summary.
            'evaluation_manifest' => $this->when($request->routeIs('analytics.forecast-runs.show'), fn () => JsonMap::of($this->evaluation_manifest)),
            'envelope' => app(DemandEnvelope::class)->for($this->resource),
        ];
    }
}
