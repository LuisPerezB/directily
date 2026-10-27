<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\ImportJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class CatalogImportsTest extends TestCase
{
    use BuildsImportFiles, InteractsWithTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeImportInfrastructure();
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->actingAsTenantUser($this->owner, $this->tenant);
    }

    private function importAndConfirm(string $entity, array $headers, array $rows, array $options = []): ImportJob
    {
        $job = $this->uploadAndValidate($this->tenant, $this->csv($headers, $rows), $entity, [], $options, "{$entity}.csv");
        $this->assertSame('validated', $job->status, (string) $job->error_message);
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();

        return $job;
    }

    public function test_branches_suppliers_products_with_price_history(): void
    {
        $this->importAndConfirm('branches', ['code', 'name', 'province', 'canton'], [['001', 'Matriz', 'Pichincha', 'Quito'], ['002', 'Sur', 'Guayas', 'Guayaquil']]);
        $this->assertSame(2, DB::table('branches')->where('tenant_id', $this->tenant->id)->count());

        $this->importAndConfirm('suppliers', ['code', 'name', 'country', 'default_lead_time_days'], [['P1', 'Proveedor Uno', 'EC', '5']]);
        $this->assertSame(5, (int) DB::table('suppliers')->where('code', 'P1')->value('default_lead_time_days'));

        $headers = ['Código', 'Nombre', 'Categoría', 'Unidad', 'Marca', 'PVP', 'Costo', 'Activo'];
        $this->importAndConfirm('products', $headers, [['A1', 'Cemento', 'Construcción', 'saco', 'X', '8.50', '6.90', 'si'], ['A2', 'Arena', 'Construcción', 'm3', '', '20', '', 'no']]);
        $this->assertSame(2, DB::table('products')->where('tenant_id', $this->tenant->id)->count());
        $this->assertFalse((bool) DB::table('products')->where('sku', 'A2')->value('is_active'));
        $this->assertSame(1, DB::table('product_categories')->where('tenant_id', $this->tenant->id)->count());

        // Cambio de precio: actualiza el producto y agrega historial sin duplicar productos.
        $job = $this->importAndConfirm('products', $headers, [['A1', 'Cemento', 'Construcción', 'saco', 'X', '9.10', '6.90', 'si']]);
        $this->assertSame(1, $this->getJson("/api/v1/imports/{$job->id}")->json('data.stats.products_updated'));
        $this->assertSame(2, DB::table('products')->where('tenant_id', $this->tenant->id)->count());
        $this->assertEquals(9.1, (float) DB::table('products')->where('sku', 'A1')->value('current_price'));
        $this->assertSame(2, DB::table('product_price_history')->where('product_id', DB::table('products')->where('sku', 'A1')->value('id'))->count());
    }

    public function test_inventory_upsert_is_idempotent_and_flags_stockouts(): void
    {
        $this->createBranch($this->tenant, '001');
        $headers = ['Fecha', 'Sucursal', 'SKU', 'Existencia', 'Reservado', 'En tránsito'];
        $rows = [['2026-03-01', '001', 'A1', '10', '1', '5'], ['2026-03-01', '001', 'A2', '0', '', ''], ['2026-03-02', '001', 'A2', '-2', '', '']];
        $job = $this->importAndConfirm('inventory', $headers, $rows);
        $stats = $this->getJson("/api/v1/imports/{$job->id}")->json('data.stats');
        $this->assertSame(2, $stats['stockout_rows']);
        $this->assertSame(3, DB::table('inventory_snapshots')->where('tenant_id', $this->tenant->id)->count());

        $rows[0][3] = '12';
        $this->importAndConfirm('inventory', $headers, $rows);
        $this->assertSame(3, DB::table('inventory_snapshots')->where('tenant_id', $this->tenant->id)->count());
        $this->assertEquals(12.0, (float) DB::table('inventory_snapshots')->where('snapshot_date', '2026-03-01')->orderByDesc('on_hand')->value('on_hand'));

        $dup = $this->uploadAndValidate($this->tenant, $this->csv($headers, [$rows[0], $rows[0]]), 'inventory', filename: 'inv.csv');
        $this->assertSame(1, $dup->stats['rows_error']);
    }

    public function test_lead_times_and_promotions(): void
    {
        $this->createBranch($this->tenant, '001');
        $this->postJson('/api/v1/products', ['sku' => 'A1', 'name' => 'A1'])->assertCreated();
        $this->postJson('/api/v1/product-categories', ['name' => 'Pinturas'])->assertCreated();

        $headers = ['product_sku', 'supplier_code', 'branch_code', 'lead_time_days', 'lead_time_std_days'];
        $this->importAndConfirm('lead_times', $headers, [['A1', 'P9', '', '7', '1.5'], ['', 'P9', '', '10', '']]);
        $this->importAndConfirm('lead_times', $headers, [['A1', 'P9', '', '8', '1.5']]);
        $this->assertSame(2, DB::table('supplier_lead_times')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(8, (int) DB::table('supplier_lead_times')->whereNotNull('product_id')->value('lead_time_days'));
        $this->assertTrue(DB::table('suppliers')->where('code', 'P9')->exists());

        $pHeaders = ['name', 'promo_type', 'discount_pct', 'starts_on', 'ends_on', 'branch_code', 'product_sku', 'category'];
        $rows = [['Feria', 'percentage', '0.15', '2026-03-10', '2026-03-20', '', '', 'Pinturas'], ['Liquidación', 'percentage', '20%', '2026-04-01', '2026-04-05', '001', 'A1', '']];
        $this->importAndConfirm('promotions', $pHeaders, $rows);
        $this->importAndConfirm('promotions', $pHeaders, $rows);
        $this->assertSame(2, DB::table('promotions')->where('tenant_id', $this->tenant->id)->count());
        $this->assertEquals(0.2, (float) DB::table('promotions')->where('name', 'Liquidación')->value('discount_pct'));
    }
}
