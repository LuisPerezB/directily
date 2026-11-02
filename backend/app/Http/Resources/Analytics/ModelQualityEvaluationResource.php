<?php

declare(strict_types=1);

namespace App\Http\Resources\Analytics;

use App\Models\ModelEvaluation;
use App\Services\Analytics\ModelQuality\EvaluationPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Evaluación de calidad de un modelo (V2.5 · W3-D): la fila de `model_evaluations` con su segmento, denominador y cobertura explícitos, la marca
 * de WAPE inestable y su métrica alternativa, la calibración por segmento del Customer Core y la evaluación prospectiva del shadow. Distinta de
 * ModelEvaluationResource (la vista clásica de GET /model-evaluations, que no cambia).
 *
 * @mixin ModelEvaluation
 */
class ModelQualityEvaluationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $min = $request->attributes->get('wape_min_denominator');

        return EvaluationPresenter::row($this->resource, is_numeric($min) ? (float) $min : null);
    }
}
