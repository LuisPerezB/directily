<?php

declare(strict_types=1);

namespace Tests\Feature\Supply;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\TestCase;

/**
 * P9 · Vista Plan de compras: las líneas y la demanda no atendida se paginan en el servidor (`/lines`, `/shortfalls`) con filtros y orden
 * por lista blanca; el detalle del plan trae totales, resumen, vista previa acotada y la fecha de corte frente a HOY en la zona de la empresa.
 */
class SupplyPlanPaginationTest extends TestCase
{
    use AnalyticsFixtures;
    use RefreshDatabase;

    private const AS_OF = '2026-10-26';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Plan completado con 30 líneas (3 productos × 2 sucursales × 5 fechas; las 6 del día de corte son emitibles) y 16 faltantes.
     *
     * @param  array{branches: array<string, int>, products: array<string, int>}  $cat
     * @return array{run: int, plan: int, supplier: int}
     */
    private function seedPlan(Tenant $tenant, array $cat, string $asOf = self::AS_OF, bool $withPlan = true): array
    {
        $now = now();
        $tid = $tenant->id;
        $run = (int) DB::table('core_runs')->insertGetId([
            'tenant_id' => $tid, 'core' => 'supply', 'operation' => 'plan', 'version' => 1, 'run_type' => 'production', 'status' => 'completed',
            'result_type' => 'optimized_plan', 'quality_status' => 'ok', 'uncertainty_source' => 'none', 'as_of' => $asOf,
            'horizon_start' => '2026-10-27', 'horizon_end' => '2027-01-18', 'options' => '{}', 'is_current' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $supplier = (int) DB::table('suppliers')->insertGetId(['tenant_id' => $tid, 'code' => 'PRV-1', 'name' => 'Proveedor Uno', 'created_at' => $now, 'updated_at' => $now]);
        if (! $withPlan) {
            return ['run' => $run, 'plan' => 0, 'supplier' => $supplier];
        }
        $plan = (int) DB::table('supply_plans')->insertGetId([
            'tenant_id' => $tid, 'core_run_id' => $run, 'objective' => 'min_cost', 'coverage_mode' => 'soft', 'solver_status' => 'optimal', 'mip_gap' => 0,
            'objective_value' => 1000, 'currency' => 'USD', 'total_purchase_cost' => 900, 'unmet_units_total' => 600, 'demand_units_total' => 1200,
            'details' => '{}', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $lines = [];
        $i = 0;
        foreach ($cat['products'] as $productId) {
            foreach ($cat['branches'] as $branchId) {
                foreach ([0, 7, 14, 21, 28] as $offset) {
                    $i++;
                    $date = Carbon::parse($asOf)->addDays($offset)->toDateString();
                    $lines[] = [
                        'tenant_id' => $tid, 'supply_plan_id' => $plan, 'line_type' => 'purchase', 'branch_id' => $branchId, 'product_id' => $productId,
                        'supplier_id' => $supplier, 'quantity' => 10 + $i, 'unit' => 'unidad', 'order_date' => $date,
                        'expected_receipt_date' => Carbon::parse($date)->addDays(7)->toDateString(), 'unit_cost' => 2, 'line_cost' => 100 + $i, 'currency' => 'USD',
                        'actionable_now' => $offset === 0, 'binding_constraints' => '[]', 'assumptions' => '{}', 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
        }
        DB::table('supply_plan_lines')->insert($lines);
        $shortfalls = [];
        foreach ($cat['products'] as $productId) {
            foreach ($cat['branches'] as $branchId) {
                foreach ([0, 7] as $k => $offset) {
                    $start = Carbon::parse($asOf)->addDays(1 + $offset);
                    $shortfalls[] = [
                        'tenant_id' => $tid, 'supply_plan_id' => $plan, 'branch_id' => $branchId, 'product_id' => $productId,
                        'period_start' => $start->toDateString(), 'period_end' => $start->copy()->addDays(6)->toDateString(),
                        'demand' => 100, 'unmet' => 50 + $k * 10, 'reason' => $k === 0 ? 'lead_time' : 'budget',
                    ];
                }
            }
        }
        // más faltantes del primer producto en la sucursal 1 (12 + 4 = 16)
        foreach ([14, 21, 28, 35] as $offset) {
            $start = Carbon::parse($asOf)->addDays(1 + $offset);
            $shortfalls[] = [
                'tenant_id' => $tid, 'supply_plan_id' => $plan, 'branch_id' => $cat['branches']['b1'], 'product_id' => $cat['products']['p1'],
                'period_start' => $start->toDateString(), 'period_end' => $start->copy()->addDays(6)->toDateString(), 'demand' => 100, 'unmet' => 100, 'reason' => 'no_offer',
            ];
        }
        DB::table('supply_plan_shortfalls')->insert($shortfalls);

        return ['run' => $run, 'plan' => $plan, 'supplier' => $supplier];
    }

    /** @return array{0: Tenant, 1: User, 2: array<string, mixed>, 3: array{run: int, plan: int, supplier: int}} */
    private function setUpPlan(TenantRole $role = TenantRole::Viewer): array
    {
        [$tenant, $user] = $this->createTenantWithUser($role);
        $cat = $this->seedCatalog($tenant);
        $plan = $this->withinTenant($tenant, fn () => $this->seedPlan($tenant, $cat));

        return [$tenant, $user, $cat, $plan];
    }

    public function test_detail_summarizes_and_previews_instead_of_returning_every_line(): void
    {
        [$tenant, $user, $cat, $plan] = $this->setUpPlan();
        $r = $this->actingAsTenantUser($user, $tenant)->getJson("/api/v1/supply/plans/{$plan['run']}")->assertOk();

        $r->assertJsonCount(20, 'data.lines')->assertJsonPath('data.lines_summary.total', 30)->assertJsonPath('data.lines_summary.shown', 20)
            ->assertJsonPath('data.lines_summary.truncated', true)->assertJsonPath('data.lines_summary.actionable_now', 6)
            ->assertJsonCount(16, 'data.shortfalls')->assertJsonPath('data.shortfalls_summary.total', 16)
            ->assertJsonPath('data.shortfalls_summary.truncated', false);
        // la vista previa va con lo emitible primero y la suma de faltantes viene del SQL, no de la lista devuelta
        $this->assertTrue($r->json('data.lines.0.actionable_now'));
        $this->assertEqualsWithDelta(6 * 50 + 6 * 60 + 4 * 100, $r->json('data.shortfalls_summary.unmet_units'), 0.001);
        $this->assertEqualsWithDelta(16 * 100, $r->json('data.shortfalls_summary.demand_units'), 0.001);
        $this->assertSame(['Matriz Quito', 'Sucursal Guayaquil'], array_column($r->json('data.branches'), 'name'));

        // con más de 20 faltantes la vista previa se acota y lo declara
        $extra = [];
        foreach (range(1, 10) as $n) {
            $extra[] = ['tenant_id' => $tenant->id, 'supply_plan_id' => $plan['plan'], 'branch_id' => $cat['branches']['b2'], 'product_id' => $cat['products']['p2'],
                'period_start' => '2027-02-01', 'period_end' => '2027-02-07', 'demand' => 10, 'unmet' => 1, 'reason' => 'mixed'];
        }
        DB::table('supply_plan_shortfalls')->insert($extra);
        $r2 = $this->actingAsTenantUser($user, $tenant)->getJson("/api/v1/supply/plans/{$plan['run']}")->assertOk();
        $r2->assertJsonCount(20, 'data.shortfalls')->assertJsonPath('data.shortfalls_summary.total', 26)->assertJsonPath('data.shortfalls_summary.shown', 20)
            ->assertJsonPath('data.shortfalls_summary.truncated', true);
    }

    public function test_lines_are_paginated_with_standard_data_and_meta(): void
    {
        [$tenant, $user, , $plan] = $this->setUpPlan();
        $api = $this->actingAsTenantUser($user, $tenant);

        $p1 = $api->getJson("/api/v1/supply/plans/{$plan['run']}/lines?per_page=12")->assertOk();
        $p1->assertJsonCount(12, 'data')->assertJsonPath('meta.total', 30)->assertJsonPath('meta.per_page', 12)->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.current_page', 1);
        $p3 = $api->getJson("/api/v1/supply/plans/{$plan['run']}/lines?per_page=12&page=3")->assertOk();
        $p3->assertJsonCount(6, 'data')->assertJsonPath('meta.current_page', 3);
        $api->getJson("/api/v1/supply/plans/{$plan['run']}/lines?per_page=12&page=4")->assertOk()->assertJsonCount(0, 'data');
        // pages do not overlap
        $ids = array_merge($p1->json('data.*.id'), $api->getJson("/api/v1/supply/plans/{$plan['run']}/lines?per_page=12&page=2")->json('data.*.id'), $p3->json('data.*.id'));
        $this->assertCount(30, array_unique($ids));
        // shape of one line: product, branch, supplier, dates, cost; assumptions is an object
        $p1->assertJsonStructure(['data' => [['id', 'line_type', 'branch' => ['id', 'name'], 'product' => ['id', 'sku', 'name'], 'supplier' => ['id', 'name'],
            'quantity', 'order_date', 'expected_receipt_date', 'unit_cost', 'line_cost', 'actionable_now', 'binding_constraints', 'justification', 'assumptions']], 'meta', 'links']);
        // per_page is bounded
        $api->getJson("/api/v1/supply/plans/{$plan['run']}/lines?per_page=101")->assertStatus(422);
        $api->getJson("/api/v1/supply/plans/{$plan['run']}/lines?per_page=0")->assertStatus(422);
        $api->getJson("/api/v1/supply/plans/{$plan['run']}/lines?page=0")->assertStatus(422);
        // default page size
        $api->getJson("/api/v1/supply/plans/{$plan['run']}/lines")->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.per_page', 25);
    }

    public function test_lines_filter_by_branch_product_search_actionable_and_supplier(): void
    {
        [$tenant, $user, $cat, $plan] = $this->setUpPlan();
        $api = $this->actingAsTenantUser($user, $tenant);
        $base = "/api/v1/supply/plans/{$plan['run']}/lines";

        $api->getJson("$base?branch_id={$cat['branches']['b1']}")->assertOk()->assertJsonPath('meta.total', 15);
        $api->getJson("$base?product_id={$cat['products']['p2']}")->assertOk()->assertJsonPath('meta.total', 10);
        $api->getJson("$base?actionable_now=1")->assertOk()->assertJsonPath('meta.total', 6);
        $api->getJson("$base?actionable_now=true")->assertOk()->assertJsonPath('meta.total', 6);
        $api->getJson("$base?actionable_now=0")->assertOk()->assertJsonPath('meta.total', 30);
        $api->getJson("$base?supplier_id={$plan['supplier']}")->assertOk()->assertJsonPath('meta.total', 30);
        $api->getJson("$base?supplier_id=999999")->assertOk()->assertJsonPath('meta.total', 0);
        // búsqueda por nombre (sin distinguir mayúsculas) y por SKU
        $api->getJson("$base?search=cemento")->assertOk()->assertJsonPath('meta.total', 10)->assertJsonPath('data.0.product.name', 'Cemento Portland 50kg');
        $api->getJson("$base?search=P3-sku")->assertOk()->assertJsonPath('meta.total', 10)->assertJsonPath('data.0.product.sku', 'P3-SKU');
        $api->getJson("$base?search=zzz")->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
        // los comodines del texto se escapan: «%» no es «cualquier cosa»
        $api->getJson("$base?search=".urlencode('%'))->assertOk()->assertJsonPath('meta.total', 0);
        $api->getJson("$base?search=".urlencode('_'))->assertOk()->assertJsonPath('meta.total', 0);
        // combinación de filtros
        $api->getJson("$base?branch_id={$cat['branches']['b2']}&search=varilla&actionable_now=1")->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.branch.name', 'Sucursal Guayaquil')->assertJsonPath('data.0.actionable_now', true);
        // the filtered pages keep the pagination meta of the filtered set
        $api->getJson("$base?branch_id={$cat['branches']['b1']}&per_page=10&page=2")->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.last_page', 2);
    }

    public function test_lines_sort_by_a_whitelist_and_default_to_actionable_first(): void
    {
        [$tenant, $user, , $plan] = $this->setUpPlan();
        $api = $this->actingAsTenantUser($user, $tenant);
        $base = "/api/v1/supply/plans/{$plan['run']}/lines";

        $default = $api->getJson("$base?per_page=10")->assertOk();
        $this->assertSame([true, true, true, true, true, true, false, false, false, false], $default->json('data.*.actionable_now'));
        $dates = $default->json('data.*.order_date');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);

        $desc = $api->getJson("$base?sort=-line_cost&per_page=100")->assertOk()->json('data.*.line_cost');
        $copy = $desc;
        rsort($copy);
        $this->assertSame($copy, $desc);
        $this->assertSame(130.0, (float) $desc[0]);
        $asc = $api->getJson("$base?sort=quantity&per_page=100")->assertOk()->json('data.*.quantity');
        $copy = $asc;
        sort($copy);
        $this->assertEquals($copy, $asc);
        $names = $api->getJson("$base?sort=product&per_page=100")->assertOk()->json('data.*.product.name');
        $copy = $names;
        sort($copy, SORT_STRING);
        $this->assertSame('Cemento Portland 50kg', $names[0]);
        $this->assertSame('Varilla 12mm', end($names));
        $api->getJson("$base?sort=-branch")->assertOk()->assertJsonPath('data.0.branch.name', 'Sucursal Guayaquil');
        // el orden es estable entre páginas (desempate por id)
        $a = $api->getJson("$base?sort=branch&per_page=15&page=1")->json('data.*.id');
        $b = $api->getJson("$base?sort=branch&per_page=15&page=2")->json('data.*.id');
        $this->assertCount(30, array_unique(array_merge($a, $b)));

        foreach (['unknown', '-unknown', 'id', 'line_cost;drop table users', 'products.name', '(select 1)'] as $bad) {
            $api->getJson("$base?sort=".urlencode($bad))->assertStatus(422);
        }
    }

    public function test_shortfalls_are_paginated_filtered_and_sorted(): void
    {
        [$tenant, $user, $cat, $plan] = $this->setUpPlan();
        $api = $this->actingAsTenantUser($user, $tenant);
        $base = "/api/v1/supply/plans/{$plan['run']}/shortfalls";

        $p = $api->getJson("$base?per_page=5")->assertOk();
        $p->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 16)->assertJsonPath('meta.last_page', 4)
            ->assertJsonStructure(['data' => [['branch_id', 'branch' => ['id', 'name'], 'product_id', 'product' => ['id', 'sku', 'name'], 'period_start', 'period_end', 'demand', 'unmet', 'reason']], 'meta']);
        $starts = $p->json('data.*.period_start');
        $sorted = $starts;
        sort($sorted);
        $this->assertSame($sorted, $starts);   // por defecto, por período
        $api->getJson("$base?per_page=5&page=4")->assertOk()->assertJsonCount(1, 'data');

        $api->getJson("$base?branch_id={$cat['branches']['b2']}")->assertOk()->assertJsonPath('meta.total', 6);
        $api->getJson("$base?search=martillo")->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('data.0.product.name', 'Martillo de uña');
        $api->getJson("$base?reason=no_offer")->assertOk()->assertJsonPath('meta.total', 4);
        $api->getJson("$base?product_id={$cat['products']['p1']}&reason=budget")->assertOk()->assertJsonPath('meta.total', 2);
        $unmet = $api->getJson("$base?sort=-unmet&per_page=100")->assertOk()->json('data.*.unmet');
        $this->assertEquals(100, $unmet[0]);
        $copy = $unmet;
        rsort($copy);
        $this->assertEquals($copy, $unmet);
        $api->getJson("$base?sort=product")->assertOk()->assertJsonPath('data.0.product.name', 'Cemento Portland 50kg');
        foreach (['unknown', 'supply_plan_id', 'unmet;--'] as $bad) {
            $api->getJson("$base?sort=".urlencode($bad))->assertStatus(422);
        }
        $api->getJson("$base?per_page=500")->assertStatus(422);
    }

    public function test_lines_and_shortfalls_are_isolated_between_companies(): void
    {
        [$tenantA, $userA, $catA, $planA] = $this->setUpPlan(TenantRole::Owner);
        [$tenantB, $userB] = $this->createTenantWithUser(TenantRole::Owner);
        $catB = $this->seedCatalog($tenantB);
        $planB = $this->withinTenant($tenantB, fn () => $this->seedPlan($tenantB, $catB));

        foreach (['lines', 'shortfalls'] as $part) {
            $this->actingAsTenantUser($userB, $tenantB)->getJson("/api/v1/supply/plans/{$planA['run']}/$part")->assertNotFound();
            $cross = $this->actingAsTenantUser($userA, $tenantB)->getJson("/api/v1/supply/plans/{$planA['run']}/$part");
            $this->assertContains($cross->status(), [403, 404]);
            $this->actingAsTenantUser($userA, $tenantA)->getJson("/api/v1/supply/plans/{$planA['run']}/$part")->assertOk();
        }
        // el propio plan de B no mezcla filas de A, aunque se pida una sucursal o producto de A
        $b = $this->actingAsTenantUser($userB, $tenantB);
        $b->getJson("/api/v1/supply/plans/{$planB['run']}/lines?per_page=100")->assertOk()->assertJsonPath('meta.total', 30);
        $b->getJson("/api/v1/supply/plans/{$planB['run']}/lines?branch_id={$catA['branches']['b1']}")->assertOk()->assertJsonPath('meta.total', 0);
        $b->getJson("/api/v1/supply/plans/{$planB['run']}/lines?product_id={$catA['products']['p1']}")->assertOk()->assertJsonPath('meta.total', 0);
        $b->getJson("/api/v1/supply/plans/{$planB['run']}/shortfalls?branch_id={$catA['branches']['b1']}")->assertOk()->assertJsonPath('meta.total', 0);
        $ids = $b->getJson("/api/v1/supply/plans/{$planB['run']}/lines?per_page=100")->json('data.*.branch_id');
        $this->assertEmpty(array_intersect($ids, array_values($catA['branches'])));
        // el plan de A pedido con el identificador de la corrida de B no existe
        $b->getJson("/api/v1/supply/plans/{$planA['plan']}/lines")->assertNotFound();
        // el detalle de B no cuenta ni lista sucursales de A
        $detail = $b->getJson("/api/v1/supply/plans/{$planB['run']}")->assertOk();
        $this->assertSame(array_values($catB['branches']), array_column($detail->json('data.branches'), 'id'));
    }

    public function test_requires_authentication_and_the_forecast_view_ability(): void
    {
        [, , , $plan] = $this->setUpPlan();
        $this->getJson("/api/v1/supply/plans/{$plan['run']}/lines")->assertUnauthorized();
        $this->getJson("/api/v1/supply/plans/{$plan['run']}/shortfalls")->assertUnauthorized();
        $this->getJson('/api/v1/supply/plans/abc/lines')->assertNotFound();
    }

    public function test_a_run_without_plan_lists_empty_pages_and_the_detail_has_zero_summaries(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Viewer);
        $cat = $this->seedCatalog($tenant);
        $plan = $this->withinTenant($tenant, fn () => $this->seedPlan($tenant, $cat, self::AS_OF, false));
        $api = $this->actingAsTenantUser($user, $tenant);

        $api->getJson("/api/v1/supply/plans/{$plan['run']}/lines")->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $api->getJson("/api/v1/supply/plans/{$plan['run']}/shortfalls")->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $api->getJson("/api/v1/supply/plans/{$plan['run']}")->assertOk()->assertJsonPath('data.plan', null)->assertJsonPath('data.lines_summary.total', 0)
            ->assertJsonPath('data.shortfalls_summary.total', 0)->assertJsonPath('data.branches', [])->assertJsonCount(0, 'data.lines');
    }

    public function test_detail_reports_the_cutoff_against_today_in_the_company_timezone(): void
    {
        [$tenant, $user, , $plan] = $this->setUpPlan();
        $this->assertSame('America/Guayaquil', $tenant->timezone);
        $api = $this->actingAsTenantUser($user, $tenant);
        $show = fn () => $api->getJson("/api/v1/supply/plans/{$plan['run']}")->assertOk();

        // plan con datos hasta el 26-oct y hoy es 31-oct: 5 días de antigüedad
        Carbon::setTestNow(Carbon::parse('2026-10-31 15:00:00', 'America/Guayaquil'));
        $show()->assertJsonPath('data.plan.as_of', '2026-10-26')->assertJsonPath('data.plan.today', '2026-10-31')->assertJsonPath('data.plan.as_of_age_days', 5)
            ->assertJsonPath('data.plan.timezone', 'America/Guayaquil')->assertJsonPath('data.run.as_of', '2026-10-26');

        // el mismo día de corte
        Carbon::setTestNow(Carbon::parse('2026-10-26 09:00:00', 'America/Guayaquil'));
        $show()->assertJsonPath('data.plan.today', '2026-10-26')->assertJsonPath('data.plan.as_of_age_days', 0);

        // «hoy» es el de la empresa, no el UTC: a las 02:00 UTC del 27 en Guayaquil (UTC-5) todavía es 26
        Carbon::setTestNow(Carbon::parse('2026-10-27 02:00:00', 'UTC'));
        $show()->assertJsonPath('data.plan.today', '2026-10-26')->assertJsonPath('data.plan.as_of_age_days', 0);
        DB::table('tenants')->where('id', $tenant->id)->update(['timezone' => 'Pacific/Auckland']);   // UTC+13: ya es 27
        $show()->assertJsonPath('data.plan.today', '2026-10-27')->assertJsonPath('data.plan.as_of_age_days', 1)->assertJsonPath('data.plan.timezone', 'Pacific/Auckland');
    }

    public function test_detail_cutoff_age_is_null_when_the_run_has_no_cutoff_date(): void
    {
        [$tenant, $user, , $plan] = $this->setUpPlan();
        DB::table('core_runs')->where('id', $plan['run'])->update(['as_of' => null]);
        $this->actingAsTenantUser($user, $tenant)->getJson("/api/v1/supply/plans/{$plan['run']}")->assertOk()
            ->assertJsonPath('data.plan.as_of', null)->assertJsonPath('data.plan.as_of_age_days', null);
    }
}
