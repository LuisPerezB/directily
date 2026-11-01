<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use Illuminate\Validation\Rule;

class CustomerReconciliationRequest extends CustomerRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'horizon' => ['nullable', 'integer', Rule::in([7, 30, 90])],
            'run_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', $this->existsInTenant('products')],
            'status' => ['nullable', Rule::in(['ok', 'inconsistent', 'aggregate_unavailable', 'aggregate_incomplete'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
