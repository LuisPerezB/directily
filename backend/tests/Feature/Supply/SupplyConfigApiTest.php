<?php

declare(strict_types=1);

namespace Tests\Feature\Supply;

use App\Enums\TenantRole;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\TestCase;

/** Datos de configuración del planificador: ofertas, tasas, capacidades, presupuestos, recursos y recetas. */
class SupplyConfigApiTest extends TestCase
{
    use AnalyticsFixtures;
    use RefreshDatabase;

    /** @return array{0: Tenant, 1: User, 2: array, 3: int} */
    private function world(TenantRole $role = TenantRole::Analyst): array
    {
        [$tenant, $user] = $this->createTenantWithUser($role);
        $cat = $this->seedCatalog($tenant);
        $supplier = $this->withinTenant($tenant, fn () => Supplier::query()->create(['code' => 'S1', 'name' => 'Proveedor 1']));

        return [$tenant, $user, $cat, $supplier->id];
    }

    public function test_offer_crud_validates_references_and_keeps_unknowns_null(): void
    {
        [$tenant, $user, $cat, $supplierId] = $this->world();
        $payload = ['supplier_id' => $supplierId, 'product_id' => $cat['products']['p1'], 'unit_cost' => 12.5, 'currency' => 'eur', 'unit' => 'caja'];
        $r = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/supply/offers', $payload)->assertCreated();
        $r->assertJsonPath('data.currency', 'EUR')->assertJsonPath('data.min_order_qty', null)->assertJsonPath('data.lead_time_days', null)
            ->assertJsonPath('data.capacity_per_period', null)->assertJsonPath('data.known_at', null);
        $id = $r->json('data.id');

        // La misma clave natural (proveedor, producto, sucursal, vigencia) no se duplica.
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/supply/offers', $payload)->assertStatus(422);
        // Unidad y moneda son obligatorias: no se asumen.
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/supply/offers', ['supplier_id' => $supplierId, 'product_id' => $cat['products']['p2'], 'unit_cost' => 1])
            ->assertStatus(422)->assertJsonValidationErrors(['currency', 'unit']);

        $this->actingAsTenantUser($user, $tenant)->patchJson("/api/v1/supply/offers/{$id}", ['min_order_qty' => 10, 'order_multiple' => 2, 'lead_time_days' => 21, 'acquisition_cost_pct' => 0.1])
            ->assertOk()->assertJsonPath('data.min_order_qty', 10)->assertJsonPath('data.lead_time_days', 21);
        $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/supply/offers?product_id='.$cat['products']['p1'])->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAsTenantUser($user, $tenant)->deleteJson("/api/v1/supply/offers/{$id}")->assertNoContent();
        $this->assertDatabaseCount('supplier_offers', 0);
    }

    public function test_configuration_is_isolated_between_companies(): void
    {
        [$tenantA, $userA, $catA, $supA] = $this->world();
        [$tenantB, $userB, $catB, $supB] = $this->world();
        $offerA = $this->actingAsTenantUser($userA, $tenantA)->postJson('/api/v1/supply/offers', [
            'supplier_id' => $supA, 'product_id' => $catA['products']['p1'], 'unit_cost' => 1, 'currency' => 'USD', 'unit' => 'u'])->assertCreated()->json('data.id');
        // B no ve ni toca la oferta de A (404) y no puede referenciar proveedores/productos de A (422).
        $this->actingAsTenantUser($userB, $tenantB)->getJson("/api/v1/supply/offers/{$offerA}")->assertNotFound();
        $this->actingAsTenantUser($userB, $tenantB)->patchJson("/api/v1/supply/offers/{$offerA}", ['unit_cost' => 99])->assertNotFound();
        $this->actingAsTenantUser($userB, $tenantB)->deleteJson("/api/v1/supply/offers/{$offerA}")->assertNotFound();
        $this->actingAsTenantUser($userB, $tenantB)->getJson('/api/v1/supply/offers')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAsTenantUser($userB, $tenantB)->postJson('/api/v1/supply/offers', [
            'supplier_id' => $supA, 'product_id' => $catB['products']['p1'], 'unit_cost' => 1, 'currency' => 'USD', 'unit' => 'u'])
            ->assertStatus(422)->assertJsonValidationErrors(['supplier_id']);
        $this->actingAsTenantUser($userB, $tenantB)->postJson('/api/v1/supply/budgets', [
            'branch_id' => $catA['branches']['b1'], 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'amount' => 100, 'currency' => 'USD'])
            ->assertStatus(422)->assertJsonValidationErrors(['branch_id']);
        $this->assertDatabaseHas('supplier_offers', ['id' => $offerA, 'unit_cost' => 1, 'tenant_id' => $tenantA->id]);
    }

