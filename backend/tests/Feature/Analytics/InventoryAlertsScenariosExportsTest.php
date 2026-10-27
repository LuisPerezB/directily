<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\TenantRole;
use App\Models\ForecastRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Exports\CsvExporter;
use App\Services\Scenarios\ScenarioSimulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\TestCase;

class InventoryAlertsScenariosExportsTest extends TestCase
{
    use AnalyticsFixtures;
    use RefreshDatabase;

    private const CUTOFF = '2026-09-30';

    /** @return array{0: Tenant, 1: User, 2: array, 3: ForecastRun} */
    private function scenarioData(TenantRole $role = TenantRole::Analyst): array
    {
        [$tenant, $user] = $this->createTenantWithUser($role);
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF, 60);
        $this->seedInventory($tenant, $cat, self::CUTOFF);
        $run = $this->seedForecastRun($tenant, $cat, self::CUTOFF);

        return [$tenant, $user, $cat, $run];
    }

    public function test_recommendations_listing_filters_and_sorting(): void
    {
        [$tenant, $user, $cat, $run] = $this->scenarioData(TenantRole::Viewer);
        $api = $this->actingAsTenantUser($user, $tenant);

        $all = $api->getJson('/api/v1/inventory/recommendations');
        $all->assertOk()->assertJsonPath('meta.total', 6)->assertJsonPath('run.id', $run->id)
            ->assertJsonPath('data.0.risk_level', 'critical')
            ->assertJsonPath('data.0.product.sku', 'P1-SKU')
            ->assertJsonPath('data.0.branch.name', 'Matriz Quito');
        $this->assertNotEmpty($all->json('disclaimer'));

        $api->getJson('/api/v1/inventory/recommendations?risk_level=critical,medium')->assertJsonPath('meta.total', 3);
        $api->getJson('/api/v1/inventory/recommendations?branch_id='.$cat['branches']['b2'])->assertJsonPath('meta.total', 3);
        $api->getJson('/api/v1/inventory/recommendations?category_id='.$cat['categories']['c2'])->assertJsonPath('meta.total', 2);
        $api->getJson('/api/v1/inventory/recommendations?search=varilla')->assertJsonPath('meta.total', 2);
        $api->getJson('/api/v1/inventory/recommendations?sort=-estimated_investment')->assertJsonPath('data.0.product.sku', 'P2-SKU');
        $api->getJson('/api/v1/inventory/recommendations?sort=id;drop')->assertStatus(422);
        $api->getJson('/api/v1/inventory/recommendations?risk_level=extreme')->assertStatus(422);
    }

    public function test_recommendation_status_update_is_registered_but_never_orders(): void
    {
        [$tenant, $user, , $run] = $this->scenarioData();
        $id = DB::table('recommendations')->where('forecast_run_id', $run->id)->where('risk_level', 'critical')->value('id');
        $viewer = $this->addUserToTenant($tenant, TenantRole::Viewer);

        $this->actingAsTenantUser($viewer, $tenant)->patchJson("/api/v1/inventory/recommendations/{$id}", ['status' => 'ordered'])->assertStatus(403);
        $this->actingAsTenantUser($user, $tenant)->patchJson("/api/v1/inventory/recommendations/{$id}", ['status' => 'ordered'])
            ->assertOk()->assertJsonPath('data.status', 'ordered')->assertJsonPath('data.product.sku', 'P1-SKU');
        $this->assertDatabaseHas('recommendations', ['id' => $id, 'status' => 'ordered', 'acknowledged_by' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'recommendation.status_changed']);
        $this->assertDatabaseCount('purchase_orders', 0);
        $this->actingAsTenantUser($user, $tenant)->patchJson("/api/v1/inventory/recommendations/{$id}", ['status' => 'executed'])->assertStatus(422);
    }

    public function test_inventory_summary(): void
    {
        [$tenant, $user] = $this->scenarioData();

        $response = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/inventory/summary');
        $response->assertOk()
            ->assertJsonPath('data.snapshot_date', self::CUTOFF)
            ->assertJsonPath('data.series_out_of_stock', 1)
            ->assertJsonPath('data.products_with_stock', 3)
            ->assertJsonPath('data.risk_counts.critical', 1)
            ->assertJsonPath('data.series_without_cost', 2);
        // Valor: p1 solo b2 con 50 × 6 + p2 ambas sucursales 2 × 50 × 18 (p3 sin costo).
        $this->assertEqualsWithDelta(50 * 6 + 2 * 50 * 18, $response->json('data.total_on_hand_value'), 0.01);
    }

    public function test_alerts_listing_and_acknowledgement(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);
        foreach ([['stockout_risk', 'critical'], ['forecast_drift', 'warning'], ['run_completed', 'info']] as [$type, $severity]) {
            DB::table('alerts')->insert([
                'tenant_id' => $tenant->id, 'type' => $type, 'severity' => $severity, 'title' => $type, 'message' => 'm',
                'payload' => '{}', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $api = $this->actingAsTenantUser($user, $tenant);

        $api->getJson('/api/v1/alerts')->assertOk()->assertJsonPath('meta.total', 3);
        $api->getJson('/api/v1/alerts?severity=critical')->assertJsonPath('meta.total', 1);
        $id = $api->getJson('/api/v1/alerts?type=forecast_drift')->json('data.0.id');
        $api->patchJson("/api/v1/alerts/{$id}", ['status' => 'acknowledged'])->assertOk()
            ->assertJsonPath('data.status', 'acknowledged')->assertJsonPath('data.acknowledged_by', $user->id);
        $api->getJson('/api/v1/alerts?status=open')->assertJsonPath('meta.total', 2);
        $api->patchJson("/api/v1/alerts/{$id}", ['status' => 'deleted'])->assertStatus(422);
    }

    public function test_scenario_simulation_endpoint(): void
    {
        [$tenant, $user, $cat, $run] = $this->scenarioData();

        $response = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/scenarios/simulate', [
            'horizon_days' => 30,
            'filters' => ['category_id' => $cat['categories']['c1']],
            'assumptions' => ['price_change_pct' => 0.1, 'demand_change_pct' => -0.05, 'cost_change_pct' => 0.02, 'purchase_budget' => 500],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.type', 'simulation')
            ->assertJsonPath('data.disclaimer', ScenarioSimulator::DISCLAIMER)
            ->assertJsonPath('data.base_run.id', $run->id)
            ->assertJsonPath('data.assumptions_used.price_change_pct', 0.1)
            ->assertJsonPath('data.budget.purchase_budget', 500);
        $base = $response->json('data.base');
        $scenario = $response->json('data.scenario');
        $this->assertEqualsWithDelta($base['units'] * 0.95, $scenario['units'], 0.01);
        $this->assertEqualsWithDelta($base['revenue'] * 0.95 * 1.1, $scenario['revenue'], 0.05);
        $this->assertGreaterThan(0, $base['purchase_need_cost']);
        $codes = array_column($response->json('data.risks'), 'code');
        $this->assertContains('budget_shortfall', $codes);
        $this->assertContains('stockout_risk', $codes);
        $this->assertNotContains('cost_unknown', $codes); // c1 solo tiene productos con costo
    }

    public function test_scenario_validation_permissions_and_crud_with_isolation(): void
    {
        [$tenant, $user, $cat] = $this->scenarioData();
        [$tenantB, $userB] = $this->createTenantWithUser(TenantRole::Analyst);
        $viewer = $this->addUserToTenant($tenant, TenantRole::Viewer);
        $payload = ['horizon_days' => 7, 'assumptions' => ['discount_pct' => 0.1]];

        $this->actingAsTenantUser($viewer, $tenant)->postJson('/api/v1/scenarios/simulate', $payload)->assertStatus(403);
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/scenarios/simulate', ['horizon_days' => 7, 'assumptions' => ['discount_pct' => 0.95]])
            ->assertStatus(422)->assertJsonValidationErrors('assumptions.discount_pct');
        $this->actingAsTenantUser($userB, $tenantB)->postJson('/api/v1/scenarios/simulate', ['horizon_days' => 7, 'filters' => ['branch_id' => $cat['branches']['b1']], 'assumptions' => []])
            ->assertStatus(422);

        $saved = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/scenarios', $payload + ['name' => 'Descuento 10%']);
        $saved->assertCreated()->assertJsonPath('data.name', 'Descuento 10%')->assertJsonPath('data.results.type', 'simulation');
        $id = $saved->json('data.id');
        $codes = array_column($saved->json('data.results.risks'), 'code');
        $this->assertContains('price_without_demand_assumption', $codes);
        $this->assertContains('cost_unknown', $codes);

        $this->actingAsTenantUser($viewer, $tenant)->getJson('/api/v1/scenarios')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAsTenantUser($userB, $tenantB)->getJson("/api/v1/scenarios/{$id}")->assertNotFound();
        $this->actingAsTenantUser($userB, $tenantB)->deleteJson("/api/v1/scenarios/{$id}")->assertNotFound();
        $this->actingAsTenantUser($userB, $tenantB)->getJson('/api/v1/scenarios')->assertJsonPath('meta.total', 0);
        $this->actingAsTenantUser($user, $tenant)->deleteJson("/api/v1/scenarios/{$id}")->assertNoContent();
        $this->assertDatabaseCount('scenarios', 0);
    }

    public function test_scenarios_require_plan_feature(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner, ['plan_overrides' => ['features' => ['scenarios' => false]]]);

        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/scenarios/simulate', ['horizon_days' => 7, 'assumptions' => []])
            ->assertStatus(402)->assertJsonPath('code', 'plan_limit_exceeded');
    }

    public function test_scenario_without_forecast_reports_missing_base(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);

        $response = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/scenarios/simulate', ['horizon_days' => 30, 'assumptions' => []]);
        $response->assertOk()->assertJsonPath('data.base_run', null)->assertJsonPath('data.base.units', 0);
        $this->assertContains('low_data_quality', array_column($response->json('data.risks'), 'code'));
    }

    public function test_csv_exports_with_bom_delimiter_and_audit(): void
    {
        [$tenant, $user, $cat, $run] = $this->scenarioData();
        $api = $this->actingAsTenantUser($user, $tenant);

        $response = $api->get('/api/v1/exports/forecasts.csv?horizon=7');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = array_values(array_filter(explode("\n", trim(substr($csv, 3)))));
        $this->assertSame('sku,producto,categoria,unidades_periodo_anterior,unidades_pronosticadas,variacion_pct,tendencia,ingresos_estimados,version_pronostico,fecha_corte', $lines[0]);
        $this->assertCount(4, $lines);

        $weekly = $api->get('/api/v1/exports/forecasts.csv?horizon=30&granularity=week&delimiter=;')->streamedContent();
        $this->assertStringContainsString('sku;producto;categoria;periodo', $weekly);
        $this->assertStringContainsString('2026-09-28', $weekly);

        $recs = $api->get('/api/v1/exports/recommendations.csv')->streamedContent();
        $this->assertSame(7, count(array_filter(explode("\n", trim($recs)))));
        $this->assertStringContainsString('critical', $recs);

        $accuracy = $api->get('/api/v1/exports/accuracy.csv?level=overall')->streamedContent();
        $this->assertStringContainsString('LightGBM global', $accuracy);

        $sales = $api->get('/api/v1/exports/sales-summary.csv?granularity=month&date_from=2026-08-01&date_to='.self::CUTOFF)->streamedContent();
        $this->assertStringContainsString('2026-08-01', $sales);
        $this->assertStringContainsString('2026-09-01', $sales);

        $this->assertSame(5, DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('action', 'data.exported')->count());
    }

    public function test_exports_require_permission_and_forecast(): void
    {
        [$tenant] = $this->createTenantWithUser();
        $viewer = $this->addUserToTenant($tenant, TenantRole::Viewer);
        $analyst = $this->addUserToTenant($tenant, TenantRole::Analyst);

        $this->actingAsTenantUser($viewer, $tenant)->get('/api/v1/exports/forecasts.csv')->assertStatus(403);
        $this->actingAsTenantUser($analyst, $tenant)->getJson('/api/v1/exports/forecasts.csv')->assertNotFound();
    }

    public function test_csv_cells_are_protected_against_formula_injection(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", CsvExporter::cell('=HYPERLINK("x")'));
        $this->assertSame("'@SUM(A1)", CsvExporter::cell('@SUM(A1)'));
        $this->assertSame('-12.5', CsvExporter::cell('-12.5'));
        $this->assertSame(-3, CsvExporter::cell(-3));
        $this->assertSame('sí', CsvExporter::cell(true));
    }

    public function test_isolation_for_inventory_alerts_and_exports(): void
    {
        [$tenantA, , $catA, $runA] = $this->scenarioData();
        [$tenantB, $userB] = $this->createTenantWithUser(TenantRole::Owner);
        $recId = DB::table('recommendations')->where('forecast_run_id', $runA->id)->value('id');
        $alertId = DB::table('alerts')->insertGetId([
            'tenant_id' => $tenantA->id, 'type' => 'stockout_risk', 'severity' => 'critical', 'title' => 't', 'message' => 'm',
            'payload' => '{}', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $apiB = $this->actingAsTenantUser($userB, $tenantB);

        $apiB->getJson('/api/v1/inventory/recommendations')->assertOk()->assertJsonCount(0, 'data');
        $apiB->getJson("/api/v1/inventory/recommendations?run_id={$runA->id}")->assertNotFound();
        $apiB->patchJson("/api/v1/inventory/recommendations/{$recId}", ['status' => 'dismissed'])->assertNotFound();
        $apiB->getJson('/api/v1/inventory/summary')->assertOk()->assertJsonPath('data.has_inventory', false);
        $apiB->getJson('/api/v1/alerts')->assertOk()->assertJsonPath('meta.total', 0);
        $apiB->patchJson("/api/v1/alerts/{$alertId}", ['status' => 'resolved'])->assertNotFound();
        $apiB->getJson("/api/v1/exports/forecasts.csv?run_id={$runA->id}")->assertNotFound();
        $apiB->getJson('/api/v1/exports/sales-summary.csv?product_id='.$catA['products']['p1'])->assertStatus(422);
        $this->assertDatabaseHas('alerts', ['id' => $alertId, 'status' => 'open']);
        $this->assertDatabaseHas('recommendations', ['id' => $recId, 'status' => 'open']);
    }
}
