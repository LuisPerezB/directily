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
            // `shadow_prospective` y `segment` (V2.5 · W3) solo salen si se piden: sin filtro, la respuesta es la de siempre (ModelEvaluation::scopeClassicView).
            'evaluation_type' => ['nullable', Rule::in(['backtest', 'selection_windows', 'realized', 'shadow_prospective'])],
            'level' => ['nullable', Rule::in(['overall', 'demand_class', 'horizon', 'horizon_total', 'branch', 'category', 'product', 'series', 'total', 'segment'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'delimiter' => ['nullable', Rule::in([',', ';'])],
        ];
    }
}
