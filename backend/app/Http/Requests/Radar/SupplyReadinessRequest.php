<?php

declare(strict_types=1);

namespace App\Http\Requests\Radar;

use App\Support\Horizons\HorizonCatalog;
use Illuminate\Validation\Rule;

/** Preparación de abastecimiento por producto: horizonte, corte del inventario y filtros de producto y categoría. */
class SupplyReadinessRequest extends RadarRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['nullable', 'integer', $this->existsInTenant('products')],
            'category_id' => ['nullable', 'integer', $this->existsInTenant('product_categories')],
            'horizon' => ['nullable', 'integer', Rule::in(HorizonCatalog::demand())],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
