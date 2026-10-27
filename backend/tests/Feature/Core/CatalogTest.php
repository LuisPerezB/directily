<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Enums\TenantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class CatalogTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_branch_crud_and_plan_limit(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner, ['plan_overrides' => ['max_branches' => 2]]);
        $this->actingAsTenantUser($owner, $tenant);

        $id = $this->postJson('/api/v1/branches', ['code' => '001', 'name' => 'Matriz', 'province' => 'Pichincha'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/branches', ['code' => '001', 'name' => 'Duplicada'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/branches', ['code' => '002', 'name' => 'Norte'])->assertCreated();
        $this->postJson('/api/v1/branches', ['code' => '003', 'name' => 'Sur'])->assertStatus(402)->assertJsonPath('limit', 'max_branches');
        $this->patchJson("/api/v1/branches/{$id}", ['canton' => 'Quito'])->assertOk()->assertJsonPath('data.canton', 'Quito');
        $this->deleteJson("/api/v1/branches/{$id}")->assertOk()->assertJsonPath('data.deleted', true);
    }

    public function test_products_create_list_search_and_sort_whitelist(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($owner, $tenant);
        $categoryId = $this->postJson('/api/v1/product-categories', ['name' => 'Pinturas'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/products', ['sku' => 'PIN-1', 'name' => 'Pintura blanca', 'category_id' => $categoryId, 'current_price' => 12.5])
            ->assertCreated()->assertJsonPath('data.category.name', 'Pinturas')->assertJsonPath('data.stats.units_90d', 0);
        $this->postJson('/api/v1/products', ['sku' => 'CEM-1', 'name' => 'Cemento'])->assertCreated();
        $this->postJson('/api/v1/products', ['sku' => 'CEM-1', 'name' => 'Repetido'])->assertUnprocessable();

        $this->getJson('/api/v1/products?search=pint')->assertOk()->assertJsonCount(1, 'data');
        // Caracteres comodín y comillas no rompen ni amplían la búsqueda.
        $this->getJson('/api/v1/products?search='.urlencode("%' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products?sort='.urlencode('name; DROP TABLE products'))->assertUnprocessable();
        $this->getJson('/api/v1/products?sort=-name')->assertOk()->assertJsonPath('data.0.sku', 'PIN-1');
        $this->getJson('/api/v1/product-categories')->assertOk()->assertJsonPath('data.0.products_count', 1);
    }

    public function test_suppliers_lead_times_and_promotions(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($owner, $tenant);
        $productId = $this->postJson('/api/v1/products', ['sku' => 'A', 'name' => 'A'])->json('data.id');
        $supplierId = $this->postJson('/api/v1/suppliers', ['code' => 'P1', 'name' => 'Proveedor 1', 'default_lead_time_days' => 10])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/lead-times', ['product_id' => $productId, 'supplier_id' => $supplierId, 'lead_time_days' => 7])->assertCreated();
        $this->postJson('/api/v1/lead-times', ['product_id' => $productId, 'supplier_id' => $supplierId, 'lead_time_days' => 9])->assertCreated();
        $this->getJson('/api/v1/lead-times')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.lead_time_days', 9);
        $this->postJson('/api/v1/lead-times', ['lead_time_days' => 5])->assertUnprocessable();

        $this->postJson('/api/v1/promotions', ['name' => 'Black Friday', 'discount_pct' => 0.2, 'starts_on' => '2026-11-27', 'ends_on' => '2026-11-30', 'product_id' => $productId])
            ->assertCreated()->assertJsonPath('data.discount_pct', 0.2);
        $this->postJson('/api/v1/promotions', ['name' => 'Mal', 'discount_pct' => 20, 'starts_on' => '2026-11-27', 'ends_on' => '2026-11-20'])
            ->assertUnprocessable()->assertJsonValidationErrors(['discount_pct', 'ends_on']);
        $this->getJson('/api/v1/promotions?active_on=2026-11-28')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_connectors_and_data_sources_never_return_credentials(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($owner, $tenant);
        $this->getJson('/api/v1/connectors')->assertOk()->assertJsonFragment(['key' => 'generic_rest', 'status' => 'available'])->assertJsonFragment(['key' => 'contifico', 'status' => 'requires_credentials']);

        $response = $this->postJson('/api/v1/data-sources', ['type' => 'connector', 'connector_key' => 'contifico', 'name' => 'Contífico', 'credentials' => ['api_key' => 'secreto-super-largo-123']])
            ->assertCreated()->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.has_credentials', true);
        $this->assertStringNotContainsString('secreto-super-largo-123', $response->getContent());
        $this->assertStringNotContainsString('secreto-super-largo-123', (string) DB::table('data_sources')->value('credentials_encrypted'));
        $this->getJson('/api/v1/data-sources')->assertOk()->assertJsonMissingPath('data.0.credentials_encrypted');
        $this->postJson('/api/v1/data-sources/'.$response->json('data.id').'/test')->assertOk()->assertJsonPath('data.ok', false);
    }
}
