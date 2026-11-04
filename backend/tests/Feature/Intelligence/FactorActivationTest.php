<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Enums\TenantRole;
use App\Models\FactorDefinition;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Intelligence\Support\IntelligenceFixtures;
use Tests\TestCase;

/** Activación de factores por empresa en `tenants.settings`: restringida a propietario/administrador y sin efecto en el motor con el pipeline apagado. */
final class FactorActivationTest extends TestCase
{
    use IntelligenceFixtures, RefreshDatabase;

    private const KEY = 'weather.observed';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFactorCatalog();
    }

    public function test_owner_and_admin_can_activate_and_the_choice_is_stored_in_tenant_settings_without_touching_the_catalog(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner);
        $admin = $this->addUserToTenant($tenant, TenantRole::Admin);

        $response = $this->actingAsTenantUser($owner, $tenant)->putJson('/api/v1/intelligence/factors/'.self::KEY.'/activation', ['active' => true])->assertOk();
        $response->assertJsonPath('data.company_activation', true)->assertJsonPath('data.state', 'activated_by_company')->assertJsonPath('data.catalog_is_active', false)
            ->assertJsonPath('data.engine_effect', 'none_until_pipeline_enabled');
        $this->assertStringContainsString('pipeline de factores', (string) $response->json('meta.pipeline_note'));

        $settings = Tenant::query()->findOrFail($tenant->id)->settings;
        $this->assertTrue($settings['factor_activation'][self::KEY]['active']);
        $this->assertSame($owner->id, $settings['factor_activation'][self::KEY]['updated_by']);
        // Los demás ajustes de la empresa quedan intactos (horizonte, nivel de servicio…).
        $this->assertSame(30, $settings['default_horizon_days']);
        // La definición del catálogo público NO cambia.
        $this->assertFalse((bool) DB::table('factor_definitions')->where('key', self::KEY)->value('is_active'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'intelligence.factor_activation_changed')->where('tenant_id', $tenant->id)->count());

        // El administrador también puede desactivar; el listado refleja el estado.
        $this->actingAsTenantUser($admin, $tenant)->putJson('/api/v1/intelligence/factors/'.self::KEY.'/activation', ['active' => false])->assertOk()->assertJsonPath('data.state', 'inactive');
        $this->assertFalse(Tenant::query()->findOrFail($tenant->id)->settings['factor_activation'][self::KEY]['active']);
        $this->getJson('/api/v1/intelligence/factors?state=activated_by_company')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_analyst_and_viewer_cannot_activate(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner);
        foreach ([TenantRole::Analyst, TenantRole::Viewer] as $role) {
            $user = $this->addUserToTenant($tenant, $role);
            $this->actingAsTenantUser($user, $tenant)->putJson('/api/v1/intelligence/factors/'.self::KEY.'/activation', ['active' => true])
                ->assertStatus(403)->assertJsonPath('required_ability', 'settings.manage');
        }
        $this->assertArrayNotHasKey('factor_activation', Tenant::query()->findOrFail($tenant->id)->settings);
    }

    public function test_a_read_only_api_token_without_the_ability_cannot_activate(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner);
        $token = $owner->createToken('solo-lectura', ['data.view'])->plainTextToken;
        $this->flushHeaders();

        $this->withToken($token)->withHeader('X-Tenant-ID', $tenant->uuid)->putJson('/api/v1/intelligence/factors/'.self::KEY.'/activation', ['active' => true])->assertStatus(403);
    }

    public function test_activation_is_isolated_per_company_and_rejects_unknown_or_foreign_keys(): void
    {
        [$tenantA, $ownerA] = $this->createTenantWithUser();
        [$tenantB, $ownerB] = $this->createTenantWithUser();
        $this->withinTenant($tenantA, fn () => FactorDefinition::query()->create([
            'family' => 'competition', 'key' => 'competition.privada_a', 'name' => 'Privada A', 'availability_class' => 'OBSERVED_HISTORICAL', 'storage' => 'external_indicator_observations',
        ]));

        $this->actingAsTenantUser($ownerA, $tenantA)->putJson('/api/v1/intelligence/factors/'.self::KEY.'/activation', ['active' => true])->assertOk();
        $this->putJson('/api/v1/intelligence/factors/competition.privada_a/activation', ['active' => true])->assertOk()->assertJsonPath('data.scope', 'tenant');

        // La empresa B ve el factor X desactivado y no puede activar la definición privada de A (no existe para ella).
        $listB = $this->actingAsTenantUser($ownerB, $tenantB)->getJson('/api/v1/intelligence/factors?state=activated_by_company')->assertOk();
        $this->assertCount(0, $listB->json('data'));
        $this->putJson('/api/v1/intelligence/factors/competition.privada_a/activation', ['active' => true])->assertStatus(404);
        $this->putJson('/api/v1/intelligence/factors/no.existe/activation', ['active' => true])->assertStatus(404);
        $this->putJson('/api/v1/intelligence/factors/'.self::KEY.'/activation', [])->assertStatus(422);
        $this->putJson('/api/v1/intelligence/factors/'.self::KEY.'/activation', ['active' => 'tal vez'])->assertStatus(422);
        $this->assertArrayNotHasKey('factor_activation', Tenant::query()->findOrFail($tenantB->id)->settings);
    }

    public function test_activation_cannot_be_forged_through_the_tenant_settings_endpoint(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner);

        $this->actingAsTenantUser($owner, $tenant)->patchJson('/api/v1/tenant', ['settings' => ['factor_activation' => [self::KEY => ['active' => true]]]])->assertStatus(422);
        $this->assertArrayNotHasKey('factor_activation', Tenant::query()->findOrFail($tenant->id)->settings);
    }

    public function test_activation_persists_when_other_settings_are_updated_afterwards(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner);
        $this->actingAsTenantUser($owner, $tenant)->putJson('/api/v1/intelligence/factors/'.self::KEY.'/activation', ['active' => true])->assertOk();

        $this->patchJson('/api/v1/tenant', ['settings' => ['default_horizon_days' => 14]])->assertOk();

        $settings = Tenant::query()->findOrFail($tenant->id)->settings;
        $this->assertSame(14, $settings['default_horizon_days']);
        $this->assertTrue($settings['factor_activation'][self::KEY]['active'], 'Actualizar otros ajustes no borra la activación de factores.');
    }
}
