<?php

declare(strict_types=1);

namespace App\Http\Resources\Analytics;

use App\Models\Scenario;
use App\Support\Results\JsonMap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Scenario */
class ScenarioResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'forecast_run_id' => $this->forecast_run_id,
            'horizon_days' => (int) $this->horizon_days,
            'filters' => JsonMap::of($this->filters),
            'assumptions' => JsonMap::of($this->assumptions),
            'results' => JsonMap::of($this->results),
            'user_id' => $this->user_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
