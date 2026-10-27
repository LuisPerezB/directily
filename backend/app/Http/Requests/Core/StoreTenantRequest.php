<?php

declare(strict_types=1);

namespace App\Http\Requests\Core;

use App\Enums\Sector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTenantRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'tax_id' => ['nullable', 'string', 'regex:/^\d{13}$/'],
            'sector' => ['required', Rule::in(Sector::values())],
            'timezone' => ['nullable', 'timezone:all'],
            'currency' => ['nullable', Rule::in(['USD'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['tax_id.regex' => 'El RUC debe tener 13 dígitos.', 'currency.in' => 'Por ahora solo se admite USD.'];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nombre', 'legal_name' => 'razón social', 'tax_id' => 'RUC', 'sector' => 'sector', 'timezone' => 'zona horaria'];
    }
}
