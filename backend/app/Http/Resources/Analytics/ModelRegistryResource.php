<?php

declare(strict_types=1);

namespace App\Http\Resources\Analytics;

use App\Models\ModelRegistry;
use App\Services\Analytics\Support\ModelCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ModelRegistry */
class ModelRegistryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'model_family' => $this->model_family,
            'label' => ModelCatalog::label($this->model_family),
            'strategy_level' => $this->strategy_level,
            'scope' => $this->scope,
            'status' => $this->status,
            'demand_class' => $this->demand_class,
            'feature_set_version' => $this->feature_set_version,
            'hyperparameters' => $this->hyperparameters,
            'training_data_from' => $this->training_data_from?->toDateString(),
            'training_data_to' => $this->training_data_to?->toDateString(),
            'training_data_hash' => $this->training_data_hash,
            'series_count' => (int) $this->series_count,
            'library_versions' => $this->library_versions,
            'random_seed' => $this->random_seed,
            'forecast_run_id' => $this->forecast_run_id,
            'trained_at' => $this->trained_at?->toIso8601String(),
            'promoted_at' => $this->promoted_at?->toIso8601String(),
            'retired_at' => $this->retired_at?->toIso8601String(),
        ];
    }
}
