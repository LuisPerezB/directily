<?php

declare(strict_types=1);

namespace Tests\Feature\Lineage;

use App\Models\Customer;
use App\Models\CustomerAlias;
use App\Models\DataSource;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\Tenant;
use App\Services\Catalog\CatalogResolver;
use App\Support\Privacy\CustomerIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataCore\Support\ImportsCsv;
use Tests\TestCase;

/**
 * Resolución de identidades (ADR-V25-24): el catálogo consulta `product_aliases` y `customer_aliases` ANTES de crear una identidad
 * nueva — primero el alias de la fuente y luego el general, solo dentro de su vigencia — y una importación con el código viejo
 * tras un cambio de código resuelve al mismo producto. Aislamiento entre empresas.
 */
final class CatalogResolverAliasTest extends TestCase
{
    use BuildsImportFiles, ImportsCsv, InteractsWithTenants, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeImportInfrastructure();
        [$this->tenant, $owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001');
        $this->actingAsTenantUser($owner, $this->tenant);
    }

    private function product(string $sku, ?Tenant $tenant = null): Product
    {
        return $this->withinTenant($tenant ?? $this->tenant, fn () => Product::query()->create(['sku' => $sku, 'name' => $sku]));
    }

    private function alias(Product $product, string $code, array $extra = [], ?Tenant $tenant = null): ProductAlias
    {
        return $this->withinTenant($tenant ?? $this->tenant, fn () => ProductAlias::query()->create(array_merge(['product_id' => $product->id, 'source_code' => $code, 'kind' => 'alias'], $extra)));
    }

    private function resolver(array $options = []): CatalogResolver
    {
        return new CatalogResolver($this->tenant, $options);
    }

    public function test_an_alias_is_resolved_before_the_sku_and_before_creating_a_new_product(): void
    {
        $real = $this->product('NUEVO-1');
        $other = $this->product('VIEJO-1');
        $this->alias($real, 'viejo-1');

        $resolver = $this->resolver();
        $this->assertSame($real->id, $resolver->productId('VIEJO-1'), 'el alias gana al SKU del mismo código');
        $this->assertSame($real->id, $resolver->productIdByAlias(' Viejo-1 '));
        $this->assertSame($real->id, $resolver->productId('nuevo-1'));
        $this->assertTrue($resolver->hasProduct('viejo-1'));
        $this->assertSame([], $resolver->checkProduct('VIEJO-1'), 'un código con alias no es «producto nuevo»');
        $this->assertSame(0, $resolver->productsToCreate());

        $this->assertSame(1, $resolver->ensureProducts(['VIEJO-1' => ['name' => 'x', 'category' => null], 'ALIAS-QUE-NO-EXISTE' => ['name' => 'Nuevo de verdad', 'category' => null]]), 'solo se crea el código sin alias');
        $this->assertSame(['ALIAS-QUE-NO-EXISTE', 'NUEVO-1', 'VIEJO-1'], DB::table('products')->where('tenant_id', $this->tenant->id)->orderBy('sku')->pluck('sku')->all());
        $this->assertNotNull($other);
    }

    public function test_source_specific_aliases_win_over_general_ones_and_other_sources_are_ignored(): void
    {
        $general = $this->product('GENERAL');
        $specific = $this->product('ESPECIFICO');
        $foreign = $this->product('AJENO');
        $source = $this->withinTenant($this->tenant, fn () => DataSource::query()->create(['type' => 'file', 'name' => 'ERP A']));
        $otherSource = $this->withinTenant($this->tenant, fn () => DataSource::query()->create(['type' => 'file', 'name' => 'ERP B']));
        $this->alias($general, 'COD-1');
        $this->alias($specific, 'COD-1', ['data_source_id' => $source->id]);
        $this->alias($foreign, 'COD-2', ['data_source_id' => $otherSource->id]);

        $this->assertSame($specific->id, $this->resolver(['data_source_id' => $source->id])->productId('COD-1'), 'el alias de la fuente gana');
        $this->assertSame($general->id, $this->resolver(['data_source_id' => $otherSource->id])->productId('COD-1'), 'otra fuente: el general');
        $this->assertSame($general->id, $this->resolver()->productId('COD-1'), 'sin fuente: solo el general');
        $this->assertNull($this->resolver(['data_source_id' => $source->id])->productId('COD-2'), 'el alias de OTRA fuente nunca aplica');
        $this->assertNull($this->resolver()->productId('COD-2'));
    }

