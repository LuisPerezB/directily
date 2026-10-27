<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantRole;
use App\Exceptions\DomainStateException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Plans\PlanLimitService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Gestión de miembros de una empresa.
 * Reglas: solo un propietario puede asignar o quitar el rol owner; la empresa nunca
 * puede quedar sin propietario; un administrador no puede modificar a un propietario.
 */
final class MembershipService
{
    public function __construct(private readonly AuditLogger $audit, private readonly PlanLimitService $plans) {}

    /** @return array{user: User, temporary_password: ?string, created: bool} */
    public function add(Tenant $tenant, TenantRole $actorRole, string $email, TenantRole $role, ?string $name = null): array
    {
        $this->guardOwnerAssignment($actorRole, $role);
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($tenant, $email, $role, $name): array {
            $user = User::query()->where('email', $email)->first();
            $temporaryPassword = null;
            $created = false;

            if ($user !== null && DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists()) {
                throw new DomainStateException('El usuario ya pertenece a esta empresa.', 'already_member');
            }
            $this->plans->assertCan($tenant, 'max_users');

            if ($user === null) {
                $temporaryPassword = Str::password(14, symbols: false);
                $user = User::query()->create([
                    'name' => $name ?: Str::before($email, '@'),
                    'email' => $email,
                    'password' => $temporaryPassword,
                ]);
                $created = true;
            }

            $tenant->users()->attach($user->id, ['role' => $role->value, 'invited_by' => auth()->id()]);
            $this->audit->log('user.added', $user, ['role' => $role->value, 'created_account' => $created]);

            return ['user' => $user, 'temporary_password' => $temporaryPassword, 'created' => $created];
        });
    }

    public function changeRole(Tenant $tenant, TenantRole $actorRole, User $member, TenantRole $newRole): void
    {
        $current = $this->roleOrFail($tenant, $member);
        $this->guardOwnerAssignment($actorRole, $newRole);
        if ($current === TenantRole::Owner && $actorRole !== TenantRole::Owner) {
            throw new AccessDeniedHttpException('Solo un propietario puede modificar a otro propietario.');
        }
        if ($current === TenantRole::Owner && $newRole !== TenantRole::Owner) {
            $this->guardNotLastOwner($tenant);
        }

        $tenant->users()->updateExistingPivot($member->id, ['role' => $newRole->value]);
        $this->audit->log('user.role_changed', $member, ['from' => $current->value, 'to' => $newRole->value]);
    }

    public function remove(Tenant $tenant, TenantRole $actorRole, User $member): void
    {
        $current = $this->roleOrFail($tenant, $member);
        if ($current === TenantRole::Owner) {
            if ($actorRole !== TenantRole::Owner) {
                throw new AccessDeniedHttpException('Solo un propietario puede quitar a otro propietario.');
            }
            $this->guardNotLastOwner($tenant);
        }
        $tenant->users()->detach($member->id);
        // Revoca tokens de conectores que el usuario creó para esta empresa.
        $member->tokens()->where('tenant_id', $tenant->id)->delete();
        $this->audit->log('user.removed', $member, ['role' => $current->value]);
    }

    private function roleOrFail(Tenant $tenant, User $member): TenantRole
    {
        return $member->roleIn($tenant) ?? throw new NotFoundHttpException('Usuario no encontrado en la empresa.');
    }

    private function guardOwnerAssignment(TenantRole $actorRole, TenantRole $role): void
    {
        if ($role === TenantRole::Owner && $actorRole !== TenantRole::Owner) {
            throw new AccessDeniedHttpException('Solo un propietario puede asignar el rol de propietario.');
        }
    }

    private function guardNotLastOwner(Tenant $tenant): void
    {
        $owners = DB::table('tenant_users')->where('tenant_id', $tenant->id)->where('role', TenantRole::Owner->value)->count();
        if ($owners <= 1) {
            throw new DomainStateException('La empresa debe conservar al menos un propietario.', 'last_owner');
        }
    }
}
