<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Security;

use App\Enums\CommercialRole;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Security\CommercialRoleRequest;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Rol comercial y alcance de un miembro de la empresa (V2.5 · W6-A; `users.manage`, sensible). SE SUMA al rol de acceso: no lo cambia. Reglas: solo un propietario modifica a
 * otro propietario; «servicio» (solo lectura) no se asigna a un propietario (la empresa quedaría sin quien administre).
 */
final class CommercialRoleController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    /** PUT /tenant/users/{user}/commercial-role */
    public function update(CommercialRoleRequest $request, int $user): JsonResponse
    {
        $tenant = $this->context->require();
        $member = DB::table('tenant_users')->where('tenant_id', $tenant->getKey())->where('user_id', $user)->first(['role', 'commercial_role']);
        if ($member === null) {
            throw new NotFoundHttpException('Usuario no encontrado en la empresa.');
        }
        $targetRole = TenantRole::from($member->role);
        if ($targetRole === TenantRole::Owner && $request->attributes->get('access_role') !== TenantRole::Owner) {
            throw new AccessDeniedHttpException('Solo un propietario puede modificar a otro propietario.');
        }
        $commercial = $request->input('commercial_role');
        if ($commercial === CommercialRole::Servicio->value && $targetRole === TenantRole::Owner) {
            return response()->json(['message' => 'El rol de servicio (solo lectura) no se asigna a un propietario.', 'code' => 'commercial_role_not_allowed'], 422);
        }
        $scope = $commercial === null ? [] : (array) ($request->input('commercial_scope') ?? []);
        DB::table('tenant_users')->where('tenant_id', $tenant->getKey())->where('user_id', $user)
            ->update(['commercial_role' => $commercial, 'commercial_scope' => json_encode((object) $scope, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        $this->audit->log('user.commercial_role_changed', null, [
            'user_id' => $user, 'from' => $member->commercial_role, 'to' => $commercial,
            'scope' => ['customers' => count($scope['customer_ids'] ?? []), 'zones' => count($scope['zones'] ?? []), 'segments' => count($scope['segment_ids'] ?? []), 'team' => count($scope['team_user_ids'] ?? [])],
        ], $tenant->getKey(), $request->user()->getKey());

        return response()->json(['data' => [
            'user_id' => $user,
            'access_role' => $targetRole->value,
            'commercial_role' => $commercial,
            'commercial_role_label' => $commercial !== null ? CommercialRole::from($commercial)->label() : null,
            'commercial_scope' => (object) $scope,
        ]]);
    }
}
