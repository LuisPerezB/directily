<?php

declare(strict_types=1);

namespace App\Http\Resources\Core;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Producto. Las estadísticas vienen de columnas agregadas units_90d / revenue_90d /
 * last_sale_date (consulta con JOIN o adjuntadas por el controlador).
 *
 * @mixin \App\Models\Product
 */
final class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();
        $stats = (array_key_exists('units_90d', $attributes) ? [
            'units_90d' => round((float) $attributes['units_90d'], 4),
            'revenue_90d' => round((float) $attributes['revenue_90d'], 2),
            'last_sale_date' => $attributes['last_sale_date'],
        ] : null);

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'unit' => $this->unit,
            'brand' => $this->brand,
            'category' => $this->relationLoaded('category') && $this->category
                ? ['id' => $this->category->id, 'name' => $this->category->name]
                : null,
            'current_price' => $this->current_price,
            'current_cost' => $this->current_cost,
            'is_active' => (bool) $this->is_active,
            'stats' => $stats ?? ['units_90d' => 0.0, 'revenue_90d' => 0.0, 'last_sale_date' => null],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
