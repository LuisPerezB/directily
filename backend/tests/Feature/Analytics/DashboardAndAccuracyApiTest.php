<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\TenantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\TestCase;

class DashboardAndAccuracyApiTest extends TestCase
{
    use AnalyticsFixtures;
    use RefreshDatabase;

    private const CUTOFF = '2026-09-30';

    public function test_dashboard_without_forecast_reports_data_status_and_null_kpis(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Viewer);

        $response = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/dashboard/summary');

        $response->assertOk()
            ->assertJsonPath('data.run', null)
            ->assertJsonPath('data.horizon', 30)
            ->assertJsonPath('data.kpis.forecast_units', null)
            ->assertJsonPath('data.data_status.has_sales', false)
            ->assertJsonPath('data.data_status.has_forecast', false)
            ->assertJsonPath('data.top_increasing', []);
        $this->assertNotEmpty($response->json('data.data_status.warnings'));
    }

    public function test_dashboard_with_forecast_answers_key_questions(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF, 60);
        $this->seedInventory($tenant, $cat, self::CUTOFF);
        $run = $this->seedForecastRun($tenant, $cat, self::CUTOFF);
        DB::table('alerts')->insert([
            'tenant_id' => $tenant->id, 'type' => 'stockout_risk', 'severity' => 'critical', 'title' => 'Riesgo de quiebre',
            'message' => 'Cemento en Matriz', 'payload' => '{}', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/dashboard/summary?horizon=7');
        $response->assertOk()
            ->assertJsonPath('data.run.id', $run->id)
            ->assertJsonPath('data.kpis.stockout_risk_products', 1)
            ->assertJsonPath('data.kpis.backtest_wape', 0.23)
            ->assertJsonPath('data.kpis.baseline_wape', 0.31)
            ->assertJsonPath('data.top_increasing.0.sku', 'P1-SKU')
            ->assertJsonPath('data.top_decreasing.0.sku', 'P2-SKU')
            ->assertJsonPath('data.stockout_risks.0.risk_level', 'critical')
            ->assertJsonPath('data.stockout_risks.0.branch_name', 'Matriz Quito')
            ->assertJsonPath('data.alerts.0.type', 'stockout_risk')
            ->assertJsonPath('data.data_status.has_forecast', true)
            ->assertJsonPath('data.data_status.has_inventory', true);

        $kpis = $response->json('data.kpis');
        $expectedUnits = 0.0;
        $expectedPrevious = 0.0;
        foreach (['b1', 'b2'] as $b) {
            foreach (['p1', 'p2', 'p3'] as $p) {
                $expectedUnits += $this->avgDailyQty($b, $p) * $this->growth[$p] * 7;
                $expectedPrevious += $this->avgDailyQty($b, $p) * 7;
            }
        }
        $this->assertEqualsWithDelta($expectedUnits, $kpis['forecast_units'], 0.01);
        $this->assertEqualsWithDelta($expectedPrevious, $kpis['previous_period_units'], 0.01);
        // Inversión: (116,45 × 6) cemento b1 + 2 × (66,45 × 18) varilla.
        $this->assertEqualsWithDelta(116.45 * 6 + 2 * 66.45 * 18, $kpis['purchase_investment'], 0.01);
        $this->assertNull($kpis['forecast_units_lo_80']);
    }

    public function test_dashboard_warns_about_new_sales_after_forecast_and_failed_runs(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF, 30);
        $this->seedForecastRun($tenant, $cat, '2026-09-20');
        DB::table('forecast_runs')->insert([
            'tenant_id' => $tenant->id, 'version' => 99, 'status' => 'failed', 'run_type' => 'production',
            'error_message' => 'x', 'options' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $warnings = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/dashboard/summary')->json('data.data_status.warnings');

        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains($w, 'ventas posteriores')));
        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains($w, 'falló')));
        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains($w, 'inventario')));
    }

    public function test_accuracy_summary_with_explanations(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF, 30);
        $this->seedInventory($tenant, $cat, self::CUTOFF);
        $run = $this->seedForecastRun($tenant, $cat, self::CUTOFF);

        $response = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/accuracy/summary');
        $response->assertOk()
            ->assertJsonPath('data.run.id', $run->id)
            ->assertJsonPath('data.backtest.selected.wape', 0.23)
            ->assertJsonPath('data.backtest.selected.model_family', 'lightgbm_global')
            ->assertJsonPath('data.backtest.baseline.model_family', 'seasonal_naive')
            ->assertJsonPath('data.backtest.n_windows', 3)
            ->assertJsonCount(3, 'data.backtest.by_horizon')
            ->assertJsonPath('data.backtest.by_horizon.0.horizon_days', 7)
            ->assertJsonPath('data.backtest.by_horizon.0.baseline_wape', 0.25)
            ->assertJsonCount(2, 'data.backtest.by_demand_class')
            ->assertJsonPath('data.backtest.by_branch.0.branch_name', 'Matriz Quito')
            ->assertJsonPath('data.realized', null);

        $text = implode(' ', $response->json('data.explanation'));
        $this->assertStringContainsString('23%', $text);
        $this->assertStringContainsString('31%', $text);
        $this->assertStringContainsString('8 puntos porcentuales', $text);
        $this->assertStringContainsString('quiebre de stock', $text);
        $this->assertStringNotContainsString('R²', $text);
        $this->assertStringNotContainsString('confianza del', $text);
    }

    public function test_accuracy_summary_uses_holdout_as_primary_and_selection_windows_as_secondary(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF, 30);
        $run = $this->seedForecastRun($tenant, $cat, self::CUTOFF);
        // Así escribe el motor: 'backtest' = período final reservado; 'selection_windows' = ventanas que eligieron el modelo.
        DB::table('model_evaluations')->where('forecast_run_id', $run->id)->update([
            'extra' => json_encode(['composite' => false, 'evaluation_scope' => 'holdout', 'independent_holdout' => true]),
            'n_windows' => 1, 'cutoffs' => json_encode(['2026-07-02']),
        ]);
        $base = ['tenant_id' => $tenant->id, 'forecast_run_id' => $run->id, 'evaluation_type' => 'selection_windows',
            'level' => 'overall', 'mae' => 1.0, 'rmse' => 1.4, 'bias' => -0.08, 'coverage_80' => 0.79, 'coverage_95' => 0.94,
            'n_obs' => 900, 'n_windows' => 3, 'cutoffs' => json_encode(['2026-02-05', '2026-03-05', '2026-04-02']),
            'extra' => json_encode(['evaluation_scope' => 'selection_windows']), 'created_at' => now()];
        DB::table('model_evaluations')->insert([
            $base + ['model_family' => 'selection', 'is_selected' => true, 'is_baseline' => false, 'wape' => 0.21],
            $base + ['model_family' => 'seasonal_naive', 'is_selected' => false, 'is_baseline' => true, 'wape' => 0.30],
        ]);

        $response = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/accuracy/summary');
        $response->assertOk()
            ->assertJsonPath('data.backtest.selected.wape', 0.23)
            ->assertJsonPath('data.backtest.baseline.wape', 0.31)
            ->assertJsonPath('data.backtest.evaluation_scope', 'holdout')
            ->assertJsonPath('data.backtest.n_windows', 1)
            ->assertJsonPath('data.backtest.cutoffs', ['2026-07-02'])
            ->assertJsonPath('data.backtest.selection_windows.selected.wape', 0.21)
            ->assertJsonPath('data.backtest.selection_windows.baseline.wape', 0.3)
            ->assertJsonPath('data.backtest.selection_windows.n_windows', 3);
        $text = implode(' ', $response->json('data.explanation'));
        $this->assertStringContainsString('período final reservado', $text);
        $this->assertStringContainsString('ventanas usadas para elegir el modelo el error fue 21%', $text);

        $this->actingAsTenantUser($user, $tenant)
            ->getJson("/api/v1/model-evaluations?run_id={$run->id}&evaluation_type=selection_windows")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.evaluation_type', 'selection_windows');
    }

    public function test_accuracy_comparison_against_actual_sales_and_realized_metrics(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        // Ventas hasta el 30/09; ejecución histórica con corte 20/09 → 10 días observados.
        $this->seedSales($tenant, $cat, self::CUTOFF, 60);
        $this->seedForecastRun($tenant, $cat, self::CUTOFF, ['version' => 2]);
        $historical = $this->seedForecastRun($tenant, $cat, '2026-09-20', ['version' => 1, 'run_type' => 'historical', 'is_current' => false, 'horizon' => 30]);
        $p1 = $cat['products']['p1'];
        DB::table('forecast_actuals')->insert([
            ['tenant_id' => $tenant->id, 'forecast_run_id' => $historical->id, 'node_key' => "series:{$cat['branches']['b1']}:{$p1}", 'level' => 'series',
                'branch_id' => $cat['branches']['b1'], 'product_id' => $p1, 'target_date' => '2026-09-21', 'horizon_step' => 1, 'yhat' => 12, 'actual' => 10, 'abs_error' => 2, 'is_censored' => false],
            ['tenant_id' => $tenant->id, 'forecast_run_id' => $historical->id, 'node_key' => "series:{$cat['branches']['b1']}:{$p1}", 'level' => 'series',
                'branch_id' => $cat['branches']['b1'], 'product_id' => $p1, 'target_date' => '2026-09-22', 'horizon_step' => 2, 'yhat' => 12, 'actual' => 0, 'abs_error' => 12, 'is_censored' => true],
        ]);
        $api = $this->actingAsTenantUser($user, $tenant);

        $comparison = $api->getJson("/api/v1/accuracy/comparison?run_id={$historical->id}&product_id={$p1}");
        $comparison->assertOk()->assertJsonCount(30, 'data.points')->assertJsonPath('data.metrics.n_obs', 10);
        $points = collect($comparison->json('data.points'));
        $this->assertNotNull($points->firstWhere('date', '2026-09-30')['actual']);
        $this->assertNull($points->firstWhere('date', '2026-10-01')['actual']);
        // 21–30/09: 10 días con 2 de fin de semana → real = 15×10 + 4×2; pronóstico = (15 + 8/7) × 1,2 por día.
        $actual = 15 * 10 + 4 * 2;
        $forecast = (15 + 8 / 7) * 1.2 * 10;
        $this->assertEqualsWithDelta(($forecast - $actual) / $actual, $comparison->json('data.metrics.bias'), 0.001);

        $api->getJson('/api/v1/accuracy/comparison')->assertStatus(422);

        $realized = $api->getJson('/api/v1/accuracy/summary')->json('data.realized');
        $this->assertSame(1, $realized['n_obs']); // el día censurado se excluye
        $this->assertEqualsWithDelta(0.2, $realized['wape'], 0.0001);
        $this->assertSame(1, $realized['by_run'][0]['version']);
    }

    public function test_model_evaluations_and_registry_listing(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        $run = $this->seedForecastRun($tenant, $cat, self::CUTOFF);
        $api = $this->actingAsTenantUser($user, $tenant);

        $api->getJson('/api/v1/model-evaluations')->assertStatus(422);
        $api->getJson("/api/v1/model-evaluations?run_id={$run->id}&level=overall")
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.evaluation_type', 'backtest');
        $api->getJson('/api/v1/models')->assertOk()->assertJsonPath('data.0.model_family', 'lightgbm_global')
            ->assertJsonPath('data.0.label', 'LightGBM global (machine learning)');
    }

    public function test_isolation_for_dashboard_and_accuracy(): void
    {
        [$tenantA] = $this->createTenantWithUser();
        [$tenantB, $userB] = $this->createTenantWithUser();
        $catA = $this->seedCatalog($tenantA);
        $this->seedSales($tenantA, $catA, self::CUTOFF, 30);
        $runA = $this->seedForecastRun($tenantA, $catA, self::CUTOFF);
        $apiB = $this->actingAsTenantUser($userB, $tenantB);

        $apiB->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.run', null)->assertJsonPath('data.data_status.has_sales', false);
        $apiB->getJson("/api/v1/accuracy/summary?run_id={$runA->id}")->assertNotFound();
        $apiB->getJson("/api/v1/accuracy/comparison?run_id={$runA->id}")->assertNotFound();
        $apiB->getJson("/api/v1/model-evaluations?run_id={$runA->id}")->assertNotFound();
        $apiB->getJson('/api/v1/models')->assertOk()->assertJsonCount(0, 'data');
        $apiB->getJson('/api/v1/dashboard/summary?branch_id='.$catA['branches']['b1'])->assertStatus(422);
        $apiB->getJson('/api/v1/reports/forecast-summary')->assertOk()->assertJsonPath('data.run', null);
    }

    public function test_report_forecast_summary(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF, 30);
        $this->seedForecastRun($tenant, $cat, self::CUTOFF);

        $response = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/reports/forecast-summary?horizon=30');
        $response->assertOk()->assertJsonPath('data.horizon', 30)->assertJsonPath('data.tenant.currency', 'USD');
        $this->assertStringContainsString('próximos 30 días', $response->json('data.highlights.0'));
        $this->assertNotEmpty($response->json('data.accuracy.explanation'));
        $this->assertCount(3, $response->json('data.disclaimers'));
    }
}
