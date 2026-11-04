<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\Support\ModelQualityFixtures;
use Tests\Feature\Customer\Support\CustomerFixtures;
use Tests\TestCase;

/**
 * V2.5 · W3-D: el Customer Core expone las cantidades de S05 (nº de pedidos esperado, cantidad por pedido, unidades esperadas y la ventana de recompra con su
 * estado) en las predicciones y en la ficha del comprador, sin romper los campos actuales y sin cifras donde el motor no las respalda.
 */
class CustomerS05ApiTest extends TestCase
{
    use CustomerFixtures;
    use ModelQualityFixtures;
    use RefreshDatabase;

    /** @return array{tenant: Tenant, user: User, cat: array, run: int} */
    private function company(bool $s05 = true): array
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Viewer);
        $cat = $this->seedCustomerCatalog($tenant);
        $run = $this->seedPredictionRun($tenant, $cat);
        if ($s05) {
            $this->seedCustomerS05($tenant->id, $run, $cat);
        }

        return compact('tenant', 'user', 'cat', 'run');
    }

    public function test_predictions_expose_orders_units_and_the_window_with_its_state(): void
    {
        $c = $this->company();

        $rows = collect($this->actingAsTenantUser($c['user'], $c['tenant'])->getJson('/api/v1/customer/predictions?horizon=30')->assertOk()->json('data'));

        $estimated = $rows->first(fn ($r) => $r['customer']['id'] === $c['cat']['customers']['c1'] && $r['product']['id'] === $c['cat']['products']['p1']);
        $this->assertSame(1.35, $estimated['expected_orders']);
        $this->assertSame(3.2, $estimated['quantity_per_order']);
        $this->assertSame(4.32, $estimated['expected_units']);
        $this->assertEquals(['status' => 'estimated', 'p10_days' => 6, 'p50_days' => 11.5, 'p90_days' => 19], $estimated['window']);
        $this->assertSame('frequency:high', $estimated['segment']);
        $this->assertSame(['orders_nested_adjusted', 'window_beyond_validation'], $estimated['quality_flags']);
        // Los campos vigentes siguen ahí con su significado (expected_quantity = P × cantidad condicionada).
        $this->assertEqualsWithDelta(0.65, $estimated['purchase_probability'], 1e-9);
        $this->assertEqualsWithDelta(2.6, $estimated['expected_quantity'], 1e-9);
        $this->assertEqualsWithDelta(4.0, $estimated['quantity_if_purchase'], 1e-9);

        $cold = $rows->first(fn ($r) => $r['customer']['id'] === $c['cat']['customers']['c2']);
        $this->assertSame('insufficient_data', $cold['window']['status']);
        $this->assertNull($cold['window']['p10_days']);
        $this->assertNull($cold['window']['p50_days']);
        $this->assertNull($cold['window']['p90_days']);
        $this->assertSame(0.4, $cold['expected_orders']);
    }

    public function test_window_figures_are_never_published_without_a_state_that_backs_them(): void
    {
        $c = $this->company();
        // Aunque la columna traiga cuantiles (p. ej. una escritura defectuosa), sin estado que los respalde no salen.
        DB::table('customer_predictions')->where('core_run_id', $c['run'])->where('customer_id', $c['cat']['customers']['c2'])
            ->update(['window_p10_days' => 5, 'window_p50_days' => 9, 'window_p90_days' => 14]);
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);

        $cold = collect($api->getJson('/api/v1/customer/predictions?horizon=30')->json('data'))->firstWhere('customer.id', $c['cat']['customers']['c2']);
        $this->assertSame('insufficient_data', $cold['window']['status']);
        $this->assertNull($cold['window']['p50_days']);

        foreach (['censored', null] as $state) {
            DB::table('customer_predictions')->where('core_run_id', $c['run'])->where('customer_id', $c['cat']['customers']['c2'])->update(['window_status' => $state]);
            $row = collect($api->getJson('/api/v1/customer/predictions?horizon=30')->json('data'))->firstWhere('customer.id', $c['cat']['customers']['c2']);
            $this->assertSame($state, $row['window']['status']);
            $this->assertNull($row['window']['p10_days'], "estado {$state}: sin cifras");
        }
        // «Atrasado» sí tiene cifras (anchas): no es abandono.
        DB::table('customer_predictions')->where('core_run_id', $c['run'])->where('customer_id', $c['cat']['customers']['c2'])->update(['window_status' => 'overdue']);
        $row = collect($api->getJson('/api/v1/customer/predictions?horizon=30')->json('data'))->firstWhere('customer.id', $c['cat']['customers']['c2']);
        $this->assertEquals(9.0, $row['window']['p50_days']);
    }

    public function test_a_run_without_s05_returns_nulls_not_zeros(): void
    {
        $c = $this->company(false);

        $rows = $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson('/api/v1/customer/predictions?horizon=30')->assertOk()->json('data');

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertNull($row['expected_orders']);
            $this->assertNull($row['quantity_per_order']);
            $this->assertNull($row['expected_units']);
            $this->assertNull($row['segment']);
            $this->assertSame(['status' => null, 'p10_days' => null, 'p50_days' => null, 'p90_days' => null], $row['window']);
        }
    }

    public function test_predictions_can_be_filtered_and_ordered_by_the_s05_columns_with_nulls_last(): void
    {
        $c = $this->company();
        // c2 × p2 sin estimar (nulo): nunca encabeza un orden descendente.
        DB::table('customer_predictions')->where('core_run_id', $c['run'])->where('customer_id', $c['cat']['customers']['c2'])->where('product_id', $c['cat']['products']['p2'])
            ->update(['expected_orders' => null, 'expected_units' => null]);
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);

        $desc = $api->getJson('/api/v1/customer/predictions?horizon=30&order_by=expected_orders&direction=desc')->assertOk()->json('data');
        $this->assertSame(1.35, $desc[0]['expected_orders']);
        $this->assertNull(end($desc)['expected_orders'], 'el nulo va al final');
        $asc = $api->getJson('/api/v1/customer/predictions?horizon=30&order_by=expected_units&direction=asc')->assertOk()->json('data');
        $this->assertNull(end($asc)['expected_units']);

        $this->assertSame(['estimated'], collect($api->getJson('/api/v1/customer/predictions?horizon=30&window_status=estimated')->json('data'))->pluck('window.status')->unique()->values()->all());
        $this->assertCount(2, $api->getJson('/api/v1/customer/predictions?horizon=30&segment=frequency:high')->json('data'));
        $api->getJson('/api/v1/customer/predictions?horizon=30&window_status=inventado')->assertStatus(422)->assertJsonValidationErrors('window_status');
        $api->getJson('/api/v1/customer/predictions?horizon=30&segment=DROP')->assertStatus(422)->assertJsonValidationErrors('segment');
    }

    public function test_the_definitions_explain_orders_units_and_the_window(): void
    {
        $c = $this->company();

        $definitions = $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson('/api/v1/customer/predictions?horizon=30')->assertOk()->json('definitions');

        foreach (['expected_orders', 'quantity_per_order', 'expected_units', 'window', 'expected_quantity'] as $key) {
            $this->assertNotEmpty($definitions[$key], "falta la definición de {$key}");
        }
        $this->assertStringContainsString('nunca un día exacto', $definitions['window']);
        $this->assertStringContainsString('independencia', $definitions['expected_units']);
    }

    public function test_the_customer_profile_shows_s05_next_to_each_ranked_product(): void
    {
        $c = $this->company();

        $profile = $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson("/api/v1/customer/customers/{$c['cat']['customers']['c1']}/profile")->assertOk();

        $first = $profile->json('data.rankings.30.0');
        $this->assertSame(1.35, $first['expected_orders']);
        $this->assertSame(4.32, $first['expected_units']);
        $this->assertSame('estimated', $first['window']['status']);
        $this->assertSame(11.5, $first['window']['p50_days']);
        $this->assertSame(['orders_nested_adjusted', 'window_beyond_validation'], $first['quality_flags']);
        // Campos que ya tenía la ficha.
        $this->assertArrayHasKey('purchase_probability', $first);
        $this->assertArrayHasKey('expected_amount', $first);

        $cold = $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson("/api/v1/customer/customers/{$c['cat']['customers']['c2']}/profile")->assertOk()->json('data.rankings.30.0');
        $this->assertSame('insufficient_data', $cold['window']['status']);
        $this->assertNull($cold['window']['p50_days']);
    }

    public function test_s05_columns_of_another_company_never_leak(): void
    {
        $a = $this->company();
        $b = $this->company();
        DB::table('customer_predictions')->where('core_run_id', $b['run'])->update(['expected_orders' => 99.0, 'segment' => 'frequency:b-only']);

        $rows = $this->actingAsTenantUser($a['user'], $a['tenant'])->getJson('/api/v1/customer/predictions?horizon=30&per_page=100')->assertOk();

        $this->assertStringNotContainsString('b-only', (string) $rows->getContent());
        $this->assertNotContains(99.0, array_column($rows->json('data'), 'expected_orders'));
        $this->actingAsTenantUser($a['user'], $a['tenant'])->getJson('/api/v1/customer/predictions?horizon=30&run_id='.$b['run'])->assertNotFound();
    }

    public function test_a_ranked_product_without_a_prediction_row_gets_no_estimate_in_the_profile(): void
    {
        $c = $this->company();
        // Un producto del ranking que no tiene fila de predicción (p. ej. el ranking se calculó con otra fuente): no se inventa nada.
        $extra = (int) DB::table('products')->insertGetId(['tenant_id' => $c['tenant']->id, 'sku' => 'EXT-01', 'name' => 'Sin predicción', 'unit' => 'unidad', 'is_active' => true,
            'current_price' => 3, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_product_rankings')->insert(['tenant_id' => $c['tenant']->id, 'core_run_id' => $c['run'], 'customer_id' => $c['cat']['customers']['c1'], 'product_id' => $extra,
            'horizon_days' => 30, 'rank' => 3, 'purchase_probability' => 0.05, 'expected_quantity' => 0.1, 'expected_amount' => 0.3, 'currency' => 'USD', 'ranking_source' => 'popularity', 'ranking_score' => 1]);

        $rankings = $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson("/api/v1/customer/customers/{$c['cat']['customers']['c1']}/profile?horizon=30")->assertOk()->json('data.rankings.30');

        $row = collect($rankings)->firstWhere('product.id', $extra);
        $this->assertNotNull($row, 'el producto del ranking sale');
        foreach (['expected_orders', 'quantity_per_order', 'expected_units', 'segment'] as $field) {
            $this->assertNull($row[$field], "{$field} sin predicción: nulo, nunca un cero");
        }
        $this->assertSame(['status' => null, 'p10_days' => null, 'p50_days' => null, 'p90_days' => null], $row['window']);
        $this->assertSame([], $row['quality_flags']);
        // Los que sí tienen predicción siguen trayendo su estimación.
        $this->assertNotNull(collect($rankings)->firstWhere('product.id', $c['cat']['products']['p1'])['expected_orders']);
    }

    public function test_the_profile_reads_only_the_ranked_products_and_the_columns_it_shows(): void
    {
        $c = $this->company();
        DB::enableQueryLog();
        $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson("/api/v1/customer/customers/{$c['cat']['customers']['c1']}/profile?horizon=30")->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'from "customer_predictions"') && str_contains($q, '"horizon_days"') && ! str_contains($q, 'count(*)'));
        DB::disableQueryLog();

        $predictions = $queries->filter(fn ($q) => str_contains($q, '"customer_id" = ?') && str_contains($q, '"product_id" in'))->first();
        $this->assertNotNull($predictions, 'la consulta de S05 se acota a los productos del ranking');
        $this->assertStringNotContainsString('select *', $predictions);
        $this->assertStringNotContainsString('"customer_predictions".*', $predictions);
        $this->assertStringContainsString('"expected_orders"', $predictions);
        $this->assertStringNotContainsString('"probability_raw"', $predictions, 'no se cargan las columnas que la ficha no muestra');
        $this->assertStringNotContainsString('"quantity_lo"', $predictions);
    }
}
