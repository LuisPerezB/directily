<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Support\Horizons\HorizonCatalog;
use Illuminate\Validation\Rule;

class StoreCustomerRunRequest extends CustomerRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operation' => ['required', Rule::in(['train', 'predict'])],
            'horizons' => ['nullable', 'array', 'min:1', 'max:'.count(HorizonCatalog::customer())],
            'horizons.*' => ['integer', Rule::in(HorizonCatalog::customer())],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'operation.in' => 'La operación debe ser train (entrenar) o predict (predecir).',
            'horizons.*.in' => 'Los horizontes de comportamiento de compra son '.implode(', ', HorizonCatalog::customer()).' días.',
        ];
    }
}
