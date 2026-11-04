<?php

declare(strict_types=1);

namespace App\Http\Requests\Radar;

use App\Support\Horizons\HorizonCatalog;
use Illuminate\Validation\Rule;

/** Consultas de la ficha del comprador por horizonte (demanda, productos y ficha). */
class CustomerHorizonRequest extends RadarRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'horizon' => ['nullable', 'integer', Rule::in(HorizonCatalog::customer())],
            'run_id' => ['nullable', 'integer', 'min:1'],
            'kind' => ['nullable', Rule::in(['habitual', 'probable'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
