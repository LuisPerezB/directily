<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Security;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Security\SecurityPolicyRequest;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\Mfa\MfaPolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Política de seguridad de la empresa (V2.5 · W6-A; `settings.manage`, sensible): MFA obligatorio para propietarios y administradores (`tenants.settings.security`). Antes de
 * activarla se informa cuántos privilegiados aún no tienen MFA (quedarían bloqueados hasta activarlo) y la activación exige que QUIEN LA ACTIVA ya tenga MFA (no se bloquea a sí mismo).
 */
final class SecurityPolicyController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly MfaPolicy $policy, private readonly AuditLogger $audit) {}

    /** GET /tenant/security-policy */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->context->require())]);
    }

    /** PUT /tenant/security-policy */
    public function update(SecurityPolicyRequest $request): JsonResponse
    {
        $tenant = $this->context->require();
        $required = $request->boolean('mfa_required_for_privileged');
        if ($required && ! $request->user()->hasMfaEnabled()) {
            return response()->json([
                'message' => 'Active primero su propio segundo factor: de lo contrario la política lo bloquearía a usted.',
                'code' => 'mfa_required_to_enable_policy',
            ], 422);
        }
        $settings = $tenant->settings ?? [];
        $before = (bool) data_get($settings, 'security.'.MfaPolicy::SETTING, false);
        $settings['security'] = array_replace((array) ($settings['security'] ?? []), [MfaPolicy::SETTING => $required]);
        $tenant->forceFill(['settings' => $settings])->save();
        $this->audit->log('tenant.security_policy_updated', $tenant, ['mfa_required_for_privileged' => ['from' => $before, 'to' => $required]]);

        return response()->json(['data' => $this->payload($tenant->refresh())]);
    }

    /** @return array<string, mixed> */
    private function payload(Tenant $tenant): array
    {
        $privileged = DB::table('tenant_users')->join('users', 'users.id', '=', 'tenant_users.user_id')->where('tenant_users.tenant_id', $tenant->getKey())
            ->whereIn('tenant_users.role', [TenantRole::Owner->value, TenantRole::Admin->value])->whereNull('users.deleted_at')->get(['users.id', 'users.mfa_enabled_at']);

        return [
            'mfa_required_for_privileged' => $this->policy->tenantRequires($tenant),
            'privileged_members' => $privileged->count(),
            'privileged_without_mfa' => $privileged->whereNull('mfa_enabled_at')->count(),
            'note' => 'Con la política activa, propietarios y administradores sin segundo factor pueden iniciar sesión y activarlo, pero no operar la empresa hasta hacerlo.',
        ];
    }
}
