<?php

declare(strict_types=1);

namespace App\Services\Auth\Mfa;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cuándo el segundo factor es OBLIGATORIO (V2.5 · W6-A; ADR-V25-15: «MFA TOTP obligatorio para roles privilegiados»):
 * - Superadministrador de la plataforma: siempre en producción (`security.mfa.superadmin_required`, por defecto solo en producción).
 * - Propietario y administrador de una empresa: cuando la empresa activa la política `settings.security.mfa_required_for_privileged` (apagada por defecto: una empresa que no la
 *   activa no se bloquea de golpe; el propietario decide).
 * Quien está obligado y no tiene MFA activo puede iniciar sesión y activarlo, pero no operar la empresa hasta hacerlo (`mfa_enrollment_required`).
 */
final class MfaPolicy
{
    public const SETTING = 'mfa_required_for_privileged';

    public function tenantRequires(Tenant $tenant): bool
    {
        return (bool) data_get($tenant->settings ?? [], 'security.'.self::SETTING, false);
    }

    public function isPrivilegedRole(?TenantRole $role): bool
    {
        return $role === TenantRole::Owner || $role === TenantRole::Admin;
    }

    public function superadminRequires(): bool
    {
        $configured = config('security.mfa.superadmin_required');

        return $configured === null ? app()->isProduction() : (bool) $configured;
    }

    /** ¿Debe activar MFA antes de operar esta empresa (con su rol de ACCESO guardado)? */
    public function mustEnroll(User $user, ?Tenant $tenant, ?TenantRole $accessRole): bool
    {
        if ($user->hasMfaEnabled()) {
            return false;
        }
        if ($user->is_superadmin && $this->superadminRequires()) {
            return true;
        }

        return $tenant !== null && $this->tenantRequires($tenant) && $this->isPrivilegedRole($accessRole);
    }

    /** ¿Alguna política le exige mantener el MFA activo (no puede desactivarlo)? */
    public function requiredFor(User $user): bool
    {
        if ($user->is_superadmin && $this->superadminRequires()) {
            return true;
        }
        $tenantIds = DB::table('tenant_users')->where('user_id', $user->getKey())->whereIn('role', [TenantRole::Owner->value, TenantRole::Admin->value])->pluck('tenant_id');
        if ($tenantIds->isEmpty()) {
            return false;
        }

        return Tenant::query()->whereIn('id', $tenantIds)->get(['id', 'settings'])->contains(fn (Tenant $t): bool => $this->tenantRequires($t));
    }
}
