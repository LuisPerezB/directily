<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTenant;
use App\Http\Requests\Core\TenantMemberRequest;
use App\Http\Resources\Core\MemberResource;
use App\Models\User;
use App\Services\Tenancy\MembershipService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class TenantUserController extends Controller
{
    public function __construct(private readonly MembershipService $members, private readonly TenantContext $context) {}

    public function index(): AnonymousResourceCollection
    {
        $tenant = $this->context->require();

        return MemberResource::collection($tenant->users()->orderBy('users.name')->get());
    }

    public function store(TenantMemberRequest $request): JsonResponse
    {
        $tenant = $this->context->require();
        $result = $this->members->add(
            $tenant,
            ResolveTenant::role($request),
            $request->string('email')->toString(),
            TenantRole::from($request->string('role')->toString()),
            $request->input('name'),
        );
        $member = $tenant->users()->whereKey($result['user']->id)->firstOrFail();

        return response()->json(['data' => array_merge(
            (new MemberResource($member))->resolve($request),
            ['account_created' => $result['created'], 'temporary_password' => $result['temporary_password']],
        )], 201);
    }

    public function update(TenantMemberRequest $request, int $user): MemberResource
    {
        $tenant = $this->context->require();
        $member = $this->member($user);
        $this->members->changeRole($tenant, ResolveTenant::role($request), $member, TenantRole::from($request->string('role')->toString()));

        return new MemberResource($tenant->users()->whereKey($member->id)->firstOrFail());
    }

    public function destroy(Request $request, int $user): Response
    {
        $this->members->remove($this->context->require(), ResolveTenant::role($request), $this->member($user));

        return response()->noContent();
    }

    /** Solo miembros de la empresa activa (404 en otro caso). */
    private function member(int $userId): User
    {
        return $this->context->require()->users()->whereKey($userId)->firstOrFail();
    }
}
