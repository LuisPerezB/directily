<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Requests\Customer\RollbackCustomerModelRequest;
use App\Services\Customer\CustomerRollbackService;
use Illuminate\Http\JsonResponse;

/** Reversión de un modelo del Customer Core a una versión validada o retirada (por horizonte y objetivo). */
class CustomerModelRollbackController extends CustomerController
{
    public function store(RollbackCustomerModelRequest $request, CustomerRollbackService $service): JsonResponse
    {
        return response()->json(['data' => $service->rollback($this->tenant(), $request->user(), (int) $request->validated('model_registry_id'))]);
    }
}
