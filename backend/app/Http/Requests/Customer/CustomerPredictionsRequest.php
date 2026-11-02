<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Services\Customer\CustomerS05Presenter;
use App\Support\Horizons\HorizonCatalog;
use Illuminate\Validation\Rule;

class CustomerPredictionsRequest extends CustomerRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'horizon' => ['nullable', 'integer', Rule::in(HorizonCatalog::customer())],
            'run_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', $this->existsInTenant('customers')],
            'product_id' => ['nullable', 'integer', $this->existsInTenant('products')],
            'min_probability' => ['nullable', 'numeric', 'between:0,1'],
            'order_by' => ['nullable', Rule::in(['probability', 'expected_quantity', 'expected_amount', 'quantity_if_purchase', 'expected_orders', 'expected_units'])],
            'window_status' => ['nullable', Rule::in(CustomerS05Presenter::WINDOW_STATUSES)],
            'segment' => ['nullable', 'string', 'max:60', 'regex:/^[a-z_]+:[a-z0-9_]+\z/'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
