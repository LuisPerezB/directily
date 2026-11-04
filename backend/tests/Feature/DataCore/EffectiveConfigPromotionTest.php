<?php

declare(strict_types=1);

namespace Tests\Feature\DataCore;

use App\Enums\TenantRole;
use App\Http\Controllers\Api\DataCore\EffectiveConfigController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * V2.5 · W3-D (ADR-V25-21): `promotion` es un core configurable como los demás. La política que gobierna lo servido (`governing_policy`: `legacy` por
 * defecto) y los umbrales de la V2.5 se leen y se sobrescriben por empresa; el motor valida claves, tipos y rangos y el backend solo guarda lo que el motor acepta.
 */
final class EffectiveConfigPromotionTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /** @return array<string, mixed> */
    private function engineConfig(int $tenantId, array $overrides = []): array
    {
        $defaults = ['governing_policy' => 'legacy', 'legacy_windows_won_fraction' => 2 / 3, 'v25_min_selection_windows' => 2, 'v25_min_relative_improvement' => 0.03, 'shadow_enabled' => true, 'optuna_enabled' => false];
        $config = array_merge($defaults, $overrides);

        return [
            'tenant_id' => $tenantId, 'core' => 'promotion', 'config_version' => str_repeat('a', 64), 'profile' => null, 'precedence' => ['core', 'profile', 'tenant'],
            'config' => $config,
            'keys' => array_map(fn ($k, $v) => ['key' => $k, 'value' => $v, 'source' => array_key_exists($k, $overrides) ? 'tenant' : 'core'], array_keys($config), $config),
        ];
    }

    public function test_promotion_is_one_of_the_configurable_cores(): void
    {
        $this->assertContains('promotion', EffectiveConfigController::CORES);
    }

    public function test_the_governing_policy_defaults_to_legacy_and_can_be_read(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $this->actingAsTenantUser($user, $tenant);
        Http::fake(fn (Request $request) => Http::response($this->engineConfig((int) $request['tenant_id']), 200));

        $r = $this->getJson('/api/v1/tenant/effective-config?core=promotion')->assertOk();

        $r->assertJsonPath('data.core', 'promotion')->assertJsonPath('data.keys.0', ['key' => 'governing_policy', 'value' => 'legacy', 'source' => 'core'])->assertJsonPath('data.tenant_overrides', []);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v1/platform/effective-config') && $request['core'] === 'promotion' && (int) $request['tenant_id'] === $tenant->id);
    }

    public function test_a_company_can_let_the_v25_policy_govern_and_the_engine_validates_the_value(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($owner, $tenant);
        Http::fake(function (Request $request) use ($tenant) {
            $overrides = (array) ($request['overrides'] ?? []);
            if (str_contains($request->url(), 'validate-config') && (($overrides['governing_policy'] ?? 'legacy') === 'otra' || array_key_exists('umbral_inventado', $overrides))) {
                return Http::response(['code' => 'invalid_config', 'message' => 'La configuración contiene claves desconocidas o valores no válidos.',
                    'errors' => [['key' => array_key_first($overrides), 'code' => 'out_of_range', 'message' => 'Valor no permitido']]], 422);
            }

            return Http::response($this->engineConfig($tenant->id, $overrides), 200);
        });

        $this->putJson('/api/v1/tenant/core-config/promotion', ['overrides' => ['governing_policy' => 'v25']])->assertOk()
            ->assertJsonPath('data.tenant_overrides.governing_policy', 'v25')->assertJsonPath('data.keys.0.source', 'tenant');
        $stored = json_decode((string) DB::table('tenants')->where('id', $tenant->id)->value('settings'), true);
        $this->assertSame(['governing_policy' => 'v25'], $stored['cores']['promotion']);
        $audit = DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('action', 'tenant.core_config_updated')->latest('id')->first();
        $this->assertSame('promotion', json_decode($audit->metadata, true)['core']);

        // Lo que el motor rechaza no reemplaza al guardado.
        $this->putJson('/api/v1/tenant/core-config/promotion', ['overrides' => ['governing_policy' => 'otra']])->assertStatus(422)->assertJsonPath('code', 'invalid_config');
        $this->putJson('/api/v1/tenant/core-config/promotion', ['overrides' => ['umbral_inventado' => 1]])->assertStatus(422);
        $this->assertSame(['governing_policy' => 'v25'], json_decode((string) DB::table('tenants')->where('id', $tenant->id)->value('settings'), true)['cores']['promotion']);

        // Volver a la política heredada: vaciar los valores de empresa.
        $this->putJson('/api/v1/tenant/core-config/promotion', ['overrides' => []])->assertOk();
        $this->assertArrayNotHasKey('cores', json_decode((string) DB::table('tenants')->where('id', $tenant->id)->value('settings'), true));
    }

    public function test_only_who_manages_settings_can_change_the_governing_policy_and_each_company_keeps_its_own(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        [$other, $otherOwner] = $this->createTenantWithUser();
        $viewer = $this->addUserToTenant($tenant, TenantRole::Viewer);
        Http::fake(fn (Request $request) => Http::response($this->engineConfig((int) $request['tenant_id'], (array) ($request['overrides'] ?? [])), 200));

        $this->actingAsTenantUser($viewer, $tenant)->putJson('/api/v1/tenant/core-config/promotion', ['overrides' => ['governing_policy' => 'v25']])->assertForbidden();
        $this->actingAsTenantUser($owner, $tenant)->putJson('/api/v1/tenant/core-config/promotion', ['overrides' => ['governing_policy' => 'v25']])->assertOk();

        $this->assertNull(json_decode((string) DB::table('tenants')->where('id', $other->id)->value('settings'), true)['cores']['promotion'] ?? null, 'la otra empresa no cambia');
        $this->actingAsTenantUser($otherOwner, $other)->getJson('/api/v1/tenant/effective-config?core=promotion')->assertOk()->assertJsonPath('data.tenant_overrides', []);
    }

    public function test_the_audit_entry_records_the_old_and_new_value_of_each_changed_key(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($owner, $tenant);
        Http::fake(fn (Request $request) => Http::response($this->engineConfig($tenant->id, (array) ($request['overrides'] ?? [])), 200));
        $changes = fn () => json_decode((string) DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('action', 'tenant.core_config_updated')->latest('id')->value('metadata'), true)['changes'];

        // legacy (por defecto, sin sobrescritura) → v25: se puede reconstruir el cambio de política.
        $this->putJson('/api/v1/tenant/core-config/promotion', ['overrides' => ['governing_policy' => 'v25']])->assertOk();
        $this->assertEquals(['governing_policy' => ['old' => null, 'new' => 'v25']], $changes());

        // Un cambio que no toca `governing_policy` no lo vuelve a registrar; una clave nueva figura con old = null.
        $this->putJson('/api/v1/tenant/core-config/promotion', ['overrides' => ['governing_policy' => 'v25', 'v25_min_relative_improvement' => 0.05]])->assertOk();
        $this->assertEquals(['v25_min_relative_improvement' => ['old' => null, 'new' => 0.05]], $changes());

        // Modificar un valor y quitar otro.
        $this->putJson('/api/v1/tenant/core-config/promotion', ['overrides' => ['governing_policy' => 'legacy']])->assertOk();
        $this->assertEquals(['governing_policy' => ['old' => 'v25', 'new' => 'legacy'], 'v25_min_relative_improvement' => ['old' => 0.05, 'new' => null]], $changes());

        // Vaciar los valores de empresa: lo que había queda registrado como anterior.
        $this->putJson('/api/v1/tenant/core-config/promotion', ['overrides' => []])->assertOk();
        $this->assertEquals(['governing_policy' => ['old' => 'legacy', 'new' => null]], $changes());
        $metadata = json_decode((string) DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('action', 'tenant.core_config_updated')->latest('id')->value('metadata'), true);
        $this->assertSame('promotion', $metadata['core']);
        $this->assertSame(['governing_policy'], $metadata['previous_keys']);
    }
}
