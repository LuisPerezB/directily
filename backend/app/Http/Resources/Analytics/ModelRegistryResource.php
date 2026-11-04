<?php

declare(strict_types=1);

namespace App\Http\Resources\Analytics;

use App\Models\ModelRegistry;
use App\Services\Analytics\Support\ModelCatalog;
use App\Services\Analytics\Support\ModelStatus;
use App\Services\Models\ModelServingPolicy;
use App\Support\Results\JsonMap;
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
            'legacy_status' => ModelStatus::legacy($this->status, $this->role),
            'core' => $this->core,
            'role' => $this->role,
            'demand_class' => $this->demand_class,
            'feature_set_version' => $this->feature_set_version,
            'hyperparameters' => JsonMap::of($this->hyperparameters),
            'training_data_from' => $this->training_data_from?->toDateString(),
            'training_data_to' => $this->training_data_to?->toDateString(),
            'training_data_hash' => $this->training_data_hash,
            'series_count' => (int) $this->series_count,
            'library_versions' => JsonMap::of($this->library_versions),
            'random_seed' => $this->random_seed,
            'forecast_run_id' => $this->forecast_run_id,
            'artifact_sha256' => $this->artifact_sha256,
            'artifact_compat' => JsonMap::of($this->artifact_compat),
            'validated_at' => $this->validated_at?->toIso8601String(),
            'trained_at' => $this->trained_at?->toIso8601String(),
            'promoted_at' => $this->promoted_at?->toIso8601String(),
            'retired_at' => $this->retired_at?->toIso8601String(),
            // V2.5 · W4 (ADR-V25-07): ciclo de vida, publicación y aprobación separada. Sin `published_object_key` (clave interna del almacén).
            'purpose' => $this->purpose,
            'granularity' => $this->granularity,
            'previous_model_registry_id' => $this->previous_model_registry_id,
            'promotion_decision_id' => $this->promotion_decision_id,
            'published_by' => $this->published_by,
            'published_env' => $this->published_env,
            'published_at' => $this->published_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_reason' => $this->rejected_reason,
            'serving_basis' => $this->servingBasis ?? app(ModelServingPolicy::class)->basis($this->resource),
        ];
    }
}
