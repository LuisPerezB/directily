<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTenant;
use App\Http\Requests\Core\StoreTenantRequest;
use App\Http\Requests\Core\UpdateTenantRequest;
use App\Http\Resources\Core\TenantResource;
use App\Http\Resources\Core\TenantSummaryResource;
use App\Models\User;
use App\Services\Plans\PlanLimitService;
use App\Services\Tenancy\TenantService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class TenantController extends Controller
{
    public function __construct(
        private readonly TenantService $tenants,
        private readonly TenantContext $context,
        private readonly PlanLimitService $plans,
    ) {
    }

    /** GET /tenants — empresas del usuario autenticado. */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return TenantSummaryResource::collection($user->tenants()->orderBy('tenants.name')->get());
    }

    /** POST /tenants — crea la empresa; el usuario queda como propietario. */
    public function store(StoreTenantRequest $request): JsonResponse
    {
        $tenant = $this->tenants->create($request->user(), $request->validated());
        $tenant->refresh();

        return (new TenantResource($tenant))->response()->setStatusCode(201);
    }

    /** GET /tenant [T] */
    public function show(): TenantResource
    {
        return new TenantResource($this->context->require());
    }

    /** PATCH /tenant [T] settings.manage */
    public function update(UpdateTenantRequest $request): TenantResource
    {
        return new TenantResource($this->tenants->update($this->context->require(), $request->validated()));
    }

    public function completeOnboarding(): TenantResource
    {
        return new TenantResource($this->tenants->completeOnboarding($this->context->require()));
    }

    /** PUT /tenant/shared-training-consent [T] tenant.consent */
    public function sharedTrainingConsent(Request $request): TenantResource
    {
        $data = $request->validate(['allow' => ['required', 'boolean']]);

        return new TenantResource($this->tenants->setSharedTrainingConsent($this->context->require(), $request->user(), (bool) $data['allow']));
    }

    /** GET /tenant/usage [T] */
    public function usage(): JsonResponse
    {
        $tenant = $this->context->require();

        return response()->json(['data' => [
            'period' => $this->plans->period($tenant),
            'metrics' => $this->plans->snapshot($tenant),
            'limits' => $this->plans->limits($tenant),
        ]]);
    }

    /** DELETE /tenant [T] tenant.delete — solicitud con período de gracia. */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['confirm_name' => ['required', 'string', 'max:150']]);
        $tenant = $this->tenants->requestDeletion($this->context->require(), $data['confirm_name']);

        return response()->json(['data' => [
            'status' => $tenant->status,
            'deletion_requested_at' => $tenant->deletion_requested_at?->toIso8601String(),
            'purge_after' => $tenant->deletion_requested_at?->copy()->addDays((int) config('predictily.privacy.deletion_grace_days'))->toIso8601String(),
        ]], 202);
    }

    /** POST /tenant/cancel-deletion [T allow-pending] tenant.delete */
    public function cancelDeletion(Request $request): TenantResource
    {
        $tenant = $this->tenants->cancelDeletion($this->context->require());
        $request->attributes->set('tenant_role', ResolveTenant::role($request));

        return new TenantResource($tenant);
    }
}
