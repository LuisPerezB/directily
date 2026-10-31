<?php

declare(strict_types=1);

namespace App\Http\Resources\Supply;

use App\Models\SupplyPlanLine;
use App\Support\Results\JsonMap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Línea de un plan de reposición (sugerencia de compra o producción). Misma forma en el detalle del plan (vista previa)
 * y en `GET /supply/plans/{id}/lines` (paginado).
 *
 * @mixin SupplyPlanLine
 */
class SupplyPlanLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_type' => $this->line_type,
            'branch_id' => $this->branch_id,
            'branch' => $this->branch ? ['id' => $this->branch->id, 'code' => $this->branch->code, 'name' => $this->branch->name] : null,
            'product_id' => $this->product_id,
            'product' => $this->product ? ['id' => $this->product->id, 'sku' => $this->product->sku, 'name' => $this->product->name] : null,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->supplier ? ['id' => $this->supplier->id, 'code' => $this->supplier->code, 'name' => $this->supplier->name] : null,
            'offer_id' => $this->offer_id,
            'recipe_id' => $this->recipe_id,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'offer_quantity' => $this->offer_quantity,
            'offer_unit' => $this->offer_unit,
            'order_date' => $this->order_date?->toDateString(),
            'expected_receipt_date' => $this->expected_receipt_date?->toDateString(),
            'unit_cost' => $this->unit_cost,
            'line_cost' => $this->line_cost,
            'currency' => $this->currency,
            // Fecha de emisión = fecha de corte del plan (`as_of`), NO necesariamente hoy: ver `plan.as_of_age_days`.
            'actionable_now' => (bool) $this->actionable_now,
            'binding_constraints' => $this->binding_constraints ?? [],
            'justification' => $this->justification,
            'assumptions' => JsonMap::of($this->assumptions),
        ];
    }
}
