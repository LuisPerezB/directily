<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Enums\TenantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\Feature\Radar\Support\RadarFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-A: GET /supply/readiness (V25-AGD-015; §10): por producto, disponible al corte (existencias − reservado, tránsito aparte), demanda esperada de los compradores
 * identificados y agregada, cobertura en días y si el horizonte es atendible. Sin inventario: «desconocido», nunca cero.
 */
class SupplyReadinessApiTest extends TestCase
{
    use AnalyticsFixtures;
    use RadarFixtures;
    use RefreshDatabase;

    private const CUTOFF = '2026-09-30';

    /** Fixtures del Customer Core (c1, c2 × p1, p2; corrida a 2026-10-01) más un pronóstico de demanda agregada sobre los mismos productos. */
    private function company(TenantRole $role = TenantRole::Viewer, bool $forecast = true): array
    {
        $c = $this->radarCompany($role);
        if ($forecast) {
            $category = (int) DB::table('product_categories')->insertGetId(['tenant_id' => $c['tenant']->id, 'name' => 'General', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('products')->whereIn('id', array_values($c['cat']['products']))->update(['category_id' => $category]);
            $cat = ['branches' => ['b1' => $c['cat']['branch']], 'categories' => ['c1' => $category], 'products' => $c['cat']['products'], 'product_category' => ['p1' => 'c1', 'p2' => 'c1']];
            $this->seedForecastRun($c['tenant'], $cat, self::CUTOFF, ['horizon' => 30, 'with_evaluations' => false, 'with_recommendations' => false]);
        }

        return $c;
    }

    private function api(array $c): static
    {
        return $this->actingAsTenantUser($c['user'], $c['tenant']);
    }

    private function item(array $c, string $product, string $query = 'horizon=30&as_of=2026-10-01'): array
    {
        $r = $this->api($c)->getJson("/api/v1/supply/readiness?product_id={$c['cat']['products'][$product]}&{$query}")->assertOk();

        return $r->json('data.0');
    }

    public function test_available_is_stock_minus_reserved_with_transit_apart_and_a_sufficient_flag(): void
    {
        $c = $this->company();
        $this->seedStock($c['tenant'], $c['cat'], 'p1', '2026-09-30', 10, 2, 5);

        $p1 = $this->item($c, 'p1');

        $this->assertSame('known', $p1['inventory']['status']);
        $this->assertEquals(8.0, $p1['inventory']['available'], 'existencias − reservado');
        $this->assertEquals(5.0, $p1['inventory']['in_transit'], 'el tránsito va aparte');
        $this->assertFalse($p1['inventory']['in_transit_counted_as_available']);
        // Demanda de los compradores identificados a 30 días: c1 (0,65 × 4) + c2 (0,15 × 4) = 3,2; la agregada pronosticada es 380,57. Se compara con la MAYOR de las dos:
        // «suficiente» frente a solo los compradores identificados (8 ≥ 3,2) subestimaría la demanda del producto.
        $this->assertSame('max_of_buyers_and_aggregate', $p1['serviceable']['required_basis']);
        $this->assertEqualsWithDelta(380.5714, $p1['serviceable']['required_units'], 0.01);
        $this->assertFalse($p1['serviceable']['value']);
        $this->assertSame('insufficient', $p1['serviceable']['status']);
        $this->assertSame(2, $p1['buyers_demand']['pairs']);
        $this->assertEquals(3.2, $p1['buyers_demand']['total_view_quantity']);
        $this->assertEquals(5.12, $p1['buyers_demand']['expected_units'], 'S05: 4,32 + 0,8');
        // Cobertura = disponible ÷ demanda diaria agregada (pronóstico de 12,69 u/día × 30 días = 380,57).
        $this->assertEqualsWithDelta(380.5714, $p1['aggregate_demand']['units'], 0.01);
        $this->assertEqualsWithDelta(8 / (380.5714 / 30), $p1['coverage_days'], 0.1);
    }

    public function test_with_only_identified_buyers_the_status_says_it_covers_only_them(): void
    {
        $c = $this->company(forecast: false);
        $this->seedStock($c['tenant'], $c['cat'], 'p1', '2026-09-30', 10, 2, 5);

        $p1 = $this->item($c, 'p1');

        $this->assertSame('identified_buyers_only', $p1['serviceable']['required_basis']);
        $this->assertSame('sufficient', $p1['serviceable']['status']);
        $this->assertStringContainsString('no necesariamente toda la demanda', $p1['serviceable']['required_basis_note']);
        $this->assertNull($p1['aggregate_demand']);
    }

    public function test_without_inventory_the_answer_is_unknown_never_zero(): void
    {
        $c = $this->company();

        $p2 = $this->item($c, 'p2');

        $this->assertSame('unknown', $p2['inventory']['status']);
        $this->assertNull($p2['inventory']['available'] ?? null);
        $this->assertNull($p2['coverage_days']);
        $this->assertNull($p2['serviceable']['value']);
        $this->assertSame('unknown', $p2['serviceable']['status']);
        $this->assertStringContainsString('se desconoce, no es cero', $p2['serviceable']['reason']);
    }

    public function test_insufficient_stock_is_flagged_and_a_snapshot_after_the_cutoff_is_ignored(): void
    {
        $c = $this->company();
        $this->seedStock($c['tenant'], $c['cat'], 'p2', '2026-09-30', 1, 0);   // demanda de compradores de p2 a 30 días: 0,25×2 + 0,5×2 = 1,5 > 1
        $this->seedStock($c['tenant'], $c['cat'], 'p2', '2026-10-15', 500, 0);

        $p2 = $this->item($c, 'p2');

        $this->assertEquals(1.0, $p2['inventory']['available'], 'la fotografía posterior al corte no se conocía');
        $this->assertSame('max_of_buyers_and_aggregate', $p2['serviceable']['required_basis']);
        $this->assertGreaterThan(1.5, $p2['serviceable']['required_units'], 'la demanda agregada (mayor que la de compradores: 1,5) es la que se compara');
        $this->assertSame('insufficient', $p2['serviceable']['status']);
        $this->assertFalse($p2['serviceable']['value']);
        $this->assertSame('known', $p2['inventory']['status']);
        // Con un corte posterior se usa la fotografía más reciente conocida a esa fecha, y una demasiado vieja (> inventory_max_age_days de la empresa) no se usa.
        $later = $this->item($c, 'p2', 'horizon=30&as_of=2026-10-10');
        $this->assertSame('unknown', $later['inventory']['status'], 'a esa fecha la fotografía de 09-30 ya tiene más de 7 días: el motor no la usa; la de 10-15 es posterior al corte');
        $this->seedStock($c['tenant'], $c['cat'], 'p2', '2026-10-09', 20, 0);
        $this->assertEquals(20.0, $this->item($c, 'p2', 'horizon=30&as_of=2026-10-10')['inventory']['available']);
    }

    public function test_a_negative_branch_stock_never_inflates_the_availability(): void
    {
        $c = $this->company();
        $branch2 = (int) DB::table('branches')->insertGetId(['tenant_id' => $c['tenant']->id, 'code' => '002', 'name' => 'Sur', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->seedStock($c['tenant'], $c['cat'], 'p1', '2026-09-30', 4, 1);
        DB::table('inventory_snapshots')->insert(['tenant_id' => $c['tenant']->id, 'branch_id' => $branch2, 'product_id' => $c['cat']['products']['p1'], 'snapshot_date' => '2026-09-30',
            'on_hand' => -50, 'reserved' => 0, 'in_transit' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $p1 = $this->item($c, 'p1');

        $this->assertEquals(3.0, $p1['inventory']['available'], 'la sucursal en negativo aporta 0, no resta ni compensa');
    }

    public function test_without_a_forecast_or_customer_run_the_demand_is_absent_not_invented(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Viewer);
        $cat = $this->seedCustomerCatalog($tenant);
        $this->seedStock($tenant, $cat, 'p1', '2026-09-30', 10);

        $r = $this->actingAsTenantUser($user, $tenant)->getJson("/api/v1/supply/readiness?product_id={$cat['products']['p1']}&as_of=2026-10-01&horizon=30")->assertOk();

        $p1 = $r->json('data.0');
        $this->assertNull($p1['buyers_demand']);
        $this->assertNull($p1['aggregate_demand']);
        $this->assertNull($p1['coverage_days']);
        $this->assertSame('unknown', $p1['serviceable']['status']);
        $this->assertNull($p1['serviceable']['required_units']);
        $this->assertStringContainsString('Sin demanda esperada', $p1['serviceable']['reason']);
        $this->assertNull($r->json('forecast_run'));
        $this->assertNull($r->json('customer_run'));
    }

    public function test_the_list_filters_and_paginates_products_and_explains_its_definitions(): void
    {
        $c = $this->company();
        $api = $this->api($c);

        $page = $api->getJson('/api/v1/supply/readiness?horizon=30&as_of=2026-10-01&per_page=1&page=2')->assertOk();
        $this->assertSame(2, $page->json('meta.total'));
        $this->assertSame(2, $page->json('meta.current_page'));
        $this->assertCount(1, $page->json('data'));
        $this->assertNotEmpty($page->json('definitions.serviceable'));
        $this->assertSame('2026-10-01', $page->json('as_of'));
        $this->assertSame($c['run'], $page->json('customer_run.id'));
        $this->assertNotNull($page->json('forecast_run.id'));
        $api->getJson('/api/v1/supply/readiness?product_id=999999')->assertStatus(422)->assertJsonValidationErrors('product_id');
        $api->getJson('/api/v1/supply/readiness?horizon=5')->assertStatus(422)->assertJsonValidationErrors('horizon');
        $api->getJson('/api/v1/supply/readiness?as_of=ayer')->assertStatus(422)->assertJsonValidationErrors('as_of');
        $api->getJson('/api/v1/supply/readiness?per_page=500')->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    public function test_it_is_isolated_by_company_and_gated_by_the_plan_horizons(): void
    {
        $a = $this->company();
        $b = $this->company();
        $this->seedStock($a['tenant'], $a['cat'], 'p1', '2026-09-30', 10);

        $this->api($b)->getJson("/api/v1/supply/readiness?product_id={$a['cat']['products']['p1']}")->assertStatus(422);
        $this->assertSame('unknown', $this->item($b, 'p1')['inventory']['status'], 'el inventario de la otra empresa no se ve');
        $a['tenant']->forceFill(['plan_overrides' => ['horizons' => [7, 30]]])->save();
        $this->api($a)->getJson('/api/v1/supply/readiness?horizon=90')->assertStatus(402);
        // No exige el plan de Customer: sin él solo falta la demanda de compradores.
        $a['tenant']->forceFill(['plan_overrides' => ['features' => ['customer' => false]]])->save();
        $r = $this->api($a)->getJson("/api/v1/supply/readiness?product_id={$a['cat']['products']['p1']}&horizon=30&as_of=2026-10-01")->assertOk();
        $this->assertNull($r->json('data.0.buyers_demand'));
        $this->assertSame('aggregate_forecast', $r->json('data.0.serviceable.required_basis'));
    }

    public function test_every_existing_role_can_read_it_and_it_is_read_only(): void
    {
        $this->getJson('/api/v1/supply/readiness')->assertUnauthorized();
        foreach ([TenantRole::Viewer, TenantRole::Analyst, TenantRole::Admin, TenantRole::Owner] as $role) {
            $c = $this->company($role, forecast: false);
            $this->api($c)->getJson('/api/v1/supply/readiness?horizon=30')->assertOk();
            $this->api($c)->postJson('/api/v1/supply/readiness')->assertStatus(405);
        }
    }
}
