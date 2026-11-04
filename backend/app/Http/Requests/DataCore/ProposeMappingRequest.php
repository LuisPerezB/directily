<?php

declare(strict_types=1);

namespace App\Http\Requests\DataCore;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Entrada de POST /data-sources/{id}/mapping-proposals: qué entidades proponer y si se leen muestras estadísticas acotadas. */
class ProposeMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'entities' => ['sometimes', 'array', 'min:1', 'max:'.count((array) config('mapping.entities'))],
            'entities.*' => ['string', 'distinct', Rule::in((array) config('mapping.entities'))],
            'sample' => ['sometimes', 'boolean'],
            'system_kind' => ['sometimes', 'string', Rule::in((array) config('mapping.system_kinds'))],
            'vendor' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._\-]*$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'entities.*.in' => 'Entidad no soportada por el Auto Mapper. Use una de: '.implode(', ', (array) config('mapping.entities')).'. Los clientes se proponen dentro de las ventas.',
        ];
    }
}
