<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class TenantManagementTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_user_creates_tenant_and_becomes_owner_on_trial_plan(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user->refresh());

        $response = $this->postJson('/api/v1/tenants', ['name' => 'Ferretería El Clavo', 'sector' => 'ferreteria', 'tax_id' => '1790012345001']);

        $response->assertCreated()
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.plan.code', 'trial')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.timezone', 'America/Guayaquil')
            ->assertJsonPath('data.settings.service_level', 0.95)
            ->assertJsonPath('data.onboarding.has_sales', false);
        $this->assertSame(TenantRole::Owner, $user->roleIn(Tenant::query()->where('uuid', $response->json('data.uuid'))->firstOrFail()));
    }

    public function test_tenant_creation_validates_ruc_and_sector(): void
    {
        Sanctum::actingAs(User::factory()->create()->refresh());
        $this->postJson('/api/v1/tenants', ['name' => 'X', 'sector' => 'casino', 'tax_id' => '123'])
            ->assertUnprocessable()->assertJsonValidationErrors(['sector', 'tax_id']);
    }

    public function test_settings_update_is_validated_and_restricted_by_role(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($owner, $tenant)
            ->patchJson('/api/v1/tenant', ['settings' => ['default_horizon_days' => 30, 'service_level' => 0.9]])
            ->assertOk()->assertJsonPath('data.settings.service_level', 0.9)->assertJsonPath('data.settings.review_period_days', 7);
        $this->patchJson('/api/v1/tenant', ['settings' => ['default_horizon_days' => 45]])->assertUnprocessable();
        $this->patchJson('/api/v1/tenant', ['settings' => ['hack' => 1]])->assertUnprocessable();

        $viewer = $this->addUserToTenant($tenant, TenantRole::Viewer);
        $this->actingAsTenantUser($viewer, $tenant)->patchJson('/api/v1/tenant', ['name' => 'Otro'])->assertForbidden();
        $this->getJson('/api/v1/tenant')->assertOk()->assertJsonPath('data.role', 'viewer');
    }

    public function test_missing_or_unknown_tenant_header(): void
    {
        [, $user] = $this->createTenantWithUser();
        Sanctum::actingAs($user->refresh());
        $this->getJson('/api/v1/tenant')->assertStatus(400)->assertJsonPath('code', 'tenant_required');
        $this->withHeader('X-Tenant-ID', 'no-es-uuid')->getJson('/api/v1/tenant')->assertNotFound();
        $this->withHeader('X-Tenant-ID', '00000000-0000-0000-0000-000000000000')->getJson('/api/v1/tenant')->assertNotFound();
    }

    public function test_shared_training_consent_requires_owner_and_is_recorded(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        $admin = $this->addUserToTenant($tenant, TenantRole::Admin);
        $this->actingAsTenantUser($admin, $tenant)->putJson('/api/v1/tenant/shared-training-consent', ['allow' => true])->assertForbidden();
        $this->actingAsTenantUser($owner, $tenant)->putJson('/api/v1/tenant/shared-training-consent', ['allow' => true])
            ->assertOk()->assertJsonPath('data.allow_shared_training', true);
        $tenant->refresh();
        $this->assertNotNull($tenant->shared_training_consented_at);
        $this->assertSame($owner->id, $tenant->shared_training_consented_by);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'tenant.consent_changed']);
    }

    public function test_deletion_request_blocks_tenant_until_cancelled(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($owner, $tenant)->deleteJson('/api/v1/tenant', ['confirm_name' => 'nombre incorrecto'])->assertStatus(409);
        $this->deleteJson('/api/v1/tenant', ['confirm_name' => $tenant->name])->assertStatus(202)->assertJsonStructure(['data' => ['purge_after']]);
        $this->getJson('/api/v1/products')->assertStatus(423);
        $this->postJson('/api/v1/tenant/cancel-deletion')->assertOk()->assertJsonPath('data.status', 'active');
        $this->getJson('/api/v1/products')->assertOk();
    }

    public function test_usage_reports_limits_and_metrics(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($owner, $tenant)->getJson('/api/v1/tenant/usage')
            ->assertOk()->assertJsonStructure(['data' => ['period', 'metrics' => ['import_rows', 'branches', 'users'], 'limits' => ['max_branches', 'horizons', 'features']]]);
    }

    public function test_member_management_rules(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($owner, $tenant);

        $created = $this->postJson('/api/v1/tenant/users', ['email' => 'nuevo@example.com', 'role' => 'analyst', 'name' => 'Nuevo'])
            ->assertCreated()->assertJsonPath('data.role', 'analyst')->assertJsonPath('data.account_created', true);
        $this->assertNotEmpty($created->json('data.temporary_password'));
        $this->postJson('/api/v1/tenant/users', ['email' => 'nuevo@example.com', 'role' => 'viewer'])->assertStatus(409);

        // La empresa no puede quedarse sin propietario.
        $this->patchJson("/api/v1/tenant/users/{$owner->id}", ['role' => 'admin'])->assertStatus(409)->assertJsonPath('code', 'last_owner');
        $this->deleteJson("/api/v1/tenant/users/{$owner->id}")->assertStatus(409);

        // Un administrador no puede asignar ni modificar propietarios.
        $admin = $this->addUserToTenant($tenant, TenantRole::Admin);
        $analystId = $created->json('data.id');
        $this->actingAsTenantUser($admin, $tenant)->patchJson("/api/v1/tenant/users/{$analystId}", ['role' => 'owner'])->assertForbidden();
        $this->patchJson("/api/v1/tenant/users/{$owner->id}", ['role' => 'viewer'])->assertForbidden();
        $this->patchJson("/api/v1/tenant/users/{$analystId}", ['role' => 'viewer'])->assertOk()->assertJsonPath('data.role', 'viewer');

        // Un analista no gestiona usuarios.
        $analyst = $this->addUserToTenant($tenant, TenantRole::Analyst);
        $this->actingAsTenantUser($analyst, $tenant)->getJson('/api/v1/tenant/users')->assertForbidden();
    }

    public function test_plan_limit_on_users_returns_402(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner, ['plan_overrides' => ['max_users' => 1]]);
        $this->actingAsTenantUser($owner, $tenant)->postJson('/api/v1/tenant/users', ['email' => 'otro@example.com', 'role' => 'viewer'])
            ->assertStatus(402)->assertJsonPath('code', 'plan_limit_exceeded')->assertJsonPath('limit', 'max_users');
    }

    public function test_superadmin_endpoints_do_not_expose_business_data(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        $admin = User::factory()->create();
        $admin->forceFill(['is_superadmin' => true])->save();

        Sanctum::actingAs($owner->refresh());
        $this->getJson('/api/v1/admin/tenants')->assertForbidden();

        Sanctum::actingAs($admin->refresh());
        $this->getJson('/api/v1/admin/tenants')->assertOk()->assertJsonPath('data.0.uuid', $tenant->uuid);
        $this->patchJson("/api/v1/admin/tenants/{$tenant->uuid}", ['plan_code' => 'profesional'])->assertOk()->assertJsonPath('data.plan.code', 'profesional');
        // El superadmin no es miembro: no accede a datos de la empresa.
        $this->withHeader('X-Tenant-ID', $tenant->uuid)->getJson('/api/v1/products')->assertNotFound();
    }
}
