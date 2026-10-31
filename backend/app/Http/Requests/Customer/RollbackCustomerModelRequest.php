<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

/** POST /models/customer/rollback: versión del registro (validated|retired) que se vuelve a servir. */
class RollbackCustomerModelRequest extends CustomerRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['model_registry_id' => ['required', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['model_registry_id.required' => 'Indique la versión del modelo (model_registry_id) que desea volver a servir.'];
    }
}
