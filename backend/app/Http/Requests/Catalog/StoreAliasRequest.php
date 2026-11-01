<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Models\ProductAlias;
use App\Support\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta de un alias de producto o de cliente. La fuente debe ser de la misma empresa; la vigencia incluye ambos extremos. */
final class StoreAliasRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source_code' => ['required', 'string', 'max:100'],
            'data_source_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('data_sources')->whereNull('deleted_at')],
            'kind' => ['sometimes', 'string', Rule::in(ProductAlias::KINDS)],
            'valid_from' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1990-01-01'],
            'valid_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from', 'before_or_equal:2100-12-31'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'source_code.required' => 'Indique el código de origen del alias.',
            'valid_to.after_or_equal' => 'La vigencia no puede terminar antes de empezar.',
            'data_source_id.exists' => 'La fuente de datos no existe en la empresa.',
        ];
    }
}
