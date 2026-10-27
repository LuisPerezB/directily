<?php

declare(strict_types=1);

namespace App\Http\Requests\Core;

use App\Services\Imports\ImportEntity;
use App\Support\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreImportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(int) config('predictily.imports.max_file_size_kb', 20480), 'extensions:csv,txt,tsv,xlsx'],
            'entity_type' => ['required', Rule::in(ImportEntity::values())],
            'data_source_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('data_sources')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.max' => 'El archivo supera el tamaño máximo permitido (20 MB).',
            'file.extensions' => 'Formato no soportado. Use CSV o XLSX.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['file' => 'archivo', 'entity_type' => 'tipo de datos'];
    }
}
