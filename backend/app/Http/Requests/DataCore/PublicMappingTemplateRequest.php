<?php

declare(strict_types=1);

namespace App\Http\Requests\DataCore;

use App\Services\DataCore\Mapping\Templates\SourceSpec;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta de una plantilla PÚBLICA por la plataforma (superadministrador). La estructura y los mapas se validan a fondo en el servicio. */
class PublicMappingTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'system_kind' => ['required', 'string', Rule::in((array) config('mapping.system_kinds'))],
            'vendor' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._\-]*$/'],
            'entity' => ['required', 'string', Rule::in((array) config('mapping.entities'))],
            'source_spec' => ['required', 'array'],
            'source_spec.dialect' => ['required', Rule::in(SourceSpec::DIALECTS)],
            'source_spec.tables' => ['required', 'array', 'min:1', 'max:'.SourceSpec::MAX_TABLES],
            'field_map' => ['required', 'array', 'min:1', 'max:60'],
            'value_maps' => ['sometimes', 'array', 'max:20'],
        ];
    }
}
