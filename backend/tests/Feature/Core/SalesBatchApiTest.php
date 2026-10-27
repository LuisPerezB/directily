<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class SalesBatchApiTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001');
    }

    /** @return array<string, mixed> */
    private function payload(float $qty = 2): array
    {
        return ['documents' => [
            ['document_number' => '001-002-000000010', 'issued_at' => '2026-05-04T10:00:00-05:00', 'branch_code' => '001', 'channel' => 'web',
                'items' => [['product_sku' => 'API-1', 'product_name' => 'Producto API', 'quantity' => $qty, 'unit_price' => 4.5, 'discount' => 0.5]]],
            ['document_number' => '001-002-000000011', 'issued_at' => '2026-05-04', 'items' => [['product_sku' => 'API-1', 'quantity' => 1, 'unit_price' => 4.5]]],
        ]];
    }

    public function test_batch_ingestion_is_idempotent_and_detects_changes(): void
    {
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $this->postJson('/api/v1/sales/batch', $this->payload())->assertOk()->assertJsonPath('data.created', 2)->assertJsonPath('data.rejected', 0);
        $this->postJson('/api/v1/sales/batch', $this->payload())->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.unchanged', 2);
        $this->postJson('/api/v1/sales/batch', $this->payload(5))->assertOk()->assertJsonPath('data.updated', 1)->assertJsonPath('data.unchanged', 1);

        $this->assertSame(2, DB::table('sales')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(2, DB::table('sale_items')->where('tenant_id', $this->tenant->id)->count());
        $this->assertEquals(22.0, (float) DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')->where('s.document_number', '001-002-000000010')->value('si.net_amount'));
    }

    public function test_batch_reports_per_document_errors_with_same_rules_as_importer(): void
    {
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $payload = ['documents' => [
            ['document_number' => 'B-1', 'issued_at' => 'ayer', 'items' => [['product_sku' => 'X', 'quantity' => 1, 'unit_price' => 1]]],
            ['document_number' => 'B-2', 'issued_at' => '2026-05-01', 'currency' => 'EUR', 'items' => [['product_sku' => 'X', 'quantity' => 1, 'unit_price' => 1]]],
            ['document_number' => 'B-3', 'issued_at' => '2026-05-01', 'items' => [['product_sku' => 'X', 'quantity' => 1, 'unit_price' => 1]]],
        ]];
        $response = $this->postJson('/api/v1/sales/batch', $payload)->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.rejected', 2);
        $codes = collect($response->json('data.errors'))->pluck('code')->all();
        $this->assertContains('invalid_date', $codes);
        $this->assertContains('currency_mismatch', $codes);

        $this->postJson('/api/v1/sales/batch', ['documents' => [['document_number' => 'B-9', 'issued_at' => 'x', 'items' => [['product_sku' => 'X', 'quantity' => 1, 'unit_price' => 1]]]]])
            ->assertUnprocessable();
        $this->postJson('/api/v1/sales/batch', ['documents' => []])->assertUnprocessable()->assertJsonValidationErrors('documents');
    }

    public function test_data_source_token_ingests_only_into_its_tenant(): void
    {
        [$otherTenant] = $this->createTenantWithUser();
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $sourceId = $this->postJson('/api/v1/data-sources', ['type' => 'rest_api', 'name' => 'ERP central'])->assertCreated()->json('data.id');
        $token = $this->postJson("/api/v1/data-sources/{$sourceId}/tokens")->assertCreated()->json('data.token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->withHeader('X-Tenant-ID', $otherTenant->uuid)
            ->postJson('/api/v1/sales/batch', $this->payload())->assertOk()->assertJsonPath('data.created', 2);
        $this->assertSame(2, DB::table('sales')->where('tenant_id', $this->tenant->id)->where('data_source_id', $sourceId)->count());
        $this->assertSame(0, DB::table('sales')->where('tenant_id', $otherTenant->id)->count());
        $this->assertNotNull(DB::table('data_sources')->where('id', $sourceId)->value('last_synced_at'));
    }

    public function test_sales_listing_detail_and_summary(): void
    {
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $this->postJson('/api/v1/sales/batch', $this->payload())->assertOk();
        $this->getJson('/api/v1/sales?date_from=2026-05-01&date_to=2026-05-31')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.items_count', 1);
        $id = DB::table('sales')->where('document_number', '001-002-000000010')->value('id');
        $this->getJson("/api/v1/sales/{$id}")->assertOk()->assertJsonPath('data.items.0.product.sku', 'API-1')->assertJsonPath('data.channel', 'web');
        $this->getJson('/api/v1/sales/summary?date_from=2026-05-01&date_to=2026-05-31&granularity=month')
            ->assertOk()->assertJsonPath('data.series.0.period', '2026-05-01')->assertJsonPath('data.totals.units', 3)->assertJsonPath('data.totals.documents', 2);
        $this->getJson('/api/v1/sales/summary?granularity=year')->assertUnprocessable();
    }
}
