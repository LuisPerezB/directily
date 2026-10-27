<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Enums\TenantRole;
use App\Jobs\Forecasting\RefreshReadiness;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class SalesImportTest extends TestCase
{
    use BuildsImportFiles, InteractsWithTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeImportInfrastructure();
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001', 'Matriz');
        $this->actingAsTenantUser($this->owner, $this->tenant);
    }

    public function test_upload_detects_columns_and_suggests_mapping_from_spanish_headers(): void
    {
        $response = $this->uploadImport($this->csv(self::SALES_HEADERS, $this->validSalesRows()))->assertCreated();

        $response->assertJsonPath('data.status', 'awaiting_mapping')
            ->assertJsonPath('data.suggested_mapping.document_number', 'Nro Factura')
            ->assertJsonPath('data.suggested_mapping.issued_at', 'Fecha Emisión')
            ->assertJsonPath('data.suggested_mapping.product_sku', 'Código')
            ->assertJsonPath('data.suggested_mapping.quantity', 'Cantidad')
            ->assertJsonPath('data.suggested_mapping.unit_price', 'Precio Unitario')
            ->assertJsonPath('data.suggested_mapping.net_amount', 'Subtotal')
            ->assertJsonPath('data.suggested_mapping.tax', 'IVA')
            ->assertJsonPath('data.suggested_mapping.customer_code', 'Cliente')
            ->assertJsonCount(5, 'data.preview.rows');
        $this->assertSame(64, strlen($response->json('data.file_hash')));
    }

    public function test_full_flow_validate_confirm_and_reimport_is_idempotent(): void
    {
        $content = $this->csv(self::SALES_HEADERS, $this->validSalesRows());
        $job = $this->uploadAndValidate($this->tenant, $content);
        $this->assertSame('validated', $job->status);
        $this->assertSame(5, $job->stats['rows_total']);
        $this->assertSame(0, $job->stats['rows_error']);
        $this->assertSame(3, $job->stats['documents_new']);
        $this->assertSame(1, $job->stats['voided_documents']);
        $this->assertSame(3, $job->stats['products_new']);
        $this->getJson("/api/v1/imports/{$job->id}")->assertOk()->assertJsonPath('data.quality_report.score', fn ($s) => $s > 50)
            ->assertJsonCount(5, 'data.normalized_preview');

        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
        $this->getJson("/api/v1/imports/{$job->id}")->assertJsonPath('data.status', 'completed');
        $this->assertSame(3, DB::table('sales')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(5, DB::table('sale_items')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(3, DB::table('products')->where('tenant_id', $this->tenant->id)->count());
        Bus::assertDispatched(RefreshReadiness::class, fn ($job) => $job->tenantId === $this->tenant->id);

        // Precio, descuento y costo históricos tal como vinieron; neto calculado si faltaba.
        $line = DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')->where('s.document_number', '001-001-000000002')->first(['si.*', 's.channel', 's.sale_date as sdate']);
        $this->assertEquals(34.0, (float) $line->net_amount);
        $this->assertEquals(8.5, (float) $line->unit_price);
        $this->assertSame('wholesale', $line->channel);
        $this->assertSame('2026-01-06', $line->sdate);
        $this->assertSame('voided', DB::table('sales')->where('document_number', '001-001-000000003')->value('status'));
        // Cédula convertida en huella; nunca en claro.
        $this->assertFalse(DB::table('customers')->where('external_code', '1710034065')->exists());
        $this->assertTrue(DB::table('customers')->where('customer_type', 'person')->whereNotNull('tax_id_hash')->exists());
        $this->assertTrue(DB::table('customers')->where('external_code', 'CONSUMIDOR_FINAL')->exists());

        // Reimportar el mismo archivo no duplica nada.
        $again = $this->uploadAndValidate($this->tenant, $content);
        $this->assertSame(0, $again->stats['documents_new']);
        $this->assertSame(3, $again->stats['documents_unchanged']);
        $this->getJson("/api/v1/imports/{$again->id}")->assertJsonPath('data.stats.previous_import_id', $job->id);
        $this->postJson("/api/v1/imports/{$again->id}/confirm")->assertAccepted();
        $this->assertSame(3, DB::table('sales')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(5, DB::table('sale_items')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_changed_document_is_updated_and_its_lines_replaced(): void
    {
        $job = $this->uploadAndValidate($this->tenant, $this->csv(self::SALES_HEADERS, $this->validSalesRows()));
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
        $saleId = DB::table('sales')->where('document_number', '001-001-000000001')->value('id');

        $rows = $this->validSalesRows();
        $rows[0][8] = '12'; // cambia la cantidad de la línea 1
        unset($rows[1]);    // y elimina la línea 2
        $job2 = $this->uploadAndValidate($this->tenant, $this->csv(self::SALES_HEADERS, array_values($rows)));
        $this->assertSame(1, $job2->stats['documents_updated']);
        $this->assertSame(2, $job2->stats['documents_unchanged']);
        $this->postJson("/api/v1/imports/{$job2->id}/confirm")->assertAccepted();

        $this->assertSame($saleId, DB::table('sales')->where('document_number', '001-001-000000001')->value('id'));
        $items = DB::table('sale_items')->where('sale_id', $saleId)->get();
        $this->assertCount(1, $items);
        $this->assertEquals(12.0, (float) $items[0]->quantity);
    }

    public function test_validation_reports_errors_and_blocks_confirmation(): void
    {
        $rows = [
            ['F-1', 'Factura', '', 'no-es-fecha', '001', 'A', 'A', '', '1', '2', '', '', '', '', '', ''],          // fecha inválida
            ['F-2', 'Factura', '', '2026-01-02', '001', 'A', 'A', '', 'abc', '2', '', '', '', '', '', ''],         // cantidad no numérica
            ['F-3', 'Factura', '', '2026-01-02', '001', '', 'A', '', '1', '2', '', '', '', '', '', ''],            // falta SKU
            ['F-4', 'Factura', '', '2099-01-01', '001', 'A', 'A', '', '1', '2', '', '', '', '', '', ''],           // fecha futura
            ['F-5', 'Factura', '', '2026-01-02', '999', 'A', 'A', '', '1', '2', '', '', '', '', '', ''],           // sucursal desconocida
            ['F-6', 'Factura', '', '2026-01-02', '001', 'A', 'A', '', '1', '-2', '', '', '', '', '', ''],          // precio negativo
            ['F-7', 'Factura', '', '2026-01-02', '001', 'A', 'A', '', '-3', '2', '', '', '', '', '', ''],          // devolución (warning)
            ['F-8', 'Factura', '', '2026-01-02', '001', 'A', 'A', '', '0', '2', '', '', '', '', '', ''],           // cantidad cero (warning)
            ['F-9', 'Factura', '', '2026-01-02', '001', 'A', 'A', '', '1', '2', '', '', '9', '', '', ''],          // neto distinto (warning)
            ['F-10', 'Factura', '', '2026-01-02', '001', 'A', 'A', '', '1', '2', '', '', '', '', '', ''],
            ['F-10', 'Factura', '', '2026-01-03', '001', 'B', 'B', '', '1', '2', '', '', '', '', '', ''],          // conflicto de cabecera
            ['NC-1', 'Nota de crédito', '', '2026-01-02', '001', 'A', 'A', '', '1', '2', '', '', '', '', '', ''], // devolución
        ];
        $job = $this->uploadAndValidate($this->tenant, $this->csv(self::SALES_HEADERS, $rows));

        $this->assertSame('validated', $job->status);
        $this->assertSame(7, $job->stats['rows_error']);
        $codes = DB::table('import_errors')->where('import_job_id', $job->id)->pluck('error_code')->all();
        foreach (['invalid_date', 'invalid_number', 'required', 'future_date', 'unknown_branch', 'negative_price', 'negative_quantity', 'zero_quantity', 'net_amount_mismatch', 'document_conflict', 'unknown_product'] as $code) {
            $this->assertContains($code, $codes, "Falta el código {$code}");
        }
        $this->assertSame(1, $job->stats['credit_notes']);
        $this->assertSame(2, $job->stats['return_lines']);

        $this->getJson("/api/v1/imports/{$job->id}/errors?severity=error")->assertOk()->assertJsonPath('data.0.severity', 'error');
        $csv = $this->get("/api/v1/imports/{$job->id}/errors.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('invalid_date', $csv);

        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertStatus(409)->assertJsonPath('code', 'has_errors');

        // Corregir: importar solo filas válidas (los documentos con errores se omiten completos).
        $suggested = $this->getJson("/api/v1/imports/{$job->id}")->json('data.suggested_mapping');
        $this->putJson("/api/v1/imports/{$job->id}/mapping", ['mapping' => $suggested, 'options' => ['skip_invalid_rows' => true]])->assertAccepted();
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
        $numbers = DB::table('sales')->where('tenant_id', $this->tenant->id)->orderBy('document_number')->pluck('document_number')->all();
        $this->assertSame(['F-7', 'F-9', 'NC-1'], $numbers);
        $this->assertSame('credit_note', DB::table('sales')->where('document_number', 'NC-1')->value('document_type'));
        $this->assertTrue((bool) DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')->where('s.document_number', 'F-7')->value('si.is_return'));
    }

    public function test_duplicate_rows_and_line_numbers_in_file(): void
    {
        $headers = [...self::SALES_HEADERS, 'Nro Línea'];
        $rows = [
            ['D-1', '', '', '2026-01-02', '001', 'A', 'A', '', '1', '2', '', '', '', '', '', '', '1'],
            ['D-1', '', '', '2026-01-02', '001', 'A', 'A', '', '1', '2', '', '', '', '', '', '', '1'], // fila idéntica: se omite
            ['D-2', '', '', '2026-01-02', '001', 'A', 'A', '', '1', '2', '', '', '', '', '', '', '1'],
            ['D-2', '', '', '2026-01-02', '001', 'B', 'B', '', '5', '3', '', '', '', '', '', '', '1'], // línea repetida: error
        ];
        $job = $this->uploadAndValidate($this->tenant, $this->csv($headers, $rows));
        $this->assertSame(2, $job->stats['duplicates_in_file']);
        $this->assertSame(1, $job->stats['documents_valid']);
        $this->assertSame(1, $job->stats['rows_error']);
    }

    public function test_semicolon_decimal_comma_windows1252_and_day_first_dates(): void
    {
        $content = $this->csv(['Factura', 'Fecha', 'Código', 'Descripción', 'Cantidad', 'Precio', 'Descuento'], [
            ['A-1', '15/01/2026', 'Ñ-01', 'Señal de tránsito', '2', '1.234,50', '10%'],
            ['A-2', '16/01/2026', 'Ñ-01', 'Señal de tránsito', '1,5', '10,25', '0'],
        ], ';');
        $content = mb_convert_encoding($content, 'Windows-1252', 'UTF-8');
        $job = $this->uploadAndValidate($this->tenant, $content, options: ['create_missing_products' => true]);

        $this->assertSame(0, $job->stats['rows_error'], json_encode(DB::table('import_errors')->get()));
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
        $item = DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')->where('s.document_number', 'A-1')->first(['si.*', 's.sale_date as sdate']);
        $this->assertEquals(1234.5, (float) $item->unit_price);
        $this->assertEquals(246.9, (float) $item->discount_amount);
        $this->assertEquals(2222.1, (float) $item->net_amount);
        $this->assertSame('2026-01-15', $item->sdate);
        $this->assertSame('Señal de tránsito', DB::table('products')->where('sku', 'Ñ-01')->value('name'));
        $this->assertEquals(1.5, (float) DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')->where('s.document_number', 'A-2')->value('si.quantity'));
    }

    public function test_xlsx_import_with_excel_dates(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Numero Documento', 'Fecha', 'SKU', 'Producto', 'Cantidad', 'PVP']));
        $writer->addRow(Row::fromValues(['X-1', new \DateTimeImmutable('2026-02-10 10:30:00'), 'TOR-8', 'Tornillo 8mm', 100, 0.05]));
        $writer->addRow(Row::fromValues(['X-2', new \DateTimeImmutable('2026-02-11'), 1001, 'Código numérico', 3, 2.5]));
        $writer->close();

        $job = $this->uploadAndValidate($this->tenant, (string) file_get_contents($path), filename: 'ventas.xlsx');
        @unlink($path);
        $this->assertSame('validated', $job->status, (string) $job->error_message);
        $this->assertSame(0, $job->stats['rows_error']);
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
        $this->assertSame('2026-02-10', DB::table('sales')->where('document_number', 'X-1')->value('sale_date'));
        $this->assertTrue(DB::table('products')->where('sku', '1001')->exists());
    }

    public function test_mapping_validation_and_state_machine(): void
    {
        $upload = $this->uploadImport($this->csv(self::SALES_HEADERS, $this->validSalesRows()))->assertCreated();
        $id = $upload->json('data.id');
        $this->putJson("/api/v1/imports/{$id}/mapping", ['mapping' => ['document_number' => 'Nro Factura']])
            ->assertUnprocessable()->assertJsonValidationErrors(['mapping.issued_at', 'mapping.product_sku']);
        $this->putJson("/api/v1/imports/{$id}/mapping", ['mapping' => array_merge($upload->json('data.suggested_mapping'), ['tax' => 'Columna inexistente'])])
            ->assertUnprocessable()->assertJsonValidationErrors('mapping.tax');
        $this->postJson("/api/v1/imports/{$id}/confirm")->assertStatus(409);
        $this->postJson("/api/v1/imports/{$id}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->putJson("/api/v1/imports/{$id}/mapping", ['mapping' => $upload->json('data.suggested_mapping')])->assertStatus(409);
    }

    public function test_unsupported_file_and_empty_file(): void
    {
        $this->post('/api/v1/imports', ['file' => UploadedFile::fake()->create('ventas.pdf', 10), 'entity_type' => 'sales'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->uploadImport("\n\n")->assertCreated()->assertJsonPath('data.status', 'failed');
    }

    public function test_viewer_cannot_import_and_analyst_can(): void
    {
        $viewer = $this->addUserToTenant($this->tenant, TenantRole::Viewer);
        $this->actingAsTenantUser($viewer, $this->tenant);
        $this->uploadImport($this->csv(self::SALES_HEADERS, $this->validSalesRows()))->assertForbidden();
        $analyst = $this->addUserToTenant($this->tenant, TenantRole::Analyst);
        $this->actingAsTenantUser($analyst, $this->tenant);
        $this->uploadImport($this->csv(self::SALES_HEADERS, $this->validSalesRows()))->assertCreated();
    }

    public function test_import_rows_plan_limit_blocks_confirmation(): void
    {
        $this->tenant->forceFill(['plan_overrides' => ['max_import_rows_per_month' => 3]])->save();
        $job = $this->uploadAndValidate($this->tenant, $this->csv(self::SALES_HEADERS, $this->validSalesRows()));
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertStatus(402)->assertJsonPath('limit', 'max_import_rows_per_month');
    }

    public function test_templates_and_field_definitions(): void
    {
        $this->get('/api/v1/import-templates/sales')->assertOk();
        $this->getJson('/api/v1/import-fields/sales')->assertOk()->assertJsonFragment(['key' => 'document_number', 'required' => true]);
        $this->getJson('/api/v1/import-fields/desconocido')->assertNotFound();
    }
}
