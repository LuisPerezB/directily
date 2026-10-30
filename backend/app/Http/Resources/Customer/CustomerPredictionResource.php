<?php

declare(strict_types=1);

namespace App\Http\Resources\Customer;

use App\Models\CustomerPrediction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerPrediction */
class CustomerPredictionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'customer' => ['id' => (int) $this->customer_id, 'code' => $this->customer?->external_code],
            'product' => $this->product ? ['id' => (int) $this->product_id, 'sku' => $this->product->sku, 'name' => $this->product->name, 'unit' => $this->product->unit]
                : ['id' => (int) $this->product_id],
            'horizon_days' => (int) $this->horizon_days,
            'as_of' => $this->as_of?->toDateString(),
            'purchase_probability' => $this->purchase_probability,
            'calibration_status' => $this->calibration_status,
            'probability_coherence_adjusted' => (bool) $this->probability_coherence_adjusted,
            'quantity_if_purchase' => $this->quantity_if_purchase,
            'quantity_interval' => ['lo' => $this->quantity_lo, 'hi' => $this->quantity_hi, 'level' => 0.8, 'method' => 'conformal multiplicativo (calibración)'],
            'expected_quantity' => $this->expected_quantity,
            'committed_quantity' => $this->committed_quantity,
            'incremental_quantity' => $this->incremental_quantity,
            'total_view_quantity' => $this->total_view_quantity,
            'expected_amount' => [
                'value' => $this->expected_amount, 'currency' => $this->currency, 'assumed_unit_price' => $this->assumed_unit_price,
                'basis' => 'supuesto: esperanza × último precio neto de la relación; no es una venta confirmada',
            ],
            'median_interval_days' => $this->median_interval_days,
            'days_since_last_purchase' => $this->days_since_last_purchase,
            'n_purchases' => $this->n_purchases,
            'model' => ['family' => $this->model_family, 'role' => $this->model_role],
            'quality_flags' => $this->quality_flags ?? [],
        ];
    }
}
