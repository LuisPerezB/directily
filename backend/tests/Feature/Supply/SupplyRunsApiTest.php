<?php

declare(strict_types=1);

namespace Tests\Feature\Supply;

use App\Enums\TenantRole;
use App\Jobs\Supply\RunCostForecast;
use App\Jobs\Supply\RunSupplyPlan;
use App\Jobs\Supply\RunSupplySimulation;
use App\Models\ForecastRun;
use App\Models\Tenant;
use App\Services\Supply\SupplyRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\TestCase;

/** Planes, pronósticos de costos y simulaciones: solicitud asíncrona, concurrencia, permisos, validación y aislamiento. */
class SupplyRunsApiTest extends TestCase
{
    use AnalyticsFixtures;
    use RefreshDatabase;

    public function test_plan_request_creates_queued_run_and_job_on_the_forecasting_queue(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);
        $r = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/supply/plans', [
            'objective' => 'min_cost', 'coverage_mode' => 'soft', 'horizon_days' => 84, 'config' => ['holding_cost_rate_annual' => 0.25],
            'priorities' => ['10' => 2.0],
        ]);
        $r->assertStatus(202)->assertJsonPath('data.core', 'supply')->assertJsonPath('data.operation', 'plan')->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.result_type', 'optimized_plan')
            ->assertJsonPath('data.training_job.job_type', 'supply_plan')->assertJsonPath('data.options.config.holding_cost_rate_annual', 0.25);
        $runId = $r->json('data.id');
        Queue::assertPushedOn('forecasting', RunSupplyPlan::class, fn (RunSupplyPlan $j) => $j->tenantId === $tenant->id && $j->coreRunId === $runId);
        $this->assertDatabaseHas('training_jobs', ['tenant_id' => $tenant->id, 'core_run_id' => $runId, 'status' => 'queued']);
        // Concurrencia: una sola corrida activa por empresa y operación
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/supply/plans', [])->assertStatus(409)->assertJsonPath('code', 'supply_run_in_progress');
        // Otras operaciones sí pueden convivir
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/supply/cost-forecasts', [])->assertStatus(202);
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/supply/simulations', ['days' => 60])->assertStatus(202)->assertJsonPath('data.run_type', 'historical');
        Queue::assertPushed(RunCostForecast::class);
        Queue::assertPushed(RunSupplySimulation::class);
    }

    public function test_plan_validation_rejects_unknown_config_and_unsafe_values(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);
        $post = fn (array $b) => $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/supply/plans', $b);
        $post(['objective' => 'maximize_everything'])->assertStatus(422);
        $post(['coverage_mode' => 'maybe'])->assertStatus(422);
        $post(['config' => ['shortage_penalty_multiplier' => 1.0]])->assertStatus(422);   // con k ≤ 1 ninguna compra se justifica
        $post(['config' => ['safety_stock_weight' => 1.0]])->assertStatus(422);
        $post(['config' => ['solver_time_limit_seconds' => 9999]])->assertStatus(422);
        $post(['config' => ['unknown_key' => 1]])->assertStatus(422);
        $post(['forecast_run_id' => 999999])->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_plan_cannot_use_another_companys_forecast_run(): void
    {
        Queue::fake();
        [$tenantA, $userA] = $this->createTenantWithUser(TenantRole::Analyst);
        [$tenantB] = $this->createTenantWithUser(TenantRole::Analyst);
        $foreign = $this->withinTenant($tenantB, fn () => ForecastRun::query()->create([
            'version' => 1, 'run_type' => 'production', 'status' => 'completed', 'horizon_days' => 30, 'cutoff_date' => '2026-10-01']));
        $this->actingAsTenantUser($userA, $tenantA)->postJson('/api/v1/supply/plans', ['forecast_run_id' => $foreign->id])->assertStatus(422);
    }

    /** Inserta un plan completado como lo haría el motor. */
    private function seedCompletedPlan(Tenant $tenant, array $cat): int
    {
        $now = now();
        $runId = DB::table('core_runs')->insertGetId([
            'tenant_id' => $tenant->id, 'core' => 'supply', 'operation' => 'plan', 'version' => 1, 'run_type' => 'production', 'status' => 'completed',
            'result_type' => 'optimized_plan', 'quality_status' => 'degraded', 'uncertainty_source' => 'none', 'as_of' => '2026-10-26',
            'horizon_start' => '2026-10-27', 'horizon_end' => '2027-01-18', 'assumptions' => json_encode(['supuesto 1']), 'limitations' => json_encode(['limitación 1']),
            'summary' => json_encode(['solver_status' => 'optimal']), 'options' => '{}', 'is_current' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $planId = DB::table('supply_plans')->insertGetId([
            'tenant_id' => $tenant->id, 'core_run_id' => $runId, 'objective' => 'min_cost', 'coverage_mode' => 'soft', 'solver_status' => 'optimal', 'mip_gap' => 0,
            'objective_value' => 940, 'currency' => 'USD', 'total_purchase_cost' => 700, 'unmet_units_total' => 60, 'demand_units_total' => 420,
            'details' => json_encode(['constraints' => [['family' => 'budget', 'used' => 100, 'limit' => 100, 'binding' => true]],
                'report' => ['comparison_coverage' => ['offers_received' => 2, 'offers_excluded' => 1], 'production' => ['status' => 'not_configured']]]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('supply_plan_lines')->insert([
            'tenant_id' => $tenant->id, 'supply_plan_id' => $planId, 'line_type' => 'purchase', 'branch_id' => $cat['branches']['b1'], 'product_id' => $cat['products']['p1'],
            'quantity' => 70, 'unit' => 'unidad', 'order_date' => '2026-10-26', 'expected_receipt_date' => '2026-11-02', 'unit_cost' => 2, 'line_cost' => 140, 'currency' => 'USD',
            'actionable_now' => true, 'binding_constraints' => json_encode([['family' => 'budget']]), 'justification' => 'Única oferta comparable vigente.', 'assumptions' => '{}',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('supply_plan_shortfalls')->insert([
            'tenant_id' => $tenant->id, 'supply_plan_id' => $planId, 'branch_id' => $cat['branches']['b1'], 'product_id' => $cat['products']['p1'],
            'period_start' => '2026-10-27', 'period_end' => '2026-11-02', 'demand' => 70, 'unmet' => 60, 'reason' => 'lead_time',
        ]);

        return $runId;
    }

    public function test_plan_detail_returns_lines_shortfalls_constraints_and_the_common_envelope(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Viewer);
        $cat = $this->seedCatalog($tenant);
        $runId = $this->withinTenant($tenant, fn () => $this->seedCompletedPlan($tenant, $cat));
        $r = $this->actingAsTenantUser($user, $tenant)->getJson("/api/v1/supply/plans/{$runId}")->assertOk();
        $r->assertJsonPath('envelope.result_type', 'optimized_plan')->assertJsonPath('envelope.core', 'supply')->assertJsonPath('envelope.run_id', $runId)
            ->assertJsonPath('envelope.tenant_id', $tenant->id)->assertJsonPath('envelope.quality_status', 'degraded')
            ->assertJsonPath('envelope.uncertainty_source', 'none')->assertJsonPath('envelope.assumptions.0', 'supuesto 1')
            ->assertJsonPath('envelope.entity_keys', ['branch_id', 'product_id', 'supplier_id'])->assertJsonPath('envelope.currency', 'USD');
        $r->assertJsonPath('data.plan.solver_status', 'optimal')->assertJsonPath('data.plan.is_optimal', true)
            ->assertJsonPath('data.lines.0.actionable_now', true)->assertJsonPath('data.lines.0.justification', 'Única oferta comparable vigente.')
            ->assertJsonPath('data.shortfalls.0.reason', 'lead_time')->assertJsonPath('data.constraints.0.family', 'budget')
            ->assertJsonPath('data.production.status', 'not_configured')->assertJsonPath('data.comparison_coverage.offers_excluded', 1);
        $this->assertStringContainsString('no envía pedidos', $r->json('data.plan.note'));
        $this->actingAsTenantUser($user, $tenant)->getJson("/api/v1/supply/plans/{$runId}?actionable_now=1")->assertJsonCount(1, 'data.lines');
        $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/supply/plans')->assertOk()->assertJsonPath('data.0.id', $runId);
    }

    public function test_plans_are_isolated_between_companies(): void
    {
        [$tenantA, $userA] = $this->createTenantWithUser(TenantRole::Owner);
        [$tenantB, $userB] = $this->createTenantWithUser(TenantRole::Owner);
        $catA = $this->seedCatalog($tenantA);
        $runA = $this->withinTenant($tenantA, fn () => $this->seedCompletedPlan($tenantA, $catA));
        $this->actingAsTenantUser($userB, $tenantB)->getJson("/api/v1/supply/plans/{$runA}")->assertNotFound();
        $this->actingAsTenantUser($userB, $tenantB)->getJson('/api/v1/supply/plans')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsTenantUser($userA, $tenantA)->getJson("/api/v1/supply/plans/{$runA}")->assertOk();
        // un token de A con el header de B no abre el plan de A
        $cross = $this->actingAsTenantUser($userA, $tenantB)->getJson("/api/v1/supply/plans/{$runA}");
        $this->assertContains($cross->status(), [403, 404]);
    }

    public function test_cost_forecast_listing_has_envelope_and_explicit_empty_state(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);
        $cat = $this->seedCatalog($tenant);
        $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/supply/cost-forecasts')->assertOk()
            ->assertJsonPath('envelope.quality_status', 'insufficient_data')->assertJsonCount(0, 'data');
        $now = now();
        $runId = DB::table('core_runs')->insertGetId([
            'tenant_id' => $tenant->id, 'core' => 'cost', 'operation' => 'forecast', 'version' => 1, 'run_type' => 'production', 'status' => 'completed',
            'result_type' => 'forecast', 'quality_status' => 'ok', 'uncertainty_source' => 'empirical_holdout', 'as_of' => '2026-06-30', 'options' => '{}',
            'assumptions' => '[]', 'limitations' => '[]', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('cost_forecasts')->insert([
            'tenant_id' => $tenant->id, 'core_run_id' => $runId, 'product_id' => $cat['products']['p1'], 'cost_source' => 'purchase_history', 'currency' => 'USD',
            'unit' => 'kg', 'method' => 'drift', 'reference_method' => 'last_value', 'quality_status' => 'ok', 'n_obs' => 18, 'last_observed_on' => '2026-06-01',
            'last_observed_cost' => 18.5, 'points' => json_encode([['step' => 1, 'yhat' => 19.0, 'reference' => 18.5]]),
            'metrics' => json_encode(['drift' => ['holdout' => ['mae' => 0.0]], 'last_value' => ['holdout' => ['mae' => 1.0]]]), 'limitations' => '[]', 'created_at' => $now]);
        $r = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/supply/cost-forecasts')->assertOk();
        $r->assertJsonPath('data.0.method', 'drift')->assertJsonPath('data.0.points.0.reference', 18.5)->assertJsonPath('envelope.result_type', 'forecast')
            ->assertJsonPath('envelope.entity_keys', ['product_id', 'supplier_id']);
    }

    public function test_a_historical_cost_forecast_is_never_served_by_default(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);
        $cat = $this->seedCatalog($tenant);
        $now = now();
        $seed = function (string $runType, bool $current, string $asOf, float $yhat) use ($tenant, $cat, $now): int {
            $runId = DB::table('core_runs')->insertGetId([
                'tenant_id' => $tenant->id, 'core' => 'cost', 'operation' => 'forecast', 'version' => (int) DB::table('core_runs')->where('tenant_id', $tenant->id)->max('version') + 1,
                'run_type' => $runType, 'status' => 'completed', 'result_type' => 'forecast', 'quality_status' => 'ok', 'uncertainty_source' => 'empirical_holdout',
                'as_of' => $asOf, 'options' => '{}', 'assumptions' => '[]', 'limitations' => '[]', 'is_current' => $current, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('cost_forecasts')->insert([
                'tenant_id' => $tenant->id, 'core_run_id' => $runId, 'product_id' => $cat['products']['p1'], 'cost_source' => 'purchase_history', 'currency' => 'USD',
                'unit' => 'kg', 'method' => 'drift', 'reference_method' => 'last_value', 'quality_status' => 'ok', 'n_obs' => 18, 'last_observed_on' => '2026-06-01',
                'last_observed_cost' => 18.5, 'points' => json_encode([['step' => 1, 'yhat' => $yhat, 'reference' => 18.5]]), 'metrics' => '{}', 'limitations' => '[]', 'created_at' => $now]);

            return $runId;
        };
        $production = $seed('production', false, '2026-10-26', 19.0);   // sin vigente marcada (p. ej. tras una abstención)
        $historical = $seed('historical', false, '2026-06-30', 7.0);    // más reciente por id, pero retroactiva
        $api = $this->actingAsTenantUser($user, $tenant);

        $this->assertGreaterThan($production, $historical);
        $api->getJson('/api/v1/supply/cost-forecasts')->assertOk()->assertJsonPath('envelope.run_id', $production)->assertJsonPath('data.0.points.0.yhat', 19);
        $api->getJson('/api/v1/supply/cost-forecasts?run_id='.$historical)->assertOk()->assertJsonPath('envelope.run_id', $historical)->assertJsonPath('data.0.points.0.yhat', 7);
        DB::table('core_runs')->where('id', $production)->update(['run_type' => 'historical']);
        $api->getJson('/api/v1/supply/cost-forecasts')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('envelope.quality_status', 'insufficient_data');
    }

    public function test_the_latest_plan_is_never_a_historical_one(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Viewer);
        $cat = $this->seedCatalog($tenant);
        $production = $this->withinTenant($tenant, fn () => $this->seedCompletedPlan($tenant, $cat));
        $this->assertSame($production, $this->withinTenant($tenant, fn () => app(SupplyRunService::class)->latestPlan()?->id));
        DB::table('core_runs')->where('id', $production)->update(['is_current' => false]);
        $historical = DB::table('core_runs')->insertGetId([   // más reciente por id, pero retroactiva
            'tenant_id' => $tenant->id, 'core' => 'supply', 'operation' => 'plan', 'version' => 2, 'run_type' => 'historical', 'status' => 'completed',
            'result_type' => 'optimized_plan', 'quality_status' => 'degraded', 'uncertainty_source' => 'none', 'as_of' => '2026-06-30', 'options' => '{}',
            'assumptions' => '[]', 'limitations' => '[]', 'is_current' => false, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertGreaterThan($production, $historical);
        $this->assertSame($production, $this->withinTenant($tenant, fn () => app(SupplyRunService::class)->latestPlan()?->id));
        DB::table('core_runs')->where('id', $production)->update(['run_type' => 'historical']);
        $this->assertNull($this->withinTenant($tenant, fn () => app(SupplyRunService::class)->latestPlan()));
    }
}
