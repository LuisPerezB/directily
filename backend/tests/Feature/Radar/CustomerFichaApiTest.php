<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Enums\TenantRole;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Radar\Support\RadarFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-A: fachadas V2.2 de la ficha del comprador (V25-AGD-010 a -013): predicciones (≡ /customer/predictions), demanda esperada (Σ de las predicciones), productos
 * habituales y probables, y la ficha comercial con compras, frecuencia, SKU habituales, evolución, ventana futura y seguimiento. Sin PII innecesaria; aislada por empresa.
 */
class CustomerFichaApiTest extends TestCase
{
    use RadarFixtures;
    use RefreshDatabase;

    private function company(TenantRole $role = TenantRole::Viewer): array
    {
        return $this->radarCompany($role);
    }

    private function api(array $c): static
    {
        return $this->actingAsTenantUser($c['user'], $c['tenant']);
    }

    // ── /customers/{id}/predictions ─────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_predictions_answer_exactly_like_customer_predictions_filtered_by_the_customer(): void
    {
        $c = $this->company();
        $id = $c['cat']['customers']['c1'];

        // Mismo cuerpo salvo las URL de paginación (que apuntan a la ruta consultada).
        $body = function (array $json): array {
            unset($json['links'], $json['meta']['path'], $json['meta']['links']);

            return $json;
        };
        foreach (['horizon=30', 'horizon=7&order_by=expected_orders&direction=desc', 'horizon=30&min_probability=0.3', ''] as $query) {
            $alias = $this->api($c)->getJson("/api/v1/customers/{$id}/predictions?{$query}")->assertOk();
            $direct = $this->api($c)->getJson("/api/v1/customer/predictions?customer_id={$id}&{$query}")->assertOk();
            $this->assertEquals($body($direct->json()), $body($alias->json()), "mismo cuerpo con «{$query}»");
        }
        $alias = $this->api($c)->getJson("/api/v1/customers/{$id}/predictions?horizon=30");
        $this->assertSame("/api/v1/customer/predictions?customer_id={$id}", $alias->headers->get('X-Equivalent-Of'));
        $this->assertSame([$id], collect($alias->json('data'))->pluck('customer.id')->unique()->values()->all(), 'solo las de ese comprador');
        $this->assertCount(2, $alias->json('data'));
        $this->assertSame(1.35, collect($alias->json('data'))->first(fn ($r) => $r['product']['id'] === $c['cat']['products']['p1'])['expected_orders']);
    }

    public function test_predictions_of_a_customer_of_another_company_are_404(): void
    {
        $a = $this->company();
        $b = $this->company();

        $this->api($b)->getJson("/api/v1/customers/{$a['cat']['customers']['c1']}/predictions")->assertNotFound();
        $this->api($b)->getJson('/api/v1/customers/999999/predictions')->assertNotFound();
        $this->api($b)->getJson("/api/v1/customers/{$b['cat']['customers']['c1']}/predictions?horizon=30")->assertOk();
    }

    // ── /customers/{id}/demand ──────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_demand_is_the_sum_over_the_customer_pairs_of_the_total_view_with_its_breakdown(): void
    {
        $c = $this->company();
        $id = $c['cat']['customers']['c1'];
        // Un pedido confirmado mayor que la esperanza: la demanda de la relación es max(esperanza, comprometido).
        DB::table('customer_predictions')->where('core_run_id', $c['run'])->where('customer_id', $id)->where('product_id', $c['cat']['products']['p2'])->where('horizon_days', 30)
            ->update(['committed_quantity' => 3, 'incremental_quantity' => 0, 'total_view_quantity' => 3]);

        $demand = $this->api($c)->getJson("/api/v1/customers/{$id}/demand?horizon=30")->assertOk()->json('data');
        $rows = collect($this->api($c)->getJson("/api/v1/customer/predictions?customer_id={$id}&horizon=30")->json('data'));

        $this->assertSame(2, $demand['pairs']);
        $this->assertEqualsWithDelta($rows->sum('total_view_quantity'), $demand['total_view_quantity'], 1e-6, 'coincide con la suma de /customer/predictions');
        $this->assertEqualsWithDelta($rows->sum('expected_quantity'), $demand['breakdown']['expected_quantity'], 1e-6);
        $this->assertEqualsWithDelta($rows->sum('committed_quantity'), $demand['breakdown']['committed_quantity'], 1e-6);
        $this->assertEqualsWithDelta($rows->sum('incremental_quantity'), $demand['breakdown']['incremental_quantity'], 1e-6);
        $this->assertEqualsWithDelta(0.65 * 4.0 + 3.0, $demand['total_view_quantity'], 1e-6, 'p1: 2,6 de esperanza; p2: 3 comprometidos > 0,5 esperados');
        $this->assertEqualsWithDelta($rows->sum('expected_amount.value'), $demand['expected_amount']['value'], 1e-6);
        $this->assertStringContainsString('no es una venta confirmada', $demand['expected_amount']['basis']);
        // S05: unidades y pedidos esperados solo de los pares estimados (los demás se declaran, no se rellenan con ceros).
        $this->assertSame(['pairs_estimated' => 2, 'pairs_without_estimate' => 0], ['pairs_estimated' => $demand['s05']['pairs_estimated'], 'pairs_without_estimate' => $demand['s05']['pairs_without_estimate']]);
        $this->assertEqualsWithDelta($rows->sum('expected_units'), $demand['s05']['expected_units'], 1e-6);
        $this->assertCount(2, $demand['products']);
        $this->assertSame('predicted', $demand['status']);
        $this->assertNotNull($this->api($c)->getJson("/api/v1/customers/{$id}/demand?horizon=30")->json('envelope.run_id'));
    }

