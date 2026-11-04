<?php

declare(strict_types=1);

namespace App\Http\Requests\DataCore;

use App\Models\MappingTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtros de GET /mapping-templates. */
class MappingTemplateIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope' => ['sometimes', Rule::in(['all', 'tenant', 'platform'])],
            'entity' => ['sometimes', 'string', Rule::in((array) config('mapping.entities'))],
            'status' => ['sometimes', Rule::in([MappingTemplate::STATUS_PROPOSED, MappingTemplate::STATUS_APPROVED, MappingTemplate::STATUS_RETIRED])],
            'vendor' => ['sometimes', 'string', 'max:80'],
            'system_kind' => ['sometimes', 'string', Rule::in((array) config('mapping.system_kinds'))],
            'data_source_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
