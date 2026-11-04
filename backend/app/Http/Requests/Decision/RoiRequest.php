<?php

declare(strict_types=1);

namespace App\Http\Requests\Decision;

use App\Models\DecisionRecommendation;
use App\Services\Outcomes\RoiReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtros de GET /decisions/roi (V2.5 · W5-C). El periodo filtra por el corte (`as_of`) de la recomendación. */
class RoiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['nullable', 'string', 'regex:/^[a-z_]+(,[a-z_]+)*$/'],
            'horizon_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'experiment_key' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group_by' => ['nullable', Rule::in(RoiReport::GROUPS)],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $kinds = array_values(array_intersect(array_filter(explode(',', (string) $this->query('kind', ''))), DecisionRecommendation::KINDS));

        return [
            // Un valor desconocido en `kind` no se descarta en silencio hasta «todos»: devuelve un informe vacío.
            'kind' => $this->filled('kind') ? ($kinds === [] ? ['__none__'] : $kinds) : null,
            'horizon_days' => $this->filled('horizon_days') ? (int) $this->query('horizon_days') : null,
            'experiment_key' => $this->filled('experiment_key') ? (string) $this->query('experiment_key') : null,
            // Sin periodo, el informe toma los últimos 180 días del corte; el máximo es de 730 días (422 si se supera): ver RoiReport::period().
            'from' => $this->filled('from') ? (string) $this->query('from') : null,
            'to' => $this->filled('to') ? (string) $this->query('to') : null,
            'group_by' => $this->filled('group_by') ? (string) $this->query('group_by') : null,
        ];
    }
}