    public function test_demand_does_not_invent_figures_without_an_individual_prediction(): void
    {
        $c = $this->company();

        $generic = $this->api($c)->getJson("/api/v1/customers/{$c['cat']['customers']['c3']}/demand?horizon=30")->assertOk()->json('data');
        $this->assertSame('no_individual_prediction', $generic['status']);
        $this->assertSame(0, $generic['pairs']);
        $this->assertNull($generic['total_view_quantity']);
        $this->assertNull($generic['breakdown']['expected_quantity']);
        $this->assertNull($generic['s05']['expected_units']);
        $this->assertSame([], $generic['products']);

        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Viewer);
        $cat = $this->seedCustomerCatalog($tenant);
        $r = $this->actingAsTenantUser($user, $tenant)->getJson("/api/v1/customers/{$cat['customers']['c1']}/demand")->assertOk();
        $this->assertSame('no_prediction_run', $r->json('data.status'));
        $this->assertNull($r->json('data.total_view_quantity'));
        $this->assertNull($r->json('envelope'));
    }

    public function test_demand_validates_the_horizon_and_hides_other_companies(): void
    {
        $a = $this->company();
        $b = $this->company();
        $id = $a['cat']['customers']['c1'];

        $this->api($a)->getJson("/api/v1/customers/{$id}/demand?horizon=60")->assertStatus(422);
        DB::table('core_runs')->where('id', $a['run'])->update(['horizons' => json_encode([7, 30])]);
        $r = $this->api($a)->getJson("/api/v1/customers/{$id}/demand?horizon=90")->assertStatus(422);
        $this->assertSame('horizon_not_available', $r->json('code'), 'el plan permite 90 días pero la corrida no los trae: abstención explícita');
        $a['tenant']->forceFill(['plan_overrides' => ['horizons' => [7, 30]]])->save();
        $this->api($a)->getJson("/api/v1/customers/{$id}/demand?horizon=90")->assertStatus(402);
        $this->api($b)->getJson("/api/v1/customers/{$id}/demand?horizon=30")->assertNotFound();
        $a['tenant']->forceFill(['plan_overrides' => ['features' => ['customer' => false]]])->save();
        $this->api($a)->getJson("/api/v1/customers/{$id}/demand")->assertStatus(402);
    }

    // ── /customers/{id}/products ────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_products_list_the_habitual_from_purchase_history_and_the_probable_from_the_ranking(): void
    {
        $c = $this->company();
        $id = $c['cat']['customers']['c1'];
        $this->seedCustomerSales($c['tenant'], $c['cat'], 'c1', 'p2', ['2026-08-01', '2026-08-15', '2026-09-01', '2026-09-15'], 3, null, 10.0);
        $this->seedCustomerSales($c['tenant'], $c['cat'], 'c1', 'p1', ['2026-09-20'], 1, null, 8.0);
        $this->seedCustomerSales($c['tenant'], $c['cat'], 'c2', 'p1', ['2026-09-21'], 9, null, 8.0);   // de otro comprador: no cuenta
        DB::table('sales')->where('tenant_id', $c['tenant']->id)->where('sale_date', '2026-09-15')->update(['status' => 'voided']);   // anulada: no cuenta

        $all = $this->api($c)->getJson("/api/v1/customers/{$id}/products?horizon=30")->assertOk();
        $habitual = $all->json('data.habitual');
        $probable = $all->json('data.probable');

        $this->assertSame('purchase_history', $habitual['basis']);
        $this->assertSame([$c['cat']['products']['p2'], $c['cat']['products']['p1']], collect($habitual['data'])->pluck('product.id')->all(), 'por días de compra: p2 (3 válidos) y luego p1 (1)');
        $p2 = $habitual['data'][0];
        $this->assertSame(3, $p2['purchase_days']);
        $this->assertEquals(9.0, $p2['units']);
        $this->assertEquals(3.0, $p2['avg_units_per_purchase_day']);
        $this->assertEquals(90.0, $p2['net_amount']);
        $this->assertSame('2026-09-01', $p2['last_purchase_date']);
        $this->assertSame('customer_core_ranking', $probable['basis']);
        $this->assertSame('model', $probable['ranking_source']);
        $this->assertSame('predicted', $probable['status']);
        $this->assertCount(2, $probable['data']);
        $top = $probable['data'][0];
        $this->assertSame(['rank', 'ranking_source', 'ranking_score', 'product', 'purchase_probability', 'expected_quantity', 'expected_amount', 'expected_orders', 'quantity_per_order', 'expected_units', 'window', 'segment'], array_keys($top));
        $this->assertSame('estimated', $top['window']['status']);

        $only = $this->api($c)->getJson("/api/v1/customers/{$id}/products?kind=habitual")->assertOk()->json('data');
        $this->assertArrayHasKey('habitual', $only);
        $this->assertArrayNotHasKey('probable', $only);
        $this->assertCount(1, $this->api($c)->getJson("/api/v1/customers/{$id}/products?kind=habitual&limit=1")->json('data.habitual.data'));
        $this->api($c)->getJson("/api/v1/customers/{$id}/products?kind=inventado")->assertStatus(422)->assertJsonValidationErrors('kind');
    }

    public function test_probable_products_say_when_the_horizon_is_not_available_or_there_is_no_run(): void
    {
        $c = $this->company();
        $id = $c['cat']['customers']['c1'];

        DB::table('core_runs')->where('id', $c['run'])->update(['horizons' => json_encode([7, 30])]);
        $both = $this->api($c)->getJson("/api/v1/customers/{$id}/products?horizon=90")->assertOk();
        $this->assertSame('horizon_not_available', $both->json('data.probable.status'));
        $this->assertSame([], $both->json('data.probable.data'));
        $this->api($c)->getJson("/api/v1/customers/{$id}/products?horizon=90&kind=probable")->assertStatus(422)->assertJsonPath('code', 'horizon_not_available');

        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Viewer);
        $cat = $this->seedCustomerCatalog($tenant);
        $r = $this->actingAsTenantUser($user, $tenant)->getJson("/api/v1/customers/{$cat['customers']['c1']}/products")->assertOk();
        $this->assertSame('no_prediction_run', $r->json('data.probable.status'));
        $this->assertSame([], $r->json('data.habitual.data'));
    }

    public function test_products_are_isolated_by_company(): void
    {
        $a = $this->company();
        $b = $this->company();

        $this->api($b)->getJson("/api/v1/customers/{$a['cat']['customers']['c1']}/products")->assertNotFound();
        $this->api($b)->getJson("/api/v1/customers/{$a['cat']['customers']['c1']}/overview")->assertNotFound();
    }

    // ── /customers/{id}/overview (ficha) ────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_overview_shows_purchases_frequency_habitual_products_evolution_window_and_follow_up(): void
    {
        $c = $this->company(TenantRole::Analyst);
        $id = $c['cat']['customers']['c1'];
        $this->seedCustomerSales($c['tenant'], $c['cat'], 'c1', 'p1', ['2026-07-10', '2026-08-12', '2026-09-20'], 2, null, 8.0);
        $this->seedCustomerSales($c['tenant'], $c['cat'], 'c1', 'p2', ['2026-09-20'], 5, null, 4.0);
        $this->materializeAll($c);
        $opportunity = (int) DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('kind', 'contact')->where('horizon_days', 30)->where('customer_id', $id)->orderBy('id')->value('id');
        $this->api($c)->postJson("/api/v1/radar/opportunities/{$opportunity}/feedback", ['state' => 'contactado', 'note' => 'Llamada de seguimiento', 'idempotency_key' => 'ficha-seguimiento-1'])->assertCreated();

        $r = $this->api($c)->getJson("/api/v1/customers/{$id}/overview?horizon=30")->assertOk();
        $d = $r->json('data');

        $this->assertSame(['id' => $id, 'code' => 'CLI-0001', 'type' => 'company', 'is_generic' => false, 'zone' => null, 'segment' => null], $d['customer']);
        $this->assertSame('predicted', $d['status']);
        // Compras: los documentos más recientes primero, con importes netos.
        $recent = $d['purchases']['recent'];
        $this->assertCount(4, $recent, 'cuatro documentos (el 20 de septiembre tiene dos)');
        $this->assertSame('2026-09-20', $recent[0]['date']);
        $this->assertSame(['date', 'document_type', 'document_number', 'lines', 'units', 'net_amount', 'currency'], array_keys($recent[0]));
        $this->assertSame(4, $d['purchases']['totals']['documents']);
        $this->assertSame('2026-07-10', $d['purchases']['totals']['first_purchase_date']);
        $this->assertSame('2026-09-20', $d['purchases']['totals']['last_purchase_date']);
        $this->assertEquals(2 * 8 * 3 + 5 * 4, $d['purchases']['totals']['net_amount_last_12_months']);
        // Frecuencia (ficha de actividad del Customer Core).
        $this->assertSame('customer_activity', $d['frequency']['source']);
        $this->assertSame('active', $d['frequency']['inactivity']['status']);
        $this->assertEquals(2.5, $d['frequency']['purchase_frequency_per_30d']);
        // SKU habituales.
        $this->assertSame($c['cat']['products']['p1'], $d['habitual_products']['data'][0]['product']['id']);
        // Evolución mensual: 12 meses, los sin compras con cero.
        $series = collect($d['evolution']['series']);
        $this->assertCount(12, $series);
        $this->assertSame('2025-11', $series->first()['month']);
        $this->assertSame('2026-10', $series->last()['month']);
        $this->assertEquals(['documents' => 0, 'purchase_days' => 0, 'units' => 0, 'net_amount' => 0], collect($series->firstWhere('month', '2026-06'))->only(['documents', 'purchase_days', 'units', 'net_amount'])->all());
        $sep = $series->firstWhere('month', '2026-09');
        $this->assertSame([2, 1], [$sep['documents'], $sep['purchase_days']]);
        $this->assertEquals(7.0, $sep['units']);
        // Ventana futura de recompra (nunca un día exacto; cifras solo con estado estimated u overdue).
        $window = $d['next_purchase_window'];
        $this->assertSame(30, $window['horizon_days']);
        $this->assertSame('estimated', $window['products'][0]['window']['status']);
        $this->assertEquals(11.5, $window['products'][0]['window']['p50_days']);
        $this->assertStringContainsString('nunca un día exacto', $window['note']);
        // Seguimiento: oportunidades del comprador y sus eventos.
        $follow = $d['follow_up'];
        $this->assertNotEmpty($follow['opportunities']);
        $this->assertSame('Llamada de seguimiento', collect($follow['events'])->firstWhere('agenda_status', 'contactado')['note']);
        $this->assertSame($opportunity, collect($follow['events'])->firstWhere('agenda_status', 'contactado')['recommendation_id']);
        $this->assertSame($c['run'], $r->json('envelope.run_id'));
    }

    public function test_the_overview_carries_no_unnecessary_pii_and_never_decrypts_the_name(): void
    {
        $c = $this->company();
        $id = $c['cat']['customers']['c1'];
        $this->withinTenant($c['tenant'], fn () => Customer::query()->findOrFail($id)->forceFill(['name_encrypted' => 'Juan Pérez Cervecería', 'tax_id_hash' => str_repeat('a', 64)])->save());
        $this->seedCustomerSales($c['tenant'], $c['cat'], 'c1', 'p1', ['2026-09-20']);
        $this->materializeAll($c);

        foreach (["/api/v1/customers/{$id}/overview", "/api/v1/customers/{$id}/demand", "/api/v1/customers/{$id}/products", "/api/v1/customers/{$id}/predictions",
            '/api/v1/radar/opportunities', '/api/v1/radar/agenda'] as $path) {
            $body = $this->api($c)->getJson($path)->assertOk()->getContent();
            $this->assertStringNotContainsString('Juan', $body, "{$path} expone el nombre");
            $this->assertStringNotContainsString('Cervecer', $body);
            $this->assertStringNotContainsString('name_encrypted', $body);
            $this->assertStringNotContainsString('tax_id', $body);
            $this->assertStringNotContainsString(str_repeat('a', 64), $body);
        }
    }

    public function test_the_overview_of_a_generic_customer_or_without_predictions_says_so(): void
    {
        $c = $this->company();

        $generic = $this->api($c)->getJson("/api/v1/customers/{$c['cat']['customers']['c3']}/overview")->assertOk()->json('data');
        $this->assertSame('no_individual_prediction', $generic['status']);
        $this->assertNotEmpty($generic['fallback']);
        $this->assertNull($generic['frequency']);
        $this->assertSame([], $generic['next_purchase_window']['products']);
        $this->assertSame([], $generic['purchases']['recent']);
        $this->assertCount(12, $generic['evolution']['series']);
    }

    public function test_the_facade_endpoints_have_no_write_methods_and_need_forecast_view(): void
    {
        $c = $this->company();
        $this->getJson("/api/v1/customers/{$c['cat']['customers']['c1']}/overview")->assertUnauthorized();
        foreach (['predictions', 'demand', 'products', 'overview'] as $leaf) {
            $this->api($c)->postJson("/api/v1/customers/{$c['cat']['customers']['c1']}/{$leaf}")->assertStatus(405);
        }
    }
}
