<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\ForecastRun;
use App\Services\Analytics\ProductForecastQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\TestCase;

/**
 * Calidad de los rankings de productos: un cambio porcentual sobre una base muy
 * pequeña (1 → 4 unidades = "+300 %") no debe presentarse como tendencia, y los
 * intervalos del total del horizonte se toman de las filas granularity='horizon'.
 */
class ProductRankingQualityTest extends TestCase
{
    use AnalyticsFixtures;
    use RefreshDatabase;

    private const CUTOFF = '2026-09-30';

    /** Agrega un producto con 1 unidad vendida en la ventana histórica y 4/día pronosticadas. */
    private function addLowBaseProduct(int $tenantId, array $cat, ForecastRun $run): int
    {
        $now = now();
        $productId = (int) DB::table('products')->insertGetId([
            'tenant_id' => $tenantId, 'category_id' => $cat['categories']['c2'], 'sku' => 'P4-SKU',
            'name' => 'Lijadora de baja rotación', 'unit' => 'unidad', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $branchId = $cat['branches']['b1'];
        $saleId = DB::table('sales')->insertGetId([
            'tenant_id' => $tenantId, 'branch_id' => $branchId, 'document_type' => 'invoice', 'document_number' => 'LOW-1',
            'status' => 'completed', 'issued_at' => self::CUTOFF.' 10:00:00', 'sale_date' => self::CUTOFF, 'currency' => 'USD',
            'source_hash' => str_repeat('c', 64), 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('sale_items')->insert([
            'tenant_id' => $tenantId, 'sale_id' => $saleId, 'line_number' => 1, 'product_id' => $productId,
            'branch_id' => $branchId, 'sale_date' => self::CUTOFF, 'quantity' => 1, 'unit_price' => 50,
            'discount_amount' => 0, 'net_amount' => 50, 'tax_amount' => 0, 'is_return' => false,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $rows = [];
        for ($step = 1; $step <= 7; $step++) {
            $date = CarbonImmutable::parse(self::CUTOFF)->addDays($step)->toDateString();
            foreach ([["series:{$branchId}:{$productId}", 'series', $branchId], ["product:{$productId}", 'product', null]] as [$key, $level, $b]) {
                $rows[] = [
                    'tenant_id' => $tenantId, 'forecast_run_id' => $run->id, 'node_key' => $key, 'level' => $level,
                    'branch_id' => $b, 'product_id' => $productId, 'category_id' => $cat['categories']['c2'],
                    'granularity' => 'day', 'target_date' => $date, 'period_days' => 1, 'horizon_step' => $step,
                    'yhat' => 4, 'model_family' => 'croston_sba', 'unit_price_assumed' => 50, 'revenue_yhat' => 200,
                ];
            }
        }
        DB::table('forecast_values')->insert($rows);

        return $productId;
    }

    public function test_low_base_products_are_flagged_sorted_last_and_excluded_from_top_movers(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF, 60);
        $run = $this->seedForecastRun($tenant, $cat, self::CUTOFF);
        $this->addLowBaseProduct($tenant->id, $cat, $run);
        $api = $this->actingAsTenantUser($user, $tenant);

        $rows = collect($api->getJson('/api/v1/forecasts/products?horizon=7&sort=-change_pct')->assertOk()->json('data'));
        $low = $rows->firstWhere('product.sku', 'P4-SKU');
        $this->assertTrue($low['low_base']);
        $this->assertGreaterThan(10, $low['change_pct']); // 1 → 28 unidades: el cálculo se conserva...
        $this->assertSame('P4-SKU', $rows->last()['product']['sku']); // ...pero no encabeza el ranking.
        $this->assertFalse($rows->firstWhere('product.sku', 'P1-SKU')['low_base']);

        $top = $api->getJson('/api/v1/dashboard/summary?horizon=7')->assertOk()->json('data.top_increasing');
        $this->assertNotContains('P4-SKU', array_column($top, 'sku'));
        $this->assertSame('P1-SKU', $top[0]['sku']);

        // Ordenar categorías por variación usa la misma expresión (debe ser SQL válido).
        $api->getJson('/api/v1/forecasts/categories?horizon=7&sort=-change_pct')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(10, ProductForecastQuery::MIN_BASE_UNITS);
    }

    public function test_product_horizon_interval_comes_from_engine_horizon_rows(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF, 60);
        $run = $this->seedForecastRun($tenant, $cat, self::CUTOFF);
        $p1 = $cat['products']['p1'];
        DB::table('forecast_values')->insert([
            'tenant_id' => $tenant->id, 'forecast_run_id' => $run->id, 'node_key' => "product:{$p1}", 'level' => 'product',
            'product_id' => $p1, 'category_id' => $cat['categories']['c1'], 'granularity' => 'horizon',
            'target_date' => CarbonImmutable::parse(self::CUTOFF)->addDays(7)->toDateString(), 'period_days' => 7,
            'horizon_step' => 7, 'yhat' => 100, 'lo_80' => 85.5, 'hi_80' => 116.25,
        ]);
        $api = $this->actingAsTenantUser($user, $tenant);

        $row = collect($api->getJson('/api/v1/forecasts/products?horizon=7')->assertOk()->json('data'))->firstWhere('product.sku', 'P1-SKU');
        $this->assertEqualsWithDelta(85.5, $row['lo_80'], 0.001);
        $this->assertEqualsWithDelta(116.25, $row['hi_80'], 0.001);

        // Con filtro de sucursal (nivel serie) no hay intervalo de horizonte publicado.
        $branchRow = collect($api->getJson('/api/v1/forecasts/products?horizon=7&branch_id='.$cat['branches']['b1'])->json('data'))
            ->firstWhere('product.sku', 'P1-SKU');
        $this->assertNull($branchRow['lo_80']);
    }
}
