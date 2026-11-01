<?php

declare(strict_types=1);

namespace Tests\Feature\Plans;

use App\Enums\TenantRole;
use App\Jobs\Customer\RunCustomerTraining;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Customer\CustomerRunService;
use App\Services\Forecasting\ForecastRunService;
use App\Services\Plans\PlanLimitService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\Feature\Customer\Support\CustomerFixtures;
use Tests\TestCase;

/**
 * W2-E · planes comerciales configurables por empresa (ADR-V25-18) sobre el catálogo único de horizontes (ADR-V25-02): Básico intacto,
 * Starter/Growth/Enterprise agregados como datos, ningún precio como límite, el programador usa el mayor horizonte del plan, 15 de punta a
 * punta en la API de demanda y de Customer, `max_series` y `features.customer` exigidos, y los límites por contrato validados.
 */
final class CommercialPlansTest extends TestCase
{
    use AnalyticsFixtures;
    use CustomerFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /** @return array{0: Tenant, 1: User} */
    private function onPlan(string $code, array $overrides = []): array
    {
        return $this->createTenantWithUser(TenantRole::Owner, array_filter([
            'plan_id' => Plan::query()->where('code', $code)->value('id'),
            'plan_overrides' => $overrides ?: null,
        ], fn ($v) => $v !== null));
    }

    private function limitsOf(Tenant $tenant): array
    {
        return app(PlanLimitService::class)->limits($tenant->fresh());
    }

    // ───────────────────────── Básico intacto ─────────────────────────

    public function test_the_basic_plan_is_exactly_what_it_was(): void
    {
        $expected = [
            'max_branches' => 2, 'max_active_products' => 1500, 'max_import_rows_per_month' => 300_000, 'max_forecast_runs_per_day' => 2, 'max_users' => 3,
            'horizons' => [7, 14, 30],
            // `customer => true` se declara explícito (antes se infería de su ausencia): mismo comportamiento, ya no depende de un valor por omisión.
            'features' => ['scenarios' => false, 'api_access' => false, 'external_indicators' => false, 'custom_models' => false, 'customer' => true],
        ];
        $this->assertSame($expected, config('predictily.plans.basico.limits'));
        $this->assertSame(39, config('predictily.plans.basico.monthly_price_usd'));
        $this->assertEquals($expected, Plan::query()->where('code', 'basico')->firstOrFail()->limits, 'jsonb no conserva el orden de las claves');
        $this->assertNull(Plan::query()->where('code', 'basico')->value('metadata'));
        [$tenant] = $this->onPlan('basico');
        $limits = $this->limitsOf($tenant);
        $this->assertSame([7, 14, 30], $limits['horizons']);
        $this->assertArrayNotHasKey('max_series', $limits, 'el Básico no gana un tope de series');
        $this->assertTrue(app(PlanLimitService::class)->allowsCustomer($tenant), 'sin features.customer = incluido (compatibilidad con los planes heredados)');
    }

    // ───────────────────────── planes nuevos como datos ─────────────────────────

    public function test_starter_growth_and_enterprise_are_configurable_plans_with_the_documented_hypotheses(): void
    {
        $starter = $this->limitsOf($this->onPlan('starter')[0]);
        $this->assertSame([7, 30], $starter['horizons']);
        $this->assertSame([1, 750], [$starter['max_branches'], $starter['max_series']]);
        $this->assertFalse($starter['features']['customer']);
        $this->assertFalse($starter['features']['api_access']);

        $growth = $this->limitsOf($this->onPlan('growth')[0]);
        $this->assertSame([7, 15, 30], $growth['horizons']);
        $this->assertSame([5, 5000], [$growth['max_branches'], $growth['max_series']]);
        $this->assertNull($growth['max_active_products'], 'la serie es la unidad: sin tope heredado del plan de prueba');
        $this->assertTrue($growth['features']['customer'] && $growth['features']['api_access'] && $growth['features']['roles']);

        $enterprise = $this->limitsOf($this->onPlan('enterprise')[0]);
        $this->assertSame([7, 15, 30], $enterprise['horizons'], 'Enterprise: 7/15/30 por defecto');
        $this->assertTrue($enterprise['features']['custom_models'] && $enterprise['features']['customer']);

        $this->assertSame(['starter', 'growth', 'enterprise'], Plan::query()->whereIn('code', ['starter', 'growth', 'enterprise'])->orderBy('id')->pluck('code')->all());
    }