    public function test_validity_windows_are_respected_for_a_given_date_and_for_today(): void
    {
        $product = $this->product('ACTUAL');
        // «Hoy» es la fecha local de la empresa (como en CatalogResolver), no la de UTC: cerca de medianoche difieren.
        $today = now($this->tenant->timezone ?: 'UTC');
        $this->alias($product, 'RANGO', ['valid_from' => '2026-03-01', 'valid_to' => '2026-06-30']);
        $this->alias($product, 'FUTURO', ['valid_from' => $today->copy()->addDays(10)->toDateString()]);
        $this->alias($product, 'CADUCADO', ['valid_to' => $today->copy()->subDay()->toDateString()]);
        $this->alias($product, 'ABIERTO');
        $resolver = $this->resolver();

        $this->assertNull($resolver->productId('RANGO', '2026-02-28'));
        $this->assertSame($product->id, $resolver->productId('RANGO', '2026-03-01'), 'el extremo inicial es inclusivo');
        $this->assertSame($product->id, $resolver->productId('RANGO', '2026-06-30 23:59:00'), 'el final es inclusivo');
        $this->assertNull($resolver->productId('RANGO', '2026-07-01'));
        $this->assertNull($resolver->productId('RANGO'), 'sin fecha rige hoy');
        $this->assertNull($resolver->productId('FUTURO'));
        $this->assertSame($product->id, $resolver->productId('FUTURO', $today->copy()->addDays(11)->toDateString()));
        $this->assertNull($resolver->productId('CADUCADO'));
        $this->assertSame($product->id, $resolver->productId('ABIERTO', '1999-01-01'));

        // Fuera de vigencia el código vuelve a tratarse como un código normal: se crea un producto nuevo.
        $this->assertSame(1, $resolver->ensureProducts(['CADUCADO' => ['name' => 'Código reutilizado', 'category' => null]]));
        $this->assertNotSame($product->id, $resolver->productId('CADUCADO'));
        $this->assertSame(2, DB::table('products')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_merge_and_split_kinds_are_not_applied_by_the_resolver(): void
    {
        $product = $this->product('DESTINO');
        $this->alias($product, 'FUSION', ['kind' => 'merge']);
        $this->alias($product, 'DIVISION', ['kind' => 'split']);
        $resolver = $this->resolver();

        $this->assertNull($resolver->productId('FUSION'), 'fusión y división están DISEÑADAS: no redirigen nada');
        $this->assertNull($resolver->productId('DIVISION'));
    }

    public function test_customer_aliases_resolve_to_the_existing_customer_instead_of_creating_one(): void
    {
        $person = $this->withinTenant($this->tenant, function () {
            $identity = CustomerIdentity::fromCode('CLI-001');

            return Customer::query()->create(['external_code' => $identity['external_code'], 'customer_type' => 'unknown']);
        });
        $cedulaCode = CustomerIdentity::fromCode('1710034065')['external_code'];
        $this->withinTenant($this->tenant, fn () => CustomerAlias::query()->create(['customer_id' => $person->id, 'source_code' => $cedulaCode, 'kind' => 'alias']));
        $this->withinTenant($this->tenant, fn () => CustomerAlias::query()->create(['customer_id' => $person->id, 'source_code' => 'ANTIGUO', 'kind' => 'code_change', 'valid_to' => '2020-01-01']));

        $ids = $this->resolver()->ensureCustomers([
            '1710034065' => ['segment' => null, 'province' => null],     // cédula: alias del cliente existente
            'ANTIGUO' => ['segment' => null, 'province' => null],        // alias caducado: cliente nuevo
            'CLI-NUEVO' => ['segment' => null, 'province' => null],
        ]);

        $this->assertSame($person->id, $ids['1710034065']);
        $this->assertNotSame($person->id, $ids['ANTIGUO']);
        $this->assertNotSame($person->id, $ids['CLI-NUEVO']);
        $this->assertSame(3, DB::table('customers')->where('tenant_id', $this->tenant->id)->count(), 'solo se crearon los dos que no tienen alias vigente');
        $this->assertNull(DB::table('customers')->where('tenant_id', $this->tenant->id)->where('external_code', $cedulaCode)->first(), 'la cédula con alias no creó una identidad nueva');
    }

    public function test_an_import_with_the_old_code_after_a_code_change_resolves_to_the_same_product(): void
    {
        $product = $this->product('OLD-1');
        $this->postJson("/api/v1/catalog/products/{$product->id}/code-change", ['old_code' => 'OLD-1', 'new_code' => 'NEW-1', 'effective_from' => '2026-04-01'])->assertOk();

        $this->importCsv('sales', ['document_number', 'issued_at', 'branch_code', 'product_sku', 'product_name', 'quantity', 'unit_price'], [
            ['F-1', '2026-03-10 09:00:00', '001', 'OLD-1', 'Producto', 2, 5],     // el ERP reenvía historia con el código viejo
            ['F-2', '2026-04-20 09:00:00', '001', 'NEW-1', 'Producto', 3, 5],     // y lo nuevo con el código nuevo
        ], true, ['ingestion_mode' => 'backfill']);

        $this->assertSame(1, DB::table('products')->where('tenant_id', $this->tenant->id)->count(), 'ningún producto duplicado por el código viejo');
        $this->assertSame(
            [$product->id],
            DB::table('sale_items')->where('tenant_id', $this->tenant->id)->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all(),
            'ambas ventas apuntan a la misma identidad canónica',
        );
        $this->assertSame(2, DB::table('sale_items')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_a_sales_batch_with_the_old_code_uses_the_same_product(): void
    {
        $product = $this->product('OLD-7');
        $this->postJson("/api/v1/catalog/products/{$product->id}/code-change", ['old_code' => 'OLD-7', 'new_code' => 'NEW-7', 'effective_from' => '2026-04-01'])->assertOk();

        $this->postJson('/api/v1/sales/batch', ['ingestion_mode' => 'backfill', 'documents' => [[
            'document_number' => 'B-1', 'issued_at' => '2026-03-02T10:00:00-05:00', 'branch_code' => '001',
            'items' => [['product_sku' => 'old-7', 'product_name' => 'Producto', 'quantity' => 2, 'unit_price' => 5]],
        ]]])->assertOk()->assertJsonPath('data.created', 1);

        $this->assertSame(1, DB::table('products')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame($product->id, (int) DB::table('sale_items')->where('tenant_id', $this->tenant->id)->value('product_id'));
    }

    public function test_ingestion_resolves_aliases_with_the_date_of_each_document_not_with_todays(): void
    {
        $current = $this->product('NEW-1');
        // El código OLD-1 identificó a NEW-1 solo durante 2025; después se reutilizó para otra cosa.
        $this->alias($current, 'OLD-1', ['valid_from' => '2025-01-01', 'valid_to' => '2025-12-31']);
        $document = fn (string $number, string $date) => [
            'document_number' => $number, 'issued_at' => "{$date}T10:00:00-05:00", 'branch_code' => '001',
            'items' => [['product_sku' => 'OLD-1', 'product_name' => 'Producto', 'quantity' => 1, 'unit_price' => 5]],
        ];

        $this->postJson('/api/v1/sales/batch', ['ingestion_mode' => 'backfill', 'documents' => [$document('F-2025', '2025-06-01'), $document('F-2026', '2026-03-01')]])
            ->assertOk()->assertJsonPath('data.created', 2);

        $ids = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->where('sale_items.tenant_id', $this->tenant->id)
            ->pluck('sale_items.product_id', 'sales.document_number')->map(fn ($v) => (int) $v)->all();
        $reused = (int) DB::table('products')->where('tenant_id', $this->tenant->id)->where('sku', 'OLD-1')->value('id');
        $this->assertGreaterThan(0, $reused, 'el documento de 2026 (alias vencido) crea el producto OLD-1');
        $this->assertSame($current->id, $ids['F-2025'], 'el de 2025 está dentro de la vigencia del alias: mismo producto');
        $this->assertSame($reused, $ids['F-2026']);
        $this->assertNotSame($current->id, $reused);

        // Una corrección posterior del mismo documento (camino de comparación de versiones) mantiene la misma resolución por fecha.
        $this->postJson('/api/v1/sales/batch', ['ingestion_mode' => 'backfill', 'documents' => [$document('F-2025', '2025-06-01')]])->assertOk()->assertJsonPath('data.unchanged', 1);
    }

    public function test_a_csv_import_resolves_aliases_with_the_document_date(): void
    {
        $current = $this->product('NEW-2');
        $this->alias($current, 'OLD-2', ['valid_to' => '2025-12-31']);

        $this->importCsv('sales', ['document_number', 'issued_at', 'branch_code', 'product_sku', 'product_name', 'quantity', 'unit_price'], [
            ['F-1', '2025-05-10 09:00:00', '001', 'OLD-2', 'Producto', 2, 5],
            ['F-2', '2026-05-10 09:00:00', '001', 'OLD-2', 'Producto', 3, 5],
        ], true, ['ingestion_mode' => 'backfill']);

        $byDoc = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->where('sale_items.tenant_id', $this->tenant->id)
            ->pluck('sale_items.product_id', 'sales.document_number')->map(fn ($v) => (int) $v)->all();
        $this->assertSame($current->id, $byDoc['F-1']);
        $this->assertNotSame($current->id, $byDoc['F-2'], 'fuera de la vigencia es otro producto (OLD-2)');
        $this->assertSame(2, DB::table('products')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_ingestion_resolves_customer_aliases_with_the_date_of_each_document(): void
    {
        $existing = $this->withinTenant($this->tenant, fn () => Customer::query()->create(['external_code' => 'CLI-REAL', 'customer_type' => 'unknown']));
        $this->withinTenant($this->tenant, fn () => CustomerAlias::query()->create(['customer_id' => $existing->id, 'source_code' => 'CLI-ALT', 'kind' => 'alias', 'valid_to' => '2025-12-31']));
        $document = fn (string $number, string $date) => [
            'document_number' => $number, 'issued_at' => "{$date}T10:00:00-05:00", 'branch_code' => '001', 'customer_code' => 'CLI-ALT',
            'items' => [['product_sku' => 'P', 'product_name' => 'P', 'quantity' => 1, 'unit_price' => 5]],
        ];

        $this->postJson('/api/v1/sales/batch', ['ingestion_mode' => 'backfill', 'documents' => [$document('F-2025', '2025-06-01'), $document('F-2026', '2026-03-01')]])->assertOk();

        $customers = DB::table('sales')->where('tenant_id', $this->tenant->id)->pluck('customer_id', 'document_number')->map(fn ($v) => (int) $v)->all();
        $this->assertSame($existing->id, $customers['F-2025'], 'dentro de la vigencia del alias: el cliente existente');
        $this->assertNotSame($existing->id, $customers['F-2026'], 'alias vencido: el código directo es otro cliente');
        $this->assertSame('CLI-ALT', DB::table('customers')->where('id', $customers['F-2026'])->value('external_code'));
        $this->assertSame(2, DB::table('customers')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_a_product_master_import_with_an_old_code_updates_the_canonical_product_instead_of_duplicating_it(): void
    {
        $product = $this->product('OLD-9');
        $this->postJson("/api/v1/catalog/products/{$product->id}/code-change", ['old_code' => 'OLD-9', 'new_code' => 'NEW-9', 'effective_from' => '2026-01-01'])->assertOk();

        $job = $this->importCsv('products', ['sku', 'name', 'current_price'], [['old-9', 'Nombre actualizado', 12.5]]);

        $this->assertSame(1, DB::table('products')->where('tenant_id', $this->tenant->id)->count(), 'ningún producto duplicado');
        $row = DB::table('products')->where('tenant_id', $this->tenant->id)->first();
        $this->assertSame([$product->id, 'NEW-9', 'Nombre actualizado'], [(int) $row->id, $row->sku, $row->name], 'se actualizó el producto canónico bajo su código vigente');
        $this->assertEquals(12.5, (float) $row->current_price);
        $warnings = collect($this->importErrors($job, 'warning'))->where('error_code', 'alias_resolved');
        $this->assertCount(1, $warnings, 'el hallazgo WARNING deja constancia del alias');
        $this->assertStringContainsString("'NEW-9'", $warnings->first()['message']);
    }

    public function test_a_product_master_with_both_the_old_and_the_new_code_reports_a_duplicate(): void
    {
        $product = $this->product('OLD-8');
        $this->postJson("/api/v1/catalog/products/{$product->id}/code-change", ['old_code' => 'OLD-8', 'new_code' => 'NEW-8', 'effective_from' => '2026-01-01'])->assertOk();

        $job = $this->importCsv('products', ['sku', 'name'], [['NEW-8', 'Con el código nuevo'], ['OLD-8', 'Con el código viejo']], false);

        $errors = collect($this->importErrors($job));
        $this->assertSame(['duplicate_in_file'], $errors->pluck('error_code')->unique()->values()->all(), 'ambas filas son el mismo producto canónico');
        $this->assertSame(1, DB::table('products')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_aliases_of_one_company_never_resolve_for_another(): void
    {
        $mine = $this->product('MIO');
        $this->alias($mine, 'CODIGO-COMPARTIDO');
        [$b] = $this->createTenantWithUser();
        $theirs = $this->product('SUYO', $b);

        $resolverB = new CatalogResolver($b);
        $this->assertNull($resolverB->productId('CODIGO-COMPARTIDO'), 'el alias de A no existe para B');
        $this->assertSame($theirs->id, $resolverB->productId('SUYO'));
        $this->assertSame(1, $resolverB->ensureProducts(['CODIGO-COMPARTIDO' => ['name' => 'Producto de B', 'category' => null]]), 'B crea su propio producto');
        $this->assertSame($mine->id, $this->resolver()->productId('CODIGO-COMPARTIDO'));
        $this->assertSame(1, DB::table('products')->where('tenant_id', $this->tenant->id)->count());
    }
}
