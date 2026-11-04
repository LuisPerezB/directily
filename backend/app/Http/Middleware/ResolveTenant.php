<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\CommercialRole;
use App\Enums\TenantRole;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Services\Auth\Commercial\CommercialScope;
use App\Services\Auth\Commercial\CommercialScopeContext;
use App\Services\Auth\Mfa\MfaException;
use App\Services\Auth\Mfa\MfaPolicy;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve la empresa activa a partir del header X-Tenant-ID (uuid) y verifica que el
 * usuario autenticado sea miembro. Para tokens de API ligados a un tenant
 * (personal_access_tokens.tenant_id) se usa ese tenant y se ignora el header.
 *
 * Deja el rol disponible en $request->attributes->get('tenant_role') (TenantRole).
 * Respuestas: 400 si falta el header, 404 si no es miembro (no revela existencia),
 * 423 si la empresa está suspendida o en eliminación.
 *
 * V2.5 · W6-A (ADR-V25-15): además fija el alcance comercial de la persona (CommercialScopeContext y el atributo `commercial_scope`) y aplica el techo de solo lectura del rol
 * comercial «servicio» (`tenant_role` es el rol EFECTIVO; el guardado queda en `access_role`). Si la política de la empresa (o de la plataforma, para el superadministrador)
 * exige MFA y la persona aún no lo activó, responde 403 `mfa_enrollment_required`: puede iniciar sesión y activarlo (/auth/mfa/*), pero no operar la empresa.
 *
 * Parámetro opcional: 'tenant:allow-pending' permite operar sobre empresas con
 * eliminación solicitada (solo para cancelar la solicitud o exportar datos).
 */
final class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CommercialScopeContext $commercial,
        private readonly MfaPolicy $mfaPolicy,
    ) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $tenantId = self::tokenTenantId($request);

        if ($tenantId !== null) {
            $tenant = Tenant::query()->find($tenantId);
        } else {
            $uuid = (string) $request->header('X-Tenant-ID', '');
            if ($uuid === '') {
                return response()->json(['message' => 'Falta el encabezado X-Tenant-ID.', 'code' => 'tenant_required'], 400);
            }
            $tenant = preg_match('/^[0-9a-fA-F-]{36}$/', $uuid) === 1
                ? Tenant::query()->where('uuid', $uuid)->first()
                : null;
        }

        $membership = $tenant ? $user->membershipIn($tenant) : null;
        $accessRole = $membership !== null ? TenantRole::tryFrom((string) $membership->role) : null;
        if ($tenant === null || $accessRole === null) {
            return response()->json(['message' => 'Empresa no encontrada.', 'code' => 'tenant_not_found'], 404);
        }
        // Rol efectivo: el comercial «servicio» pone un techo de solo lectura; los demás roles comerciales no cambian las habilidades.
        // Un valor guardado inesperado NO equivale a «sin rol comercial»: queda en solo lectura y con el alcance más restrictivo (falla cerrado).
        [$commercialRole, $unexpectedCommercial] = CommercialRole::parseStored($membership->commercial_role);
        $role = CommercialRole::effectiveAccessRole($accessRole, $commercialRole, $unexpectedCommercial);

        $allowed = $mode === 'allow-pending' ? ['active', 'pending_deletion'] : ['active'];
        if (! in_array($tenant->status, $allowed, true)) {
            return response()->json([
                'message' => $tenant->status === 'pending_deletion'
                    ? 'La empresa tiene una solicitud de eliminación en curso.'
                    : 'La empresa no está activa.',
                'code' => 'tenant_inactive',
            ], 423);
        }

        if ($this->mfaPolicy->mustEnroll($user, $tenant, $accessRole)) {
            return MfaException::enrollmentRequired()->render();
        }

        $this->context->set($tenant);
        $scope = CommercialScope::fromMembership((int) $user->getKey(), $membership->commercial_role, $membership->commercial_scope);
        $this->commercial->set($scope);
        $request->attributes->set('tenant_role', $role);
        $request->attributes->set('access_role', $accessRole);
        $request->attributes->set('commercial_role', $commercialRole);
        $request->attributes->set('commercial_scope', $scope);

        try {
            return $next($request);
        } finally {
            // Evita que el contexto sobreviva entre peticiones en workers persistentes (Octane).
            $this->context->forget();
            $this->commercial->forget();
        }
    }

    public static function role(Request $request): TenantRole
    {
        return $request->attributes->get('tenant_role');
    }

    /** tenant_id del token Bearer real (no del token transitorio de la sesión SPA). */
    public static function tokenTenantId(Request $request): ?int
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->exists && $token->tenant_id !== null) {
            return (int) $token->tenant_id;
        }

        return null;
    }
}
