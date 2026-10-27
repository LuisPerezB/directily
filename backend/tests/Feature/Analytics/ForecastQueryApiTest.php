<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\TenantRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\TestCase;

class ForecastQueryApiTest extends TestCase
{
    use AnalyticsFixtures;
    use RefreshDatabase;

    private const CUTOFF = '2026-09-30';

    public function test_series_total_returns_history_with_zero_fill_and_forecast(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Viewer);
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF, 60);
        $run = $this->seedForecastRun($tenant, $cat, self::CUTOFF);

        $response = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/forecasts/series?horizon=7&history_days=90');

        $response->assertOk()
            ->assertJsonPath('data.node.key', 'total')
            ->assertJsonPath('data.node.label', 'Total empresa')
            ->assertJsonPath('data.meta.run_id', $run->id)
            ->assertJsonPath('data.meta.cutoff_date', self::CUTOFF)
            ->assertJsonPath('data.meta.intervals_available', true)
            ->assertJsonCount(90, 'data.history')
            ->assertJsonCount(7, 'data.forecast');

        $history = collect($response->json('data.history'));
        // 30 días previos sin ventas se completan con 0.
        $this->assertSame(0.0, (float) $history->first()['units']);
        // El último día excluye la factura anulada y la nota de crédito (500 unidades cada una).
        $lastDay = CarbonImmutable::parse(self::CUTOFF);
        $expected = 0.0;
        foreach (['b1', 'b2'] as $b) {
            foreach (['p1', 'p2', 'p3'] as $p) {
                $expected += $this->dailyQty($b, $p, $lastDay);
            }
        }
        $this->assertEqualsWithDelta($expected, (float) $history->last()['units'], 0.0001);

        $first = $response->json('data.forecast.0');
        $this->assertSame('2026-10-01', $first['date']);
        $this->assertNotNull($first['lo_80']);
        $this->assertGreaterThan($first['lo_80'], $first['yhat']);
    }

    public function test_series_node_selection_and_stockout_flags(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF, 30);
        $this->seedInventory($tenant, $cat, self::CUTOFF);
        $this->seedForecastRun($tenant, $cat, self::CUTOFF);
        $b1 = $cat['branches']['b1'];
        $p1 = $cat['products']['p1'];
        $c1 = $cat['categories']['c1'];
        $api = $this->actingAsTenantUser($user, $tenant);

        $series = $api->getJson("/api/v1/forecasts/series?branch_id={$b1}&product_id={$p1}&history_days=7");
        $series->assertOk()
            ->assertJsonPath('data.node.key', "series:{$b1}:{$p1}")
            ->assertJsonPath('data.node.label', 'Cemento Portland 50kg — Matriz Quito')
            ->assertJsonPath('data.meta.model_family', 'lightgbm_global');
        $this->assertTrue(collect($series->json('data.history'))->last()['stockout']);

        $api->getJson("/api/v1/forecasts/series?product_id={$p1}")->assertJsonPath('data.node.key', "product:{$p1}");
        $api->getJson("/api/v1/forecasts/series?category_id={$c1}")->assertJsonPath('data.node.key', "category:{$c1}");
        $api->getJson("/api/v1/forecasts/series?branch_id={$b1}")->assertJsonPath('data.node.key', "branch:{$b1}");
        $branchCategory = $api->getJson("/api/v1/forecasts/series?branch_id={$b1}&category_id={$c1}&history_days=7");
        $branchCategory->assertJsonPath('data.node.key', "branch_category:{$b1}:{$c1}");
        // Nivel agregado: proporción de series sin stock (1 de 2 en la categoría c1 de b1).
        $this->assertEqualsWithDelta(0.5, collect($branchCategory->json('data.history'))->last()['stockout_share'], 0.0001);
    }

    public function test_weekly_granularity_uses_precomputed_values_or_aggregates_without_intervals(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF, 30);
        $this->seedForecastRun($tenant, $cat, self::CUTOFF, ['weekly_total' => true]);
        $api = $this->actingAsTenantUser($user, $tenant);

        // Total: semanas precalculadas por el motor (con intervalos).
        $total = $api->getJson('/api/v1/forecasts/series?granularity=week&horizon=30');
        $total->assertOk()->assertJsonPath('data.meta.intervals_available', true);
        $this->assertSame(7, $total->json('data.forecast.0.period_days'));

        // Producto: sin semanas precalculadas → agregación de días sin intervalos.
        $p1 = $cat['products']['p1'];
        $product = $api->getJson("/api/v1/forecasts/series?granularity=week&horizon=30&product_id={$p1}");
        $product->assertOk()->assertJsonPath('data.meta.intervals_available', false)->assertJsonPath('data.forecast.0.lo_80', null);
        $sum = array_sum(array_column($product->json('data.forecast'), 'yhat'));
        $expectedDaily = ($this->avgDailyQty('b1', 'p1') + $this->avgDailyQty('b2', 'p1')) * 1.2;
        $this->assertEqualsWithDelta($expectedDaily * 30, $sum, 0.01);
        $this->assertNotEmpty($product->json('data.meta.notes'));

        $monthly = $api->getJson('/api/v1/forecasts/series?granularity=month&horizon=90&history_days=120');
        $monthly->assertOk();
        $this->assertSame('2026-10-01', $monthly->json('data.forecast.0.date'));
        $this->assertSame('2026-06-01', $monthly->json('data.history.0.date'));
    }

    public function test_series_without_forecast_returns_history_only(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF, 10);

        $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/forecasts/series?history_days=10')
            ->assertOk()
            ->assertJsonPath('data.meta.has_forecast', false)
            ->assertJsonPath('data.meta.run_id', null)
            ->assertJsonCount(10, 'data.history')
            ->assertJsonCount(0, 'data.forecast');
    }

    public function test_products_ranking_trend_sort_search_and_pagination(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF, 120);
        $this->seedForecastRun($tenant, $cat, self::CUTOFF);
        $api = $this->actingAsTenantUser($user, $tenant);

        $response = $api->getJson('/api/v1/forecasts/products?horizon=28&sort=-change_pct');
        $response->assertStatus(422); // 28 no es un horizonte permitido

        $response = $api->getJson('/api/v1/forecasts/products?horizon=7&sort=-change_pct');
        $response->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('horizon', 7);
        $rows = collect($response->json('data'));
        $this->assertSame(['P1-SKU', 'P3-SKU', 'P2-SKU'], $rows->pluck('product.sku')->all());
        $p1 = $rows->firstWhere('product.sku', 'P1-SKU');
        $this->assertSame('up', $p1['trend']);
        $this->assertEqualsWithDelta(0.2, $p1['change_pct'], 0.001);
        $this->assertSame('lightgbm_global', $p1['model_family']);
        $this->assertSame('smooth', $p1['demand_class']);
        $this->assertEqualsWithDelta(0.15, $p1['wape'], 0.0001);
        $this->assertSame('Cementos', $p1['product']['category']['name']);
        $this->assertSame('down', $rows->firstWhere('product.sku', 'P2-SKU')['trend']);
        $this->assertSame('flat', $rows->firstWhere('product.sku', 'P3-SKU')['trend']);

        $api->getJson('/api/v1/forecasts/products?horizon=7&search=varilla')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.product.sku', 'P2-SKU');
        $api->getJson('/api/v1/forecasts/products?horizon=7&per_page=2')->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 2);
        $api->getJson('/api/v1/forecasts/products?horizon=7&category_id='.$cat['categories']['c2'])->assertJsonPath('meta.total', 1);
        $branch = $api->getJson('/api/v1/forecasts/products?horizon=7&sort=-forecast_units&branch_id='.$cat['branches']['b2']);
        $branch->assertOk();
        $this->assertEqualsWithDelta($this->avgDailyQty('b2', 'p1') * 1.2 * 7, $branch->json('data.0.forecast_units'), 0.01);
        $api->getJson('/api/v1/forecasts/products?sort=drop_table')->assertStatus(422);
    }

    public function test_categories_listing(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF, 60);
        $this->seedForecastRun($tenant, $cat, self::CUTOFF);

        $response = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/forecasts/categories?horizon=30');
        $response->assertOk()->assertJsonCount(2, 'data');
        $cementos = collect($response->json('data'))->firstWhere('category.name', 'Cementos');
        $this->assertNotNull($cementos['forecast_revenue']);
        $this->assertContains($cementos['trend'], ['up', 'down', 'flat']);
    }

    public function test_tenant_isolation_for_forecast_endpoints(): void
    {
        [$tenantA, $userA] = $this->createTenantWithUser();
        [$tenantB, $userB] = $this->createTenantWithUser();
        $catA = $this->seedCatalog($tenantA);
        $this->seedSales($tenantA, $catA, self::CUTOFF, 30);
        $runA = $this->seedForecastRun($tenantA, $catA, self::CUTOFF);
        $apiB = $this->actingAsTenantUser($userB, $tenantB);

        $apiB->getJson("/api/v1/forecasts/series?run_id={$runA->id}")->assertNotFound();
        $apiB->getJson('/api/v1/forecasts/series?product_id='.$catA['products']['p1'])->assertStatus(422);
        $apiB->getJson('/api/v1/forecasts/series')->assertOk()->assertJsonPath('data.meta.run_id', null)->assertJsonCount(0, 'data.history');
        $apiB->getJson('/api/v1/forecasts/products')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('run', null);
        $apiB->getJson("/api/v1/forecasts/products?run_id={$runA->id}")->assertNotFound();
        $apiB->getJson('/api/v1/forecasts/categories')->assertOk()->assertJsonCount(0, 'data');
        $apiB->getJson('/api/v1/forecasts/products?branch_id='.$catA['branches']['b1'])->assertStatus(422);

        // A sí ve su propia información.
        $this->actingAsTenantUser($userA, $tenantA)->getJson('/api/v1/forecasts/products')->assertJsonPath('meta.total', 3);
    }

    public function test_viewer_role_can_read_but_requires_membership(): void
    {
        [$tenant] = $this->createTenantWithUser();
        $viewer = $this->addUserToTenant($tenant, TenantRole::Viewer);
        $this->actingAsTenantUser($viewer, $tenant)->getJson('/api/v1/forecasts/series')->assertOk();
        $this->actingAsTenantUser($viewer, $tenant)->withHeader('X-Tenant-ID', '')->getJson('/api/v1/forecasts/series')->assertStatus(400);
    }
}
