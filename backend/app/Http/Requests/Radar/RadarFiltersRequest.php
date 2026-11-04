<?php

declare(strict_types=1);

namespace App\Http\Requests\Radar;

use App\Models\DecisionRecommendation;
use App\Services\Radar\AgendaStatusMap;
use App\Services\Radar\RadarFilters;
use App\Services\Radar\RadarPolicy;
use App\Support\Horizons\HorizonCatalog;
use Illuminate\Validation\Rule;

/** Filtros compartidos del Radar y la agenda: cliente, SKU, horizonte, vendedor, segmento, zona, categoría y estado (V25-AGD-008 y -009). */
class RadarFiltersRequest extends RadarRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', $this->existsInTenant('customers')],
            'product_id' => ['nullable', 'integer', $this->existsInTenant('products')],
            'horizon' => ['nullable', 'integer', Rule::in(HorizonCatalog::customer())],
            'seller_id' => ['nullable', 'integer', Rule::exists('tenant_users', 'user_id')->where('tenant_id', $this->tenantId())],
            'segment_id' => ['nullable', 'integer', $this->existsInTenant('customer_segments')],
            'segment' => ['nullable', 'string', 'max:60', 'regex:/^[a-z_]+:[a-z0-9_]+\z/'],
            'zone' => ['nullable', 'string', 'max:80'],
            'category_id' => ['nullable', 'integer', $this->existsInTenant('product_categories')],
            'state' => ['nullable', Rule::in(AgendaStatusMap::STATES)],
            'status' => ['nullable', Rule::in(DecisionRecommendation::STATUSES)],
            'kind' => ['nullable', Rule::in(RadarPolicy::KINDS)],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'order_by' => ['nullable', Rule::in(array_keys(RadarFilters::ORDER))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filters(): RadarFilters
    {
        return RadarFilters::fromValidated($this->validated());
    }
}
