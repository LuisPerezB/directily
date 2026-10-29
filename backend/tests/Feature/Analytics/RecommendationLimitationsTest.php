<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\TenantRole;
use App\Services\Scenarios\ScenarioBaselineLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\TestCase;

/**
 * Recomendaciones sin base suficiente (validación 2026-10-28, F1–F3): el motor puede publicar
 * cantidades y probabilidad en NULL con la limitación en `assumptions`.
 */
class RecommendationLimitationsTest extends TestCase
{
    use AnalyticsFixtures;
    use RefreshDatabase;

    private const CUTOFF = '2026-09-30';

    public function test_null_quantities_are_exposed_and_excluded_from_scenarios(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF, 60);
        $this->seedInventory($tenant, $cat, self::CUTOFF);
        $run = $this->seedForecastRun($tenant, $cat, self::CUTOFF);
        $recs = DB::table('recommendations')->where('forecast_run_id', $run->id);

        $short = (clone $recs)->where('branch_id', $cat['branches']['b1'])->where('product_id', $cat['products']['p1']);
        $short->update([
            'suggested_qty' => null, 'demand_lead_time' => null, 'safety_stock' => null, 'reorder_point' => null,
            'estimated_investment' => null, 'stockout_probability' => null, 'demand_lead_time_std' => null,
            'assumptions' => json_encode(['coverage_insufficient' => true, 'limitations' => ['El pronóstico disponible cubre 7 de los 37 días necesarios.']]),
        ]);
        $withOrders = (clone $recs)->where('branch_id', $cat['branches']['b1'])->where('product_id', $cat['products']['p2']);
        $withOrders->update(['assumptions' => json_encode(['inventory_position' => 116.45])]);

        $response = $this->actingAsTenantUser($user, $tenant)
            ->getJson('/api/v1/inventory/recommendations?sort=-suggested_qty&per_page=50')->assertOk();
        $rows = collect($response->json('data'));
        $row = $rows->first(fn ($r) => $r['branch_id'] === $cat['branches']['b1'] && $r['product_id'] === $cat['products']['p1']);
        $this->assertNull($row['suggested_qty']);
        $this->assertNull($row['reorder_point']);
        $this->assertNull($row['stockout_probability']);
        $this->assertTrue($row['assumptions']['coverage_insufficient']);
        $this->assertNull($rows->last()['suggested_qty']); // NULLS LAST también al ordenar por compra

        $baseline = app(ScenarioBaselineLoader::class)->load($run, 30, []);
        $this->assertCount(5, $baseline->replenishment);
        $p2 = collect($baseline->replenishment)->first(fn ($r) => $r['branch_id'] === $cat['branches']['b1'] && $r['product_id'] === $cat['products']['p2']);
        $this->assertSame(116.45, $p2['available']); // posición de inventario del motor
    }
}
