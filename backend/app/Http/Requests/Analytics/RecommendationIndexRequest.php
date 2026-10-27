<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Services\Analytics\InventoryQuery;
use Illuminate\Validation\Rule;

class RecommendationIndexRequest extends AnalyticsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->runRule(),
            'branch_id' => ['nullable', 'integer', $this->existsInTenant('branches')],
            'category_id' => ['nullable', 'integer', $this->existsInTenant('product_categories')],
            'risk_level' => ['nullable'],
            'risk_level.*' => [Rule::in(['low', 'medium', 'high', 'critical'])],
            'type' => ['nullable', Rule::in(['reorder', 'stockout_risk', 'overstock', 'no_action'])],
            'status' => ['nullable', Rule::in(['open', 'acknowledged', 'dismissed', 'ordered'])],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(InventoryQuery::sorts())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'delimiter' => ['nullable', Rule::in([',', ';'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Acepta risk_level=high o risk_level=high,critical o risk_level[]=high.
        $risk = $this->query('risk_level');
        if (is_string($risk) && $risk !== '') {
            $this->merge(['risk_level' => array_values(array_filter(explode(',', $risk)))]);
        }
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'branch_id' => $this->intOrNull('branch_id'),
            'category_id' => $this->intOrNull('category_id'),
            'risk_level' => $this->validated('risk_level'),
            'type' => $this->validated('type'),
            'status' => $this->validated('status'),
            'search' => $this->validated('search'),
        ];
    }
}
