<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use Illuminate\Validation\Rule;

class StoreCustomerRunRequest extends CustomerRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operation' => ['required', Rule::in(['train', 'predict'])],
            'horizons' => ['nullable', 'array', 'min:1', 'max:3'],
            'horizons.*' => ['integer', Rule::in([7, 30, 90])],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'operation.in' => 'La operación debe ser train (entrenar) o predict (predecir).',
            'horizons.*.in' => 'Los horizontes de comportamiento de compra son 7, 30 y 90 días.',
        ];
    }
}
