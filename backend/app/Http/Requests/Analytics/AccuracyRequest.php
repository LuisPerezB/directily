<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use Illuminate\Validation\Rule;

/** Consultas de precisión: resumen, comparación, evaluaciones y exportación. */
class AccuracyRequest extends AnalyticsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $runRequired = $this->routeIs('analytics.accuracy.comparison', 'analytics.model-evaluations.index');

        return [
            ...$this->runRule($runRequired),
            ...$this->nodeRules(),
            'evaluation_type' => ['nullable', Rule::in(['backtest', 'selection_windows', 'realized'])],
            'level' => ['nullable', Rule::in(['overall', 'demand_class', 'horizon', 'branch', 'category', 'product', 'series'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'delimiter' => ['nullable', Rule::in([',', ';'])],
        ];
    }
}