    public function test_abilities_viewer_reads_but_cannot_write_and_analyst_writes(): void
    {
        [$tenant, $viewer, $cat, $supplierId] = $this->world(TenantRole::Viewer);
        $body = ['supplier_id' => $supplierId, 'product_id' => $cat['products']['p1'], 'unit_cost' => 1, 'currency' => 'USD', 'unit' => 'u'];
        $this->actingAsTenantUser($viewer, $tenant)->getJson('/api/v1/supply/offers')->assertOk();
        $this->actingAsTenantUser($viewer, $tenant)->postJson('/api/v1/supply/offers', $body)->assertForbidden();
        $this->actingAsTenantUser($viewer, $tenant)->postJson('/api/v1/supply/exchange-rates', ['from_currency' => 'EUR', 'to_currency' => 'USD', 'rate' => 1.1, 'valid_on' => '2026-10-01', 'source' => 'x'])->assertForbidden();
        $this->actingAsTenantUser($viewer, $tenant)->postJson('/api/v1/supply/plans', [])->assertForbidden();   // calcular exige forecast.run
        $analyst = $this->addUserToTenant($tenant, TenantRole::Analyst);
        $this->actingAsTenantUser($analyst, $tenant)->postJson('/api/v1/supply/offers', $body)->assertCreated();
    }

