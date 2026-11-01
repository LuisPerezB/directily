<?php

declare(strict_types=1);

namespace Tests\Feature\DataCore;

use App\Enums\TenantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Proxy de configuración efectiva (ADR-V2-06). El motor resuelve y valida; el backend muestra valor y procedencia por clave,
 * guarda solo valores validados y siempre consulta la empresa del contexto. (La resolución real se prueba en el motor:
 * engine/tests/integration/test_platform_effective_config.py, que comparte el contrato `fixtures/engine-effective-config.json`.)
 */
final class EffectiveConfigApiTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /** @return array<string, mixed> */
    private function engineConfig(int $tenantId, string $core = 'supply', array $overrides = []): array
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/fixtures/engine-effective-config.json'), true);
        $config = array_merge($fixture['config'], $overrides);
        $fixture['tenant_id'] = $tenantId;
        $fixture['core'] = $core;
        $fixture['config'] = $config;
        $fixture['keys'] = array_map(fn ($k, $v) => ['key' => $k, 'value' => $v, 'source' => array_key_exists($k, $overrides) ? 'tenant' : $fixture['provenance'][$k]], array_keys($config), $config);

        return $fixture;
    }

    public function test_shows_value_and_provenance_per_key_for_the_active_tenant_only(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $this->actingAsTenantUser($user, $tenant);
        Http::fake(fn (Request $request) => Http::response($this->engineConfig((int) $request['tenant_id']), 200));

        $response = $this->getJson('/api/v1/tenant/effective-config?core=supply')->assertOk();
        $response->assertJsonPath('data.core', 'supply')->assertJsonPath('data.profile.code', 'synthetic_process_b')
            ->assertJsonPath('data.precedence', ['core', 'profile', 'tenant'])->assertJsonPath('data.keys.0.source', 'profile:synthetic_process_b@1.0.0')
            ->assertJsonPath('data.keys.1', ['key' => 'objective', 'value' => 'min_cost', 'source' => 'core']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v1/platform/effective-config') && (int) $request['tenant_id'] === $tenant->id && $request['core'] === 'supply');

        $this->getJson('/api/v1/tenant/effective-config')->assertStatus(422);
        $this->getJson('/api/v1/tenant/effective-config?core=inventado')->assertStatus(422);
    }

    public function test_a_tenant_id_in_the_query_cannot_read_another_tenants_configuration(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        [$other] = $this->createTenantWithUser();
        $this->actingAsTenantUser($user, $tenant);
        Http::fake(fn (Request $request) => Http::response($this->engineConfig((int) $request['tenant_id']), 200));
        $this->getJson('/api/v1/tenant/effective-config?core=supply&tenant_id='.$other->id)->assertOk();
        Http::assertSent(fn (Request $request) => (int) $request['tenant_id'] === $tenant->id);
        Http::assertNotSent(fn (Request $request) => (int) $request['tenant_id'] === $other->id);
    }

    public function test_saves_only_values_the_engine_validates_and_audits_the_change(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($owner, $tenant);
        Http::fake(function (Request $request) use ($tenant) {
            if (! str_contains($request->url(), 'validate-config')) {
                return Http::response([], 404);
            }
            $overrides = (array) ($request['overrides'] ?? []);
            if (array_key_exists('clave_inventada', $overrides) || ($overrides['service_level'] ?? 0.9) > 0.999) {
                return Http::response(['code' => 'invalid_config', 'message' => 'La configuración contiene claves desconocidas o valores no válidos.',
                    'errors' => [['key' => array_key_first($overrides), 'code' => 'unknown_key', 'message' => 'Clave desconocida']]], 422);
            }

            return Http::response($this->engineConfig($tenant->id, 'supply', $overrides), 200);
        });

        $this->putJson('/api/v1/tenant/core-config/supply', ['overrides' => ['clave_inventada' => 1]])->assertStatus(422)
            ->assertJsonPath('code', 'invalid_config')->assertJsonPath('errors.0.code', 'unknown_key');
        $this->assertArrayNotHasKey('cores', (array) DB::table('tenants')->where('id', $tenant->id)->value('settings') ?: json_decode((string) DB::table('tenants')->where('id', $tenant->id)->value('settings'), true));

        $this->putJson('/api/v1/tenant/core-config/supply', ['overrides' => ['service_level' => 0.9]])->assertOk()
            ->assertJsonPath('data.tenant_overrides.service_level', 0.9)->assertJsonPath('data.keys.0.source', 'tenant');
        $stored = json_decode((string) DB::table('tenants')->where('id', $tenant->id)->value('settings'), true);
        $this->assertSame(['service_level' => 0.9], $stored['cores']['supply']);
        $this->assertSame(0.95, $stored['service_level'], 'los valores de la etapa 1 se conservan');
        $audit = DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('action', 'tenant.core_config_updated')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(['service_level'], json_decode($audit->metadata, true)['keys']);

        // Vaciar los valores de empresa vuelve a core/perfil.
        $this->putJson('/api/v1/tenant/core-config/supply', ['overrides' => []])->assertOk();
        $this->assertArrayNotHasKey('cores', json_decode((string) DB::table('tenants')->where('id', $tenant->id)->value('settings'), true));
        $this->putJson('/api/v1/tenant/core-config/nucleo', ['overrides' => []])->assertNotFound();
        $this->putJson('/api/v1/tenant/core-config/supply', [])->assertStatus(422);
    }

    public function test_requires_permission_and_reports_engine_outage(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        $viewer = $this->addUserToTenant($tenant, TenantRole::Viewer);
        $this->actingAsTenantUser($viewer, $tenant);
        $this->putJson('/api/v1/tenant/core-config/supply', ['overrides' => ['service_level' => 0.9]])->assertForbidden();

        $this->actingAsTenantUser($owner, $tenant);
        Http::fake(['*' => Http::response(['detail' => 'error'], 500)]);
        $this->getJson('/api/v1/tenant/effective-config?core=supply')->assertStatus(503)->assertJsonPath('code', 'engine_unavailable');
        $this->putJson('/api/v1/tenant/core-config/supply', ['overrides' => ['service_level' => 0.9]])->assertStatus(503);
        $this->getJson('/api/v1/platform/extensions')->assertStatus(503);
    }

    public function test_extensions_endpoint_returns_profile_providers_or_the_no_profile_note(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $this->actingAsTenantUser($user, $tenant);
        Http::fake(['*' => Http::response(['tenant_id' => $tenant->id, 'sector' => 'ferreteria', 'profile' => null, 'providers' => [], 'note' => 'La empresa no tiene perfil sectorial: usa solo los valores del core.'], 200)]);
        $this->getJson('/api/v1/platform/extensions')->assertOk()->assertJsonPath('data.profile', null)->assertJsonPath('data.providers', []);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), "/v1/platform/tenants/{$tenant->id}/extensions"));
    }
}
