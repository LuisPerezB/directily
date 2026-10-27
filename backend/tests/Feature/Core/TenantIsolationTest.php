<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Enums\TenantRole;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\ImportJob;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Promotion;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Aislamiento multi-tenant: un usuario de A no puede leer, listar, modificar ni referenciar
 * recursos de B por ningún camino (header, ids adivinados, tokens, FKs, consultas sin contexto).
 */
final class TenantIsolationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /** @return array<string, mixed> */
    private function seedTenantData(Tenant $tenant, string $prefix): array
    {
        return $this->withinTenant($tenant, function () use ($tenant, $prefix) {
            $branch = $this->createBranch($tenant, $prefix.'01', "Sucursal {$prefix}");
            $category = ProductCategory::query()->create(['name' => "Cat {$prefix}"]);
            $product = Product::query()->create(['sku' => "{$prefix}-SKU", 'name' => "Producto {$prefix}", 'category_id' => $category->id]);
            $supplier = Supplier::query()->create(['code' => "{$prefix}-PROV", 'name' => "Prov {$prefix}"]);
            $promotion = Promotion::query()->create(['name' => "Promo {$prefix}", 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-10']);
            $sale = Sale::query()->create([
                'branch_id' => $branch->id, 'document_type' => 'invoice', 'document_number' => "{$prefix}-001", 'status' => 'completed',
                'issued_at' => now(), 'sale_date' => now()->toDateString(), 'source_hash' => str_repeat('a', 64), 'total' => 10,
            ]);
            DB::table('sale_items')->insert([
                'tenant_id' => $tenant->id, 'sale_id' => $sale->id, 'line_number' => 1, 'product_id' => $product->id, 'branch_id' => $branch->id,
                'sale_date' => now()->toDateString(), 'quantity' => 3, 'unit_price' => 5, 'discount_amount' => 0, 'net_amount' => 15, 'tax_amount' => 0, 'is_return' => false,
            ]);
            $import = new ImportJob;
            $import->forceFill(['entity_type' => 'sales', 'original_filename' => 'x.csv', 'stored_path' => 'x', 'file_hash' => str_repeat('b', 64), 'file_size' => 1, 'file_format' => 'csv', 'status' => 'validated'])->save();
            $source = DataSource::query()->create(['type' => 'rest_api', 'connector_key' => 'generic_rest', 'name' => "Fuente {$prefix}", 'config' => []]);

            return compact('branch', 'category', 'product', 'supplier', 'promotion', 'sale', 'import', 'source');
        });
    }

    public function test_user_cannot_access_another_tenant_through_header(): void
    {
        [$tenantA, $userA] = $this->createTenantWithUser();
        [$tenantB] = $this->createTenantWithUser();
        $this->seedTenantData($tenantB, 'B');

        $this->actingAsTenantUser($userA, $tenantB);
        foreach (['/api/v1/tenant', '/api/v1/products', '/api/v1/branches', '/api/v1/sales', '/api/v1/imports', '/api/v1/tenant/users'] as $url) {
            $this->getJson($url)->assertNotFound()->assertJsonPath('code', 'tenant_not_found');
        }
    }

    public function test_guessing_ids_of_another_tenant_returns_404_for_every_resource(): void
    {
        [$tenantA, $userA] = $this->createTenantWithUser();
        [$tenantB] = $this->createTenantWithUser();
        $this->seedTenantData($tenantA, 'A');
        $b = $this->seedTenantData($tenantB, 'B');

        $this->actingAsTenantUser($userA, $tenantA);
        $this->getJson("/api/v1/products/{$b['product']->id}")->assertNotFound();
        $this->patchJson("/api/v1/products/{$b['product']->id}", ['name' => 'hack'])->assertNotFound();
        $this->getJson("/api/v1/branches/{$b['branch']->id}")->assertNotFound();
        $this->patchJson("/api/v1/branches/{$b['branch']->id}", ['name' => 'hack'])->assertNotFound();
        $this->deleteJson("/api/v1/branches/{$b['branch']->id}")->assertNotFound();
        $this->getJson("/api/v1/sales/{$b['sale']->id}")->assertNotFound();
        $this->getJson("/api/v1/imports/{$b['import']->id}")->assertNotFound();
        $this->putJson("/api/v1/imports/{$b['import']->id}/mapping", ['mapping' => []])->assertNotFound();
        $this->postJson("/api/v1/imports/{$b['import']->id}/confirm")->assertNotFound();
        $this->getJson("/api/v1/imports/{$b['import']->id}/errors")->assertNotFound();
        $this->patchJson("/api/v1/suppliers/{$b['supplier']->id}", ['name' => 'hack'])->assertNotFound();
        $this->getJson("/api/v1/promotions/{$b['promotion']->id}")->assertNotFound();
        $this->patchJson("/api/v1/product-categories/{$b['category']->id}", ['name' => 'hack'])->assertNotFound();
        $this->patchJson("/api/v1/data-sources/{$b['source']->id}", ['name' => 'hack'])->assertNotFound();
        $this->postJson("/api/v1/data-sources/{$b['source']->id}/tokens")->assertNotFound();
        $this->assertSame('Producto B', DB::table('products')->where('id', $b['product']->id)->value('name'));
    }

    public function test_lists_and_aggregates_only_include_own_tenant(): void
    {
        [$tenantA, $userA] = $this->createTenantWithUser();
        [$tenantB] = $this->createTenantWithUser();
        $this->seedTenantData($tenantA, 'A');
        $this->seedTenantData($tenantB, 'B');

        $this->actingAsTenantUser($userA, $tenantA);
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'A-SKU')->assertJsonPath('data.0.stats.units_90d', 3);
        $this->getJson('/api/v1/products?sort=-units_90d')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.document_number', 'A-001');
        $this->getJson('/api/v1/sales/summary')->assertOk()->assertJsonPath('data.totals.units', 3);
        $this->getJson('/api/v1/branches')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/product-categories')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/suppliers')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/data-sources')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/imports')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_cannot_reference_another_tenants_ids_in_payloads(): void
    {
        [$tenantA, $userA] = $this->createTenantWithUser();
        [$tenantB] = $this->createTenantWithUser();
        $b = $this->seedTenantData($tenantB, 'B');

        $this->actingAsTenantUser($userA, $tenantA);
        $this->postJson('/api/v1/products', ['sku' => 'X1', 'name' => 'X', 'category_id' => $b['category']->id])
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->postJson('/api/v1/lead-times', ['product_id' => $b['product']->id, 'lead_time_days' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->postJson('/api/v1/promotions', ['name' => 'P', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-02', 'branch_id' => $b['branch']->id])
            ->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_database_rejects_cross_tenant_foreign_keys(): void
    {
        [$tenantA] = $this->createTenantWithUser();
        [$tenantB] = $this->createTenantWithUser();
        $b = $this->seedTenantData($tenantB, 'B');
        $a = $this->seedTenantData($tenantA, 'A');

        $this->expectException(QueryException::class);
        DB::table('sale_items')->insert([
            'tenant_id' => $tenantA->id, 'sale_id' => $a['sale']->id, 'line_number' => 2, 'product_id' => $b['product']->id,
            'branch_id' => $a['branch']->id, 'sale_date' => now()->toDateString(), 'quantity' => 1, 'unit_price' => 1, 'net_amount' => 1,
        ]);
    }

    public function test_global_scope_fails_closed_without_tenant_context(): void
    {
        [$tenantA] = $this->createTenantWithUser();
        $this->seedTenantData($tenantA, 'A');

        $this->expectException(TenantNotResolvedException::class);
        Product::query()->count();
    }

    public function test_cannot_create_record_for_other_tenant_while_in_context(): void
    {
        [$tenantA] = $this->createTenantWithUser();
        [$tenantB] = $this->createTenantWithUser();

        $this->expectException(TenantNotResolvedException::class);
        $this->withinTenant($tenantA, function () use ($tenantB) {
            $branch = new Branch(['code' => 'X', 'name' => 'X']);
            $branch->tenant_id = $tenantB->id;
            $branch->save();
        });
    }

    public function test_api_token_is_bound_to_its_tenant_and_ignores_header(): void
    {
        [$tenantA, $userA] = $this->createTenantWithUser();
        [$tenantB] = $this->createTenantWithUser();
        $tenantB->users()->attach($userA->id, ['role' => TenantRole::Owner->value]); // miembro de ambas
        $this->seedTenantData($tenantA, 'A');
        $this->seedTenantData($tenantB, 'B');

        $token = $userA->createToken('datasource:1', ['imports.manage', 'data.view']);
        $token->accessToken->forceFill(['tenant_id' => $tenantA->id])->save();

        $this->withToken($token->plainTextToken)->withHeader('X-Tenant-ID', $tenantB->uuid)
            ->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.sku', 'A-SKU');
        // Las habilidades del token limitan aunque el usuario sea propietario.
        $this->withToken($token->plainTextToken)->getJson('/api/v1/tenant/users')->assertForbidden();
    }

    public function test_viewer_role_is_read_only(): void
    {
        [$tenant] = $this->createTenantWithUser();
        $viewer = $this->addUserToTenant($tenant, TenantRole::Viewer);
        $this->actingAsTenantUser($viewer, $tenant);
        $this->getJson('/api/v1/products')->assertOk();
        $this->postJson('/api/v1/products', ['sku' => 'Z', 'name' => 'Z'])->assertForbidden();
        $this->postJson('/api/v1/branches', ['code' => 'Z', 'name' => 'Z'])->assertForbidden();
        $this->postJson('/api/v1/sales/batch', ['documents' => []])->assertForbidden();
        $this->postJson('/api/v1/data-sources', ['type' => 'rest_api', 'name' => 'x'])->assertForbidden();
        $this->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->getJson('/api/v1/tenant/users')->assertForbidden();
    }

    public function test_tenant_context_does_not_leak_between_requests(): void
    {
        [$tenantA, $userA] = $this->createTenantWithUser();
        $this->seedTenantData($tenantA, 'A');
        $this->actingAsTenantUser($userA, $tenantA)->getJson('/api/v1/products')->assertOk();
        $this->assertFalse(app(TenantContext::class)->has());
        Sanctum::actingAs($userA->refresh());
    }
}
