<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Requests\Customer\RollbackCustomerModelRequest;
use App\Services\Auth\Mfa\MfaException;
use App\Services\Auth\Mfa\MfaStepUp;
use App\Services\Customer\CustomerRollbackService;
use App\Services\Models\Exceptions\MissingAbilityException;
use Illuminate\Http\JsonResponse;

/** Reversión de un modelo del Customer Core a una versión validada o retirada (por horizonte y objetivo). */
class CustomerModelRollbackController extends CustomerController
{
    public function store(RollbackCustomerModelRequest $request, CustomerRollbackService $service): JsonResponse
    {
        // V2.5 · W6-A: restaurar un aprobado que nunca se sirvió es PROMOVERLO (`models.approve`): con MFA activo exige un segundo factor fresco, igual que POST /models/{id}/promote.
        $stepUp = app(MfaStepUp::class);
        $roleCan = $request->attributes->get('tenant_role')?->can('models.approve') === true;
        $canApprove = $stepUp->approveClassAllowed($request, $roleCan);
        try {
            return response()->json(['data' => $service->rollback($this->tenant(), $request->user(), (int) $request->validated('model_registry_id'), $canApprove)]);
        } catch (MissingAbilityException $e) {
            throw $roleCan && ! $canApprove ? MfaException::stepUpRequired() : $e;
        }
    }
}
