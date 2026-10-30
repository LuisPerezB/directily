<?php

declare(strict_types=1);

namespace App\Http\Resources\Analytics;

use App\Models\ModelEvaluation;
use App\Services\Analytics\Support\ModelCatalog;
use App\Services\Analytics\Support\Num;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ModelEvaluation */
class ModelEvaluationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'forecast_run_id' => $this->forecast_run_id,
            'model_registry_id' => $this->model_registry_id,
            'model_family' => $this->model_family,
            'label' => ModelCatalog::label($this->model_family),
            'evaluation_type' => $this->evaluation_type,
            'level' => $this->level,
            'level_key' => $this->level_key,
            'horizon_days' => $this->horizon_days !== null ? (int) $this->horizon_days : null,
            'is_baseline' => (bool) $this->is_baseline,
            'is_selected' => (bool) $this->is_selected,
            'wape' => Num::ratio($this->wape),
            'mae' => Num::units($this->mae),
            'rmse' => Num::units($this->rmse),
            'bias' => Num::ratio($this->bias),
            'coverage_80' => Num::ratio($this->coverage_80),
            'coverage_95' => Num::ratio($this->coverage_95),
            'n_obs' => (int) $this->n_obs,
            'metrics' => $this->metrics,
            'n_windows' => (int) $this->n_windows,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