    public function test_no_price_is_ever_a_limit(): void
    {
        $allowedKeys = array_merge(config('predictily.plan_limit_keys.enforced'), config('predictily.plan_limit_keys.declared'));
        foreach (config('predictily.plans') as $code => $plan) {
            foreach (array_keys($plan['limits']) as $key) {
                $this->assertContains($key, $allowedKeys, "{$code}: «{$key}» no es una clave de límite declarada");
                $this->assertDoesNotMatchRegularExpression('/price|usd|precio|cost|fee|amount/i', $key, "{$code}: un precio no puede ser un límite");
            }
            $this->assertStringNotContainsString('"price', strtolower(json_encode($plan['limits'])), $code);
        }
        // Los precios de referencia son informativos: viven en la columna monthly_price_usd y en metadata, nunca en limits.
        $starter = Plan::query()->where('code', 'starter')->firstOrFail();
        $growth = Plan::query()->where('code', 'growth')->firstOrFail();
        $enterprise = Plan::query()->where('code', 'enterprise')->firstOrFail();
        $this->assertEquals([49, 119], [$starter->monthly_price_usd, $growth->monthly_price_usd]);
        $this->assertNull($enterprise->monthly_price_usd);
        $this->assertEquals(['implementation_one_time' => ['min' => 1500, 'max' => 8000], 'subscription_monthly' => ['min' => 1000, 'max' => 3000]],
            $enterprise->metadata['reference_price_usd']);
        $this->assertTrue($starter->metadata['informational'] && $starter->metadata['hypothesis'] && $enterprise->metadata['contract']);
        // Cambiar el precio informativo no cambia ningún límite efectivo.
        [$tenant] = $this->onPlan('starter');
        $before = $this->limitsOf($tenant);
        $starter->update(['monthly_price_usd' => 9999, 'metadata' => ['reference_price_usd' => ['monthly' => 9999]]]);
        $this->assertSame($before, $this->limitsOf($tenant));
    }

    public function test_plan_limits_only_ever_contain_catalog_horizons(): void
    {
        [$tenant] = $this->onPlan('growth', ['horizons' => [7, 21, 15, 45]]);
        $this->assertSame([7, 15], $this->limitsOf($tenant)['horizons'], 'lo que no está en el catálogo único no existe para el motor');
    }

    // ───────────────────────── el programador usa el mayor horizonte del plan ─────────────────────────

    public function test_the_largest_allowed_horizon_of_each_plan_is_what_the_scheduler_asks_for(): void
    {
        $service = app(ForecastRunService::class);
        $this->assertSame(30, $service->maxAllowedHorizon($this->onPlan('basico')[0]->fresh()));
        $this->assertSame(30, $service->maxAllowedHorizon($this->onPlan('starter')[0]->fresh()));
        $this->assertSame(30, $service->maxAllowedHorizon($this->onPlan('growth')[0]->fresh()));
        $this->assertSame(30, $service->maxAllowedHorizon($this->onPlan('enterprise')[0]->fresh()));
        $this->assertSame(90, $service->maxAllowedHorizon($this->onPlan('enterprise', ['horizons' => [7, 14, 15, 30, 60, 90]])[0]->fresh()), 'catálogo completo habilitable');
        $this->assertSame(15, $service->maxAllowedHorizon($this->onPlan('enterprise', ['horizons' => [7, 15]])[0]->fresh()));
        $this->assertNull($service->maxAllowedHorizon($this->onPlan('growth', ['horizons' => []])[0]->fresh()));
    }