    public function test_exchange_rates_capacities_budgets_and_resources(): void
    {
        [$tenant, $user, $cat] = $this->world();
        $c = fn () => $this->actingAsTenantUser($user, $tenant);
        $c()->postJson('/api/v1/supply/exchange-rates', ['from_currency' => 'eur', 'to_currency' => 'usd', 'rate' => 1.1, 'valid_on' => '2026-10-01', 'source' => 'banco (ficticio)'])
            ->assertCreated()->assertJsonPath('data.from_currency', 'EUR');
        $c()->postJson('/api/v1/supply/exchange-rates', ['from_currency' => 'EUR', 'to_currency' => 'EUR', 'rate' => 1, 'valid_on' => '2026-10-01', 'source' => 'x'])->assertStatus(422);
        $c()->postJson('/api/v1/supply/exchange-rates', ['from_currency' => 'EUR', 'to_currency' => 'USD', 'rate' => 0, 'valid_on' => '2026-10-01', 'source' => 'x'])->assertStatus(422);
        $c()->postJson('/api/v1/supply/capacities', ['branch_id' => $cat['branches']['b1'], 'capacity' => 500, 'capacity_unit' => 'm3'])->assertCreated();
        $c()->postJson('/api/v1/supply/budgets', ['period_start' => '2026-11-01', 'period_end' => '2026-10-01', 'amount' => 100, 'currency' => 'USD'])->assertStatus(422);
        $c()->postJson('/api/v1/supply/budgets', ['period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'amount' => 5000, 'currency' => 'USD'])
            ->assertCreated()->assertJsonPath('data.amount', 5000);
        $res = $c()->postJson('/api/v1/supply/production/resources', ['code' => 'LINEA-1', 'name' => 'Línea 1', 'capacity_per_period' => 40, 'capacity_unit' => 'horas'])->assertCreated();
        $c()->postJson('/api/v1/supply/production/resources', ['code' => 'LINEA-1', 'name' => 'Otra', 'capacity_per_period' => 40, 'capacity_unit' => 'horas'])->assertStatus(422);
        $this->assertSame(1, $res->json('data.id') > 0 ? 1 : 0);
    }

    public function test_recipes_validate_yield_and_lines_and_replace_lines_on_update(): void
    {
        [$tenant, $user, $cat] = $this->world();
        $p = $cat['products'];
        $c = fn () => $this->actingAsTenantUser($user, $tenant);
        $resId = $c()->postJson('/api/v1/supply/production/resources', ['code' => 'R1', 'name' => 'R1', 'capacity_per_period' => 10, 'capacity_unit' => 'lotes'])->json('data.id');
        $body = ['code' => 'REC-1', 'name' => 'Receta 1', 'output_product_id' => $p['p3'], 'output_quantity' => 10, 'yield_rate' => 0.8, 'production_days' => 7,
            'resource_id' => $resId, 'resource_usage_per_batch' => 1, 'lines' => [['input_product_id' => $p['p1'], 'quantity_per_batch' => 5], ['input_product_id' => $p['p2'], 'quantity_per_batch' => 1]]];
        $c()->postJson('/api/v1/supply/production/recipes', array_merge($body, ['yield_rate' => 1.2]))->assertStatus(422);
        $c()->postJson('/api/v1/supply/production/recipes', array_merge($body, ['code' => 'REC-X', 'lines' => [['input_product_id' => $p['p3'], 'quantity_per_batch' => 1]]]))
            ->assertStatus(422);   // el producto de salida no puede ser su propio insumo
        $c()->postJson('/api/v1/supply/production/recipes', array_merge($body, ['code' => 'REC-Y', 'lines' => [['input_product_id' => $p['p1'], 'quantity_per_batch' => 1], ['input_product_id' => $p['p1'], 'quantity_per_batch' => 2]]]))
            ->assertStatus(422);   // insumo repetido
        $r = $c()->postJson('/api/v1/supply/production/recipes', $body)->assertCreated();
        $r->assertJsonPath('data.useful_output_per_batch', 8)->assertJsonCount(2, 'data.lines');
        $id = $r->json('data.id');
        $c()->patchJson("/api/v1/supply/production/recipes/{$id}", ['lines' => [['input_product_id' => $p['p1'], 'quantity_per_batch' => 7]]])
            ->assertOk()->assertJsonCount(1, 'data.lines')->assertJsonPath('data.lines.0.quantity_per_batch', 7);
        $this->assertDatabaseCount('production_recipe_lines', 1);
    }

    public function test_offer_comparability_uses_declared_unit_conversions_and_explicit_exchange_rates(): void
    {
        [$tenant, $user, $cat, $supplierId] = $this->world();
        $pid = $cat['products']['p1'];
        $c = fn () => $this->actingAsTenantUser($user, $tenant);
        $id = $c()->postJson('/api/v1/supply/offers', ['supplier_id' => $supplierId, 'product_id' => $pid, 'unit_cost' => 24, 'currency' => 'EUR', 'unit' => 'Caja.'])
            ->assertCreated()->assertJsonPath('data.comparability.comparable', false)->json('data.id');
        $this->assertEqualsCanonicalizing(['no_unit_conversion', 'no_fx_rate'], $c()->getJson("/api/v1/supply/offers/{$id}")->json('data.comparability.issues'));
        // La normalización es solo tipográfica («Caja.» = «caja») y la conversión debe declararse (nunca se asume factor 1).
        DB::table('unit_conversions')->insert(['tenant_id' => $tenant->id, 'product_id' => $pid, 'from_unit' => 'caja', 'to_unit' => 'unidad',
            'factor' => 12, 'created_at' => now(), 'updated_at' => now()]);
        $r = $c()->getJson("/api/v1/supply/offers/{$id}")->assertOk();
        $r->assertJsonPath('data.comparability.unit_conversion.declared', true)->assertJsonPath('data.comparability.unit_conversion.factor', 12)
            ->assertJsonPath('data.comparability.issues', ['no_fx_rate']);
        // Tasa explícita en sentido inverso (USD→EUR) también permite comparar.
        $c()->postJson('/api/v1/supply/exchange-rates', ['from_currency' => 'USD', 'to_currency' => 'EUR', 'rate' => 0.8, 'valid_on' => '2026-01-01', 'source' => 'banco (ficticio)'])->assertCreated();
        $c()->getJson("/api/v1/supply/offers/{$id}")->assertJsonPath('data.comparability.comparable', true)->assertJsonPath('data.comparability.exchange_rate.rate', 1.25);
    }
}
