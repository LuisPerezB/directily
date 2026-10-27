<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\TenantRole;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

/**
 * Helpers compartidos (backend-core y backend-analytics) para pruebas multi-tenant.
 */
trait InteractsWithTenants
{
    /** @return array{0: Tenant, 1: User} */
    protected function createTenantWithUser(TenantRole $role = TenantRole::Owner, array $tenantAttributes = []): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::query()->create(array_merge([
            'name' => 'Empresa de prueba '.fake()->unique()->numberBetween(1, 999999),
            'sector' => 'ferreteria',
        ], $tenantAttributes));
        $tenant->users()->attach($user->getKey(), ['role' => $role->value]);

        return [$tenant, $user];
    }

    protected function addUserToTenant(Tenant $tenant, TenantRole $role): User
    {
        $user = User::factory()->create();
        $tenant->users()->attach($user->getKey(), ['role' => $role->value]);

        return $user;
    }

    /** Autentica (Sanctum) y fija el header X-Tenant-ID para las siguientes peticiones. */
    protected function actingAsTenantUser(User $user, Tenant $tenant): static
    {
        Sanctum::actingAs($user, ['*']);

        return $this->withHeader('X-Tenant-ID', $tenant->uuid);
    }

    /** Ejecuta código con el tenant en contexto (para crear datos con modelos BelongsToTenant). */
    protected function withinTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($tenant, $callback);
    }

    protected function createBranch(Tenant $tenant, string $code = '001', string $name = 'Matriz'): Branch
    {
        return $this->withinTenant($tenant, fn () => Branch::query()->create([
            'code' => $code,
            'name' => $name,
            'province' => 'Pichincha',
            'canton' => 'Quito',
        ]));
    }
}