    public function test_the_scheduler_command_asks_each_company_for_the_largest_horizon_of_its_plan(): void
    {
        Queue::fake();
        $expected = [];
        foreach ([['starter', [], 30], ['growth', [], 30], ['enterprise', [], 30], ['enterprise', ['horizons' => [7, 14, 15, 30, 60, 90]], 90], ['enterprise', ['horizons' => [7, 15]], 15]] as [$code, $overrides, $horizon]) {
            [$tenant] = $this->onPlan($code, $overrides);
            $this->seedSales($tenant, $this->seedCatalog($tenant), '2026-09-30', 10);
            $expected[$tenant->id] = $horizon;
        }

        $this->artisan('predictily:schedule-forecasts')->expectsOutputToContain('Ejecuciones programadas: 5')->assertSuccessful();

        foreach ($expected as $tenantId => $horizon) {
            $this->assertDatabaseHas('forecast_runs', ['tenant_id' => $tenantId, 'triggered_by' => 'schedule', 'horizon_days' => $horizon]);
        }
        $this->assertSame(5, DB::table('forecast_runs')->count(), 'una sola ejecución por empresa, sin pedir un horizonte que el plan no incluye');
    }

    // ───────────────────────── 15 de punta a punta en la API de demanda ─────────────────────────

    public function test_horizon_15_is_accepted_only_by_the_plans_that_include_it(): void
    {
        Queue::fake();
        [$growth, $gu] = $this->onPlan('growth');
        $this->actingAsTenantUser($gu, $growth)->postJson('/api/v1/forecast-runs', ['horizon_days' => 15])->assertStatus(202)
            ->assertJsonPath('data.horizon_days', 15)->assertJsonPath('data.options.horizons', [7, 15]);

        foreach (['starter', 'basico'] as $code) {
            [$tenant, $user] = $this->onPlan($code);
            $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/forecast-runs', ['horizon_days' => 15])
                ->assertStatus(402)->assertJsonPath('code', 'plan_limit_exceeded')->assertJsonPath('limit', 'horizons');
        }
        [$trial, $tu] = $this->createTenantWithUser();
        $this->actingAsTenantUser($tu, $trial)->postJson('/api/v1/forecast-runs', ['horizon_days' => 15])->assertStatus(402);

        [$ent, $eu] = $this->onPlan('enterprise');
        $this->actingAsTenantUser($eu, $ent)->postJson('/api/v1/forecast-runs')->assertStatus(202)->assertJsonPath('data.horizon_days', 30)
            ->assertJsonPath('data.options.horizons', [7, 15, 30]);
        [$full, $fu] = $this->onPlan('enterprise', ['horizons' => [7, 14, 15, 30, 60, 90]]);
        $this->actingAsTenantUser($fu, $full)->postJson('/api/v1/forecast-runs')->assertStatus(202)->assertJsonPath('data.horizon_days', 90)
            ->assertJsonPath('data.options.horizons', [7, 14, 15, 30, 60, 90]);
        // 21 no pertenece al catálogo: se rechaza por validación aunque el plan lo trajera.
        $this->actingAsTenantUser($gu, $growth)->postJson('/api/v1/forecast-runs', ['horizon_days' => 21])->assertStatus(422)->assertJsonValidationErrors('horizon_days');
    }

    public function test_tenant_default_horizon_accepts_15_and_the_reads_use_it(): void
    {
        [$tenant, $user] = $this->onPlan('growth');
        $api = $this->actingAsTenantUser($user, $tenant);
        $api->patchJson('/api/v1/tenant', ['settings' => ['default_horizon_days' => 15]])->assertOk();
        $api->getJson('/api/v1/forecasts/series?horizon=15')->assertOk()->assertJsonPath('data.meta.horizon_support.status', 'not_computed');
        $api->patchJson('/api/v1/tenant', ['settings' => ['default_horizon_days' => 21]])->assertStatus(422);
    }

    // ───────────────────────── tope de series ─────────────────────────

