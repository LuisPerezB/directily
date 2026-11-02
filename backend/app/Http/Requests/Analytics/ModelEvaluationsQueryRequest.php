<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Http\Requests\Analytics\Concerns\NormalizesBooleanQuery;
use App\Support\Horizons\HorizonCatalog;
use Illuminate\Validation\Rule;

/**
 * GET /models/evaluations (V2.5 · W3-D): explorador de las evaluaciones de calidad de los modelos (Demand y Customer), incluidas las filas por
 * segmento y la evaluación prospectiva del shadow que las vistas clásicas no muestran. Solo lectura; todo filtro es opcional.
 */
class ModelEvaluationsQueryRequest extends AnalyticsRequest
{
    use NormalizesBooleanQuery;

    /** Dimensiones de segmento: Demand (W3-A) y Customer (W3-C). */
    public const DIMENSIONS = ['demand_class', 'category', 'branch', 'volume_tier', 'series_age', 'frequency', 'value', 'tenure', 'history'];

    /** @return list<string> */
    protected function booleanFilters(): array
    {
        return ['is_selected', 'is_baseline'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'core' => ['nullable', Rule::in(['demand', 'customer'])],
            'run_id' => ['nullable', 'integer', 'min:1'],
            'core_run_id' => ['nullable', 'integer', 'min:1'],
            'horizon' => ['nullable', 'integer', Rule::in(HorizonCatalog::all())],
            'evaluation_type' => ['nullable', 'string', 'max:20', 'regex:/^[a-z_]+\z/'],
            'level' => ['nullable', Rule::in(['overall', 'demand_class', 'horizon', 'horizon_total', 'branch', 'category', 'product', 'series', 'total', 'segment'])],
            'dimension' => ['nullable', Rule::in(self::DIMENSIONS)],
            'level_key' => ['nullable', 'string', 'max:80'],
            'model_family' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_]+\z/'],
            'is_selected' => ['nullable', 'boolean'],
            'is_baseline' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['ok', 'insufficient_data', 'calibrated', 'miscalibrated', 'validated', 'not_validated'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
