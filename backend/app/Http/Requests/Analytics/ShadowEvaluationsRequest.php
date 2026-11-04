<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Http\Requests\Analytics\Concerns\NormalizesBooleanQuery;
use App\Support\Horizons\HorizonCatalog;
use Illuminate\Validation\Rule;

/** GET /models/shadow-evaluations (V2.5 · W3-D): evaluación prospectiva del challenger (shadow) contra lo realizado después del corte. */
class ShadowEvaluationsRequest extends AnalyticsRequest
{
    use NormalizesBooleanQuery;

    /** @return list<string> sin filtros booleanos hoy; la normalización queda por si se agregan */
    protected function booleanFilters(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'run_id' => ['nullable', 'integer', 'min:1'],
            'horizon' => ['nullable', 'integer', Rule::in(HorizonCatalog::all())],
            'level' => ['nullable', Rule::in(['overall', 'horizon'])],
            'model_family' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_]+\z/'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