    public function test_active_series_over_the_plan_cap_block_a_new_forecast_run(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->onPlan('growth', ['max_series' => 5]);
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, now()->subDay()->toDateString(), 10);   // 2 sucursales × 3 productos = 6 series activas
        $service = app(PlanLimitService::class);
        $this->assertSame(6, $service->activeSeries($tenant));
        $api = $this->actingAsTenantUser($user, $tenant);

        $api->postJson('/api/v1/forecast-runs')->assertStatus(402)->assertJsonPath('code', 'plan_limit_exceeded')
            ->assertJsonPath('limit', 'max_series')->assertJsonPath('current', 6)->assertJsonPath('max', 5);
        $this->assertDatabaseCount('forecast_runs', 0);

        $tenant->forceFill(['plan_overrides' => ['max_series' => 6]])->save();     // exactamente en el tope: se permite
        $api->postJson('/api/v1/forecast-runs')->assertStatus(202);
    }

    public function test_series_outside_the_window_do_not_count_and_plans_without_a_cap_never_query(): void
    {
        [$tenant] = $this->onPlan('growth', ['max_series' => 1]);
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, now()->subDays(PlanLimitService::SERIES_WINDOW_DAYS + 20)->toDateString(), 5);
        $this->assertSame(0, app(PlanLimitService::class)->activeSeries($tenant), 'ventas más viejas que la ventana no son series activas');

        [$basic] = $this->onPlan('basico');
        DB::enableQueryLog();
        app(PlanLimitService::class)->assertCan($basic, 'max_series', 0);
        $this->assertSame([], array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'sale_items')), 'sin max_series no se consulta ninguna venta');
        $this->assertNull(app(PlanLimitService::class)->snapshot($basic)['series']);
    }

    // ───────────────────────── Customer: plan y subconjunto de horizontes ─────────────────────────

    public function test_starter_has_no_customer_core_and_every_customer_endpoint_says_so(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->onPlan('starter');
        $api = $this->actingAsTenantUser($user, $tenant);
        $api->postJson('/api/v1/customer/runs', ['operation' => 'train'])->assertStatus(402)->assertJsonPath('limit', 'features.customer');
        $api->getJson('/api/v1/customer/predictions')->assertStatus(402)->assertJsonPath('limit', 'features.customer');
        $api->getJson('/api/v1/customer/evaluation')->assertStatus(402)->assertJsonPath('limit', 'features.customer');
        Queue::assertNothingPushed();
        $this->assertSame([], app(CustomerRunService::class)->allowedHorizons($tenant->fresh()));
    }

    public function test_growth_runs_customer_on_its_subset_including_15(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->onPlan('growth');
        $api = $this->actingAsTenantUser($user, $tenant);

        $api->postJson('/api/v1/customer/runs', ['operation' => 'train'])->assertStatus(202)->assertJsonPath('data.horizons', [7, 15, 30]);
        Queue::assertPushed(RunCustomerTraining::class);
        DB::table('core_runs')->where('tenant_id', $tenant->id)->update(['status' => 'completed']);
        $api->postJson('/api/v1/customer/runs', ['operation' => 'predict', 'horizons' => [15]])->assertStatus(202)->assertJsonPath('data.horizons', [15]);
        DB::table('core_runs')->where('tenant_id', $tenant->id)->update(['status' => 'completed']);
        // 14 existe en Customer pero el plan no lo incluye (402); 60 no existe en Customer (422).
        $api->postJson('/api/v1/customer/runs', ['operation' => 'predict', 'horizons' => [14]])->assertStatus(402)->assertJsonPath('limit', 'horizons');
        $api->postJson('/api/v1/customer/runs', ['operation' => 'predict', 'horizons' => [60]])->assertStatus(422)->assertJsonValidationErrors('horizons.0');
    }

    public function test_customer_reads_horizon_15_and_abstains_with_a_reason_from_a_horizon_the_run_does_not_have(): void
    {
        [$tenant, $user] = $this->onPlan('growth');
        $cat = $this->seedCustomerCatalog($tenant);
        $run = $this->seedPredictionRun($tenant, $cat, [7, 15]);
        DB::table('core_runs')->where('id', $run)->update(['summary' => json_encode(['horizon_support' => [
            '30' => ['status' => 'insufficient_data', 'reasons' => [['code' => 'no_model', 'reason_code' => 'no_model', 'message' => 'No hay modelo en producción para 30 días: entrene primero.']]],
        ]])]);
        $api = $this->actingAsTenantUser($user, $tenant);

        $api->getJson('/api/v1/customer/predictions?horizon=15')->assertOk()->assertJsonPath('envelope.horizon_end', '2026-10-16')->assertJsonPath('meta.total', 4);
        $api->getJson('/api/v1/customer/predictions?horizon=30')->assertStatus(422)->assertJsonPath('code', 'horizon_not_available')
            ->assertJsonPath('quality_status', 'insufficient_data')->assertJsonPath('reason_code', 'no_model')->assertJsonPath('available_horizons', [7, 15])
            ->assertJsonPath('reasons.0.message', 'No hay modelo en producción para 30 días: entrene primero.');
        $api->getJson('/api/v1/customer/predictions?horizon=60')->assertStatus(422)->assertJsonValidationErrors('horizon');
        // El perfil solo lista los horizontes que la corrida sirve (no un ranking vacío para uno del plan sin modelo).
        $profile = $api->getJson('/api/v1/customer/customers/'.$cat['customers']['c2'].'/profile')->assertOk();
        $this->assertSame([7, 15], array_map('intval', array_keys($profile->json('data.rankings'))));
    }

    // ───────────────────────── límites por contrato ─────────────────────────

    public function test_the_superadmin_sets_contract_limits_and_invalid_overrides_are_rejected(): void
    {
        [$tenant, $owner] = $this->onPlan('enterprise');
        $admin = User::factory()->create();
        $admin->forceFill(['is_superadmin' => true])->save();
        Sanctum::actingAs($admin->refresh());

        $this->patchJson("/api/v1/admin/tenants/{$tenant->uuid}", ['plan_overrides' => [
            'horizons' => [7, 14, 15, 30, 60, 90], 'max_series' => 20_000, 'max_users' => 40, 'max_branches' => null,
            'features' => ['private_processing' => true], 'modules' => ['demand', 'supply'], 'retention_months' => 48,
        ]])->assertOk()->assertJsonPath('data.plan_overrides.max_series', 20_000);
        $limits = $this->limitsOf($tenant);
        $this->assertSame([7, 14, 15, 30, 60, 90], $limits['horizons']);
        $this->assertSame([20_000, 40, null], [$limits['max_series'], $limits['max_users'], $limits['max_branches']], 'null = sin tope del plan');
        $this->assertTrue($limits['features']['private_processing'] && $limits['features']['custom_models'], 'los mapas se combinan; las listas se reemplazan');

        foreach ([
            ['horizons' => [7, 45]],                               // fuera del catálogo
            ['monthly_price_usd' => 10],                            // un precio no es un límite
            ['max_series' => -1],
            ['max_users' => 'muchos'],
            ['features' => ['api_access' => 'quizá']],
            ['inventada' => 1],
        ] as $bad) {
            $this->patchJson("/api/v1/admin/tenants/{$tenant->uuid}", ['plan_overrides' => $bad])->assertStatus(422);
        }
        $this->assertSame([7, 14, 15, 30, 60, 90], $this->limitsOf($tenant)['horizons'], 'un override inválido no cambia nada');
    }

    public function test_an_empty_features_override_never_restores_a_capability_the_plan_denies(): void
    {
        Queue::fake();
        // `features: []` / `{}` guardado en plan_overrides (p. ej. por una edición vacía) significa «sin cambios»: Starter conserva customer=false.
        foreach ([[], new \stdClass] as $empty) {
            [$tenant, $user] = $this->onPlan('starter');
            DB::table('tenants')->where('id', $tenant->id)->update(['plan_overrides' => json_encode(['features' => $empty])]);
            $limits = $this->limitsOf($tenant);
            $this->assertFalse($limits['features']['customer'], 'customer sigue desactivado');
            $this->assertFalse($limits['features']['api_access']);
            $this->assertFalse(app(PlanLimitService::class)->allowsCustomer($tenant->fresh()));
            $this->actingAsTenantUser($user, $tenant->fresh())->postJson('/api/v1/customer/runs', ['operation' => 'train'])->assertStatus(402)->assertJsonPath('limit', 'features.customer');
        }
        // Un override explícito sí cambia la capacidad (el contrato de una empresa puede habilitar Customer en Starter) y las demás se conservan.
        [$tenant] = $this->onPlan('starter', ['features' => ['customer' => true]]);
        $limits = $this->limitsOf($tenant);
        $this->assertTrue($limits['features']['customer']);
        $this->assertFalse($limits['features']['api_access']);
        // Un mapa vacío sobre horizons sigue siendo «ningún horizonte» (lista que reemplaza), no «sin cambios».
        [$none] = $this->onPlan('growth', ['horizons' => []]);
        $this->assertSame([], $this->limitsOf($none)['horizons']);
    }

    public function test_the_legacy_plans_declare_customer_explicitly(): void
    {
        foreach (['trial', 'basico', 'profesional', 'empresarial'] as $code) {
            $this->assertTrue(config("predictily.plans.{$code}.limits.features.customer"), "{$code} declara customer");
            $this->assertTrue(Plan::query()->where('code', $code)->firstOrFail()->limits['features']['customer'], "{$code} (sembrado)");
        }
        $this->assertFalse(config('predictily.plans.starter.limits.features.customer'));
    }

    public function test_the_admin_treats_an_empty_features_override_as_no_change_and_rejects_a_list(): void
    {
        [$tenant] = $this->onPlan('starter');
        $admin = User::factory()->create();
        $admin->forceFill(['is_superadmin' => true])->save();
        Sanctum::actingAs($admin->refresh());

        $this->patchJson("/api/v1/admin/tenants/{$tenant->uuid}", ['plan_overrides' => ['features' => []]])->assertOk();
        $this->assertFalse($this->limitsOf($tenant)['features']['customer']);
        $this->patchJson("/api/v1/admin/tenants/{$tenant->uuid}", ['plan_overrides' => ['features' => [true, false]]])->assertStatus(422)
            ->assertJsonValidationErrors('plan_overrides.features');
        $this->patchJson("/api/v1/admin/tenants/{$tenant->uuid}", ['plan_overrides' => ['features' => ['customer' => true]]])->assertOk();
        $this->assertTrue($this->limitsOf($tenant)['features']['customer']);
    }

    public function test_customer_abstention_without_a_recorded_reason_does_not_assume_a_missing_model(): void
    {
        [$tenant, $user] = $this->onPlan('growth');
        $cat = $this->seedCustomerCatalog($tenant);
        $this->seedPredictionRun($tenant, $cat, [7, 15]);     // corrida sin summary.horizon_support (anterior a W2-E)
        $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/customer/predictions?horizon=30')->assertStatus(422)
            ->assertJsonPath('code', 'horizon_not_available')->assertJsonPath('quality_status', 'insufficient_data')
            ->assertJsonPath('reason_code', 'unknown_legacy_run')->assertJsonPath('reasons', []);
    }

    public function test_the_admin_plan_catalog_exposes_the_informational_metadata(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_superadmin' => true])->save();
        Sanctum::actingAs($admin->refresh());
        $plans = collect($this->getJson('/api/v1/admin/plans')->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(['trial', 'basico', 'profesional', 'empresarial', 'starter', 'growth', 'enterprise'], $plans->keys()->all());
        $this->assertSame(119, (int) $plans['growth']['monthly_price_usd']);
        $this->assertTrue($plans['growth']['metadata']['hypothesis']);
        $this->assertNull($plans['basico']['metadata']);
    }
}
