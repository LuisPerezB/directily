<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/** Cambio de código de un producto (SKU) o de un cliente. `effective_from` no puede ser futura: no existen cambios programados. */
final class ChangeCodeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'old_code' => ['required', 'string', 'max:100'],
            'new_code' => ['required', 'string', 'max:100'],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:1990-01-01', 'before_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['effective_from.before_or_equal' => 'La fecha de vigencia del cambio de código no puede ser futura.'];
    }
}
