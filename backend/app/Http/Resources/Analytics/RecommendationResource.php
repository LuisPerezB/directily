<?php

declare(strict_types=1);

namespace App\Http\Resources\Analytics;

use App\Models\Recommendation;
use App\Services\Analytics\Support\Num;
use App\Support\Results\JsonMap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Recommendation */
class RecommendationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'forecast_run_id' => $this->forecast_run_id,
            'type' => $this->type,
            'branch_id' => $this->branch_id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id, 'sku' => $this->product->sku, 'name' => $this->product->name,
                'unit' => $this->product->unit, 'category_id' => $this->product->category_id,
            ] : null),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id, 'code' => $this->branch->code, 'name' => $this->branch->name,
            ] : null),
            'inventory_snapshot_date' => $this->inventory_snapshot_date?->toDateString(),
            'on_hand' => Num::units($this->on_hand),
            'reserved' => Num::units($this->reserved),
            'in_transit' => Num::units($this->in_transit),
            'available' => Num::units($this->available),
            'lead_time_days' => (int) $this->lead_time_days,
            'lead_time_source' => $this->lead_time_source,
            'review_period_days' => (int) $this->review_period_days,
            'service_level' => Num::ratio($this->service_level),
            'demand_lead_time' => Num::units($this->demand_lead_time),
            'demand_lead_time_std' => Num::units($this->demand_lead_time_std),
            'safety_stock' => Num::units($this->safety_stock),
            'reorder_point' => Num::units($this->reorder_point),
            'suggested_qty' => Num::units($this->suggested_qty),
            'unit_cost' => Num::money($this->unit_cost),
            'estimated_investment' => Num::money($this->estimated_investment),
            'days_of_cover' => Num::units($this->days_of_cover),
            'stockout_probability' => Num::ratio($this->stockout_probability),
            'stockout_date_estimate' => $this->stockout_date_estimate?->toDateString(),
            'risk_level' => $this->risk_level,
            'status' => $this->status,
            'assumptions' => JsonMap::of($this->assumptions),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
