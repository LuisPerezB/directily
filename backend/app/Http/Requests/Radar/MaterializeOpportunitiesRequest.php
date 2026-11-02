<?php

declare(strict_types=1);

namespace App\Http\Requests\Radar;

use App\Support\Horizons\HorizonCatalog;
use Illuminate\Validation\Rule;

/** Propone al Decision Engine las oportunidades de la predicción vigente (opcionalmente solo de algunos horizontes). */
class MaterializeOpportunitiesRequest extends RadarRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'horizons' => ['nullable', 'array', 'min:1', 'max:6'],
            'horizons.*' => ['integer', Rule::in(HorizonCatalog::customer())],
        ];
    }
}
