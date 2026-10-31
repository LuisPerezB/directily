<?php

declare(strict_types=1);

namespace App\Http\Resources\Supply;

use App\Models\SupplyPlanShortfall;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Demanda que el plan no atiende, por sucursal, producto y período, con su causa determinista.
 *
 * @mixin SupplyPlanShortfall
 */
class SupplyPlanShortfallResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'branch' => $this->branch ? ['id' => $this->branch->id, 'code' => $this->branch->code, 'name' => $this->branch->name] : null,
            'product_id' => $this->product_id,
            'product' => $this->product ? ['id' => $this->product->id, 'sku' => $this->product->sku, 'name' => $this->product->name] : null,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'demand' => $this->demand,
            'unmet' => $this->unmet,
            'reason' => $this->reason,
        ];
    }
}
