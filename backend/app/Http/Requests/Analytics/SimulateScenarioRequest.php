<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use Illuminate\Validation\Rule;

/** Supuestos del simulador con rangos acotados (docs/06 §3.11). */
class SimulateScenarioRequest extends AnalyticsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->runRule(),
            'horizon_days' => ['required', 'integer', Rule::in($this->horizons())],
            'filters' => ['nullable', 'array'],
            'filters.branch_id' => ['nullable', 'integer', $this->existsInTenant('branches')],
            'filters.category_id' => ['nullable', 'integer', $this->existsInTenant('product_categories')],
            'filters.product_id' => ['nullable', 'integer', $this->existsInTenant('products')],
            // Sin supuestos = escenario idéntico a la base (útil como punto de partida).
            'assumptions' => ['nullable', 'array'],
            'assumptions.price_change_pct' => ['nullable', 'numeric', 'between:-0.5,1'],
            'assumptions.discount_pct' => ['nullable', 'numeric', 'between:0,0.9'],
            'assumptions.cost_change_pct' => ['nullable', 'numeric', 'between:-0.5,1'],
            'assumptions.demand_change_pct' => ['nullable', 'numeric', 'between:-0.9,2'],
            'assumptions.lead_time_days' => ['nullable', 'integer', 'between:1,180'],
            'assumptions.purchase_budget' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'assumptions.macro_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array{branch_id: int|null, category_id: int|null, product_id: int|null} */
    public function scenarioFilters(): array
    {
        $filters = (array) ($this->validated('filters') ?? []);

        return [
            'branch_id' => isset($filters['branch_id']) ? (int) $filters['branch_id'] : null,
            'category_id' => isset($filters['category_id']) ? (int) $filters['category_id'] : null,
            'product_id' => isset($filters['product_id']) ? (int) $filters['product_id'] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function assumptions(): array
    {
        return array_filter((array) ($this->validated('assumptions') ?? []), fn ($v) => $v !== null);
    }
}
