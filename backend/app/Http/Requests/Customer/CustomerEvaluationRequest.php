<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Support\Horizons\HorizonCatalog;
use Illuminate\Validation\Rule;

class CustomerEvaluationRequest extends CustomerRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'horizon' => ['nullable', 'integer', Rule::in(HorizonCatalog::customer())],
            'run_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
