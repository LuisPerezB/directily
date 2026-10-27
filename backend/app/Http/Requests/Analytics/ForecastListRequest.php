<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Services\Analytics\ProductForecastQuery;
use App\Services\Analytics\Support\Granularity;
use Illuminate\Validation\Rule;

/** Listados de pronóstico por producto/categoría y su exportación. */
class ForecastListRequest extends AnalyticsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->runRule(),
            'horizon' => ['nullable', 'integer', Rule::in($this->horizons())],
            'branch_id' => ['nullable', 'integer', $this->existsInTenant('branches')],
            'category_id' => ['nullable', 'integer', $this->existsInTenant('product_categories')],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(ProductForecastQuery::sorts())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'granularity' => ['nullable', Rule::in(Granularity::ALL)],
            'delimiter' => ['nullable', Rule::in([',', ';'])],
        ];
    }
}
