<?php

declare(strict_types=1);

namespace Tests\Feature\DataQuality;

use App\Models\BronzeObject;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataCore\Bronze\BronzeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataQuality\Support\IngestsSales;
use Tests\TestCase;

/**
 * Captura Bronze ANTES de normalizar y linaje Silver (ADR-V25-04 y ADR-V25-24; W2-A) para archivo subido y lote REST (la extracción SQL
 * está en SqlIngestionQualityTest): objeto Bronze con SHA-256, escritura de `bronze_object_id`, `source_row` y `schema_version` en
 * ventas e inventario, linaje de las revisiones archivadas y detención de la carga si el almacén falla (BLOCKER).
 */
final class BronzeLineageTest extends TestCase
{
    use BuildsImportFiles, IngestsSales, InteractsWithTenants, RefreshDatabase;

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

    public function test_the_uploaded_file_is_stored_in_bronze_before_any_normalization(): void
    {
        $content = $this->csv(self::SALES_HEADERS, [$this->salesRow('F-1', 'Factura', '', '2026-01-05 10:00:00', '001', 'CEM-50', '2', '8.50', '1710034065')]);
        $upload = $this->uploadImport($content)->assertCreated();

        // Recién subido (analizado, sin mapear ni validar): ya está en Bronze y nada llegó a Silver.
        $this->assertSame('awaiting_mapping', $upload->json('data.status'));
        $this->assertSame(0, DB::table('sales')->count());
        $object = DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($object);
        $this->assertSame(hash('sha256', $content), $object->sha256, 'el original íntegro');
        $this->assertSame(strlen($content), (int) $object->size_bytes);
        $this->assertSame(['file_upload', 'sales', 'ventas.csv'], [$object->kind, $object->entity, $object->original_name]);
        $this->assertSame($this->owner->id, (int) $object->received_by_user_id);
        $this->assertSame($object->id, (int) DB::table('import_jobs')->where('id', $upload->json('data.id'))->value('bronze_object_id'));
        $this->assertSame(1, (int) $object->schema_version);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $object->schema_fingerprint);
        $this->assertSame($content, $this->withinTenant($this->tenant, fn () => app(BronzeStore::class)->read(BronzeObject::query()->findOrFail($object->id))));
        $this->assertStringContainsString('tenants/'.$this->tenant->uuid.'/bronze/sales/', $object->object_key);
    }

    public function test_sales_carry_the_lineage_and_the_same_file_reuses_the_same_object(): void
    {
        $rows = [
            $this->salesRow('F-1', 'Factura', '', '2026-01-05 10:00:00', '001', 'CEM-50'),
            $this->salesRow('F-1', 'Factura', '', '2026-01-05 10:00:00', '001', 'VAR-12', '1', '7.20'),
            $this->salesRow('F-2', 'Factura', '', '2026-01-06 10:00:00', '001', 'CEM-50'),
        ];
        $job = $this->importSales($rows);
        $this->assertNotNull($job->bronze_object_id);

        $sales = DB::table('sales')->orderBy('document_number')->get();
        $this->assertSame([1, 3], $sales->pluck('source_row')->map(fn ($v) => (int) $v)->all(), 'source_row = primera fila del documento en el origen');
        foreach ($sales as $sale) {
            $this->assertSame($job->bronze_object_id, (int) $sale->bronze_object_id);
            $this->assertSame('sales@2026.03.1', $sale->schema_version);
        }

        // Mismo archivo otra vez: Bronze es idempotente (mismo SHA-256 → mismo objeto), los documentos quedan sin cambios y su linaje intacto.
        $again = $this->importSales($rows);
        $this->assertSame($job->bronze_object_id, $again->bronze_object_id);
        $this->assertSame(1, DB::table('bronze_objects')->count());
        $this->assertSame(2, $again->stats['documents_unchanged']);
    }

    public function test_archived_revisions_keep_the_lineage_of_their_own_version(): void
    {
        $first = $this->importSales([$this->salesRow('F-1', 'Factura', '', '2026-01-05 10:00:00', '001', 'CEM-50', '2')]);
        $second = $this->importSales([
            $this->salesRow('F-0', 'Factura', '', '2026-01-04 10:00:00', '001', 'CEM-50', '1'),
            $this->salesRow('F-1', 'Factura', '', '2026-01-05 10:00:00', '001', 'CEM-50', '9'),      // cambió la cantidad: nueva versión
        ]);
        $this->assertNotSame($first->bronze_object_id, $second->bronze_object_id);
        $this->assertSame(1, $second->stats['documents_updated']);

        $current = DB::table('sales')->where('document_number', 'F-1')->first();
        $this->assertSame([$second->bronze_object_id, 2, 'sales@2026.03.1'], [(int) $current->bronze_object_id, (int) $current->source_row, $current->schema_version], 'la vigente: la segunda carga, fila 2');
        $revision = DB::table('sales_document_revisions')->where('sale_id', $current->id)->first();
        $this->assertSame([$first->bronze_object_id, 1, 'sales@2026.03.1'], [(int) $revision->bronze_object_id, (int) $revision->source_row, $revision->schema_version], 'la archivada conserva el linaje de SU versión');
        $this->assertSame(0, (int) $revision->revision, 'la primera versión (revisión 0) es la archivada');
        $this->assertNotNull($revision->known_until);
    }

    public function test_inventory_carries_the_same_lineage(): void
    {
        $csv = $this->csv(['fecha', 'sucursal', 'sku', 'existencia'], [['2026-01-05', '001', 'CEM-50', '10'], ['2026-01-05', '001', 'VAR-12', '4']]);
        $job = $this->uploadAndValidate($this->tenant, $csv, 'inventory', [], ['create_missing_products' => true], 'inventario.csv');
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();

        $this->assertNotNull($job->bronze_object_id);
        $rows = DB::table('inventory_snapshots')->orderBy('source_row')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $i => $row) {
            $this->assertSame([$job->bronze_object_id, $i + 1, 'inventory_snapshot@2026.03.1'], [(int) $row->bronze_object_id, (int) $row->source_row, $row->schema_version]);
        }
        $preview = $this->getJson("/api/v1/imports/{$job->id}")->assertOk()->json('data.normalized_preview');
        $this->assertArrayNotHasKey('source_row', $preview[0], 'la vista previa normalizada no cambia');
    }

    public function test_a_batch_is_stored_in_bronze_as_received_and_writes_the_lineage(): void
    {
        $documents = [
            ['document_number' => 'B-1', 'issued_at' => '2026-05-04T10:00:00-05:00', 'branch_code' => '001', 'customer_code' => '1710034065',
                'items' => [['product_sku' => 'A', 'product_name' => 'Producto A', 'quantity' => 2, 'unit_price' => 5]]],
            ['document_number' => 'B-2', 'issued_at' => '2026-05-04T11:00:00-05:00', 'branch_code' => '001',
                'items' => [['product_sku' => 'A', 'product_name' => 'Producto A', 'quantity' => 1, 'unit_price' => 5]]],
        ];
        $response = $this->postJson('/api/v1/sales/batch', ['documents' => $documents])->assertOk()->assertJsonPath('data.created', 2);

        $object = DB::table('bronze_objects')->where('kind', 'api_batch')->first();
        $this->assertNotNull($object);
        $this->assertSame($object->id, $response->json('data.bronze_object_id'));
        $this->assertSame('sales', $object->entity);
        $this->assertSame(2, (int) $object->row_count);
        $this->assertSame($this->owner->id, (int) $object->received_by_user_id, 'procedencia: quién envió el lote');
        $body = $this->withinTenant($this->tenant, fn () => app(BronzeStore::class)->read(BronzeObject::query()->findOrFail($object->id)));
        $decoded = json_decode($body, true);
        $this->assertSame(['B-1', 'B-2'], array_column($decoded['documents'], 'document_number'));
        $this->assertSame('1710034065', $decoded['documents'][0]['customer_code'], 'el original tal cual llegó: minimizarlo rompería el reprocesamiento determinista (source_hash incluye el código de cliente)');
        $this->assertSame($documents, $decoded['documents']);
        $this->assertSame(hash('sha256', $body), $object->sha256);

        $sales = DB::table('sales')->orderBy('document_number')->get();
        $this->assertSame([[$object->id, 1], [$object->id, 2]], $sales->map(fn ($s) => [(int) $s->bronze_object_id, (int) $s->source_row])->all(), 'source_row = posición del documento en el lote');
        $this->assertSame(['sales@2026.03.1', 'sales@2026.03.1'], $sales->pluck('schema_version')->all());

        // El informe del lote queda en data_quality_reports (alcance sales_batch) con el mismo contrato ampliado.
        $report = DB::table('data_quality_reports')->where('scope', 'sales_batch')->first();
        $this->assertSame(['sales', $object->id], [$report->dataset, (int) $report->bronze_object_id]);
        $this->assertNull($report->import_job_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $report->rules_version);
        $this->assertSame(2, $this->decode($report->summary)['created']);
    }

    public function test_a_batch_findings_and_rejections_are_recorded_with_their_classes(): void
    {
        $item = [['product_sku' => 'A', 'quantity' => 1, 'unit_price' => 5]];
        $documents = [
            ['document_number' => 'B-1', 'document_type' => 'cotizacion', 'issued_at' => '2026-05-04', 'branch_code' => '001', 'items' => $item],
            ['document_number' => 'B-2', 'issued_at' => '2026-05-04', 'branch_code' => '001', 'items' => [['product_sku' => ' a ', 'quantity' => '1,5', 'unit_price' => 5]]],
        ];
        $this->postJson('/api/v1/sales/batch', ['documents' => $documents])->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.rejected', 1);

        $report = DB::table('data_quality_reports')->where('scope', 'sales_batch')->first();
        $summary = $this->decode($report->summary);
        $this->assertSame([2, 1, 1], [$summary['rows_total'], $summary['rows_error'], $summary['rows_valid']], 'mismo nombre que el resumen de una importación: los gates suman el % de filas inválidas');
        $counts = $this->decode($report->issue_counts);
        $this->assertSame(1, $counts['REVIEW_REQUIRED']);
        $this->assertGreaterThanOrEqual(1, $counts['AUTO_FIX_SAFE']);
        $issues = $this->findings($report->id);
        $type = $issues->firstWhere('code', 'unknown_document_type');
        $this->assertSame(['REVIEW_REQUIRED', 'unknown|B-1', 1], [$type->dq_class, $type->record_ref, (int) $type->source_row]);
        $this->assertTrue($issues->contains(fn ($i) => $i->code === 'decimal_separator_normalized' && (bool) $i->applied));
    }

    public function test_when_bronze_fails_a_batch_is_not_processed_and_leaves_a_blocker(): void
    {
        $this->app->bind(BronzeStore::class, fn () => $this->failingStore());
        $documents = [['document_number' => 'B-1', 'issued_at' => '2026-05-04', 'branch_code' => '001', 'items' => [['product_sku' => 'A', 'quantity' => 1, 'unit_price' => 5]]]];

        $this->postJson('/api/v1/sales/batch', ['documents' => $documents])->assertStatus(409)->assertJsonPath('code', 'bronze_unavailable');
        $this->assertSame(0, DB::table('sales')->count(), 'sin original no se escribe nada');
        $report = DB::table('data_quality_reports')->where('scope', 'sync')->orWhere('dataset', 'sales')->first();
        $this->assertSame(1, $this->decode($report->issue_counts)['BLOCKER']);
        $this->assertSame('bronze_capture_failed', DB::table('data_quality_issues')->where('report_id', $report->id)->value('code'));
    }

    public function test_when_bronze_fails_an_upload_stops_with_a_blocker_that_cannot_be_confirmed(): void
    {
        $this->app->bind(BronzeStore::class, fn () => $this->failingStore());
        $upload = $this->uploadImport($this->csv(self::SALES_HEADERS, [$this->salesRow('F-1')]))->assertCreated();

        $this->assertSame('failed', $upload->json('data.status'));
        $this->assertStringContainsString('Bronze', $upload->json('data.error_message'));
        $report = DB::table('data_quality_reports')->where('import_job_id', $upload->json('data.id'))->first();
        $this->assertSame(1, $this->decode($report->issue_counts)['BLOCKER']);
        $this->assertSame('bronze_capture_failed', DB::table('data_quality_issues')->where('report_id', $report->id)->value('code'));
        $this->assertSame('BLOCKER', DB::table('data_quality_issues')->where('report_id', $report->id)->value('dq_class'));
        $this->postJson('/api/v1/imports/'.$upload->json('data.id').'/confirm')->assertStatus(409)->assertJsonPath('code', 'invalid_state');
        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_an_empty_file_is_a_blocker_finding(): void
    {
        $upload = $this->uploadImport("\n")->assertCreated();
        $this->assertSame('failed', $upload->json('data.status'));
        $report = DB::table('data_quality_reports')->where('import_job_id', $upload->json('data.id'))->first();
        $this->assertSame(['BLOCKER', 'empty_file'], [DB::table('data_quality_issues')->where('report_id', $report->id)->value('dq_class'), DB::table('data_quality_issues')->where('report_id', $report->id)->value('code')]);
    }

    private function failingStore(): BronzeStore
    {
        return new class implements BronzeStore
        {
            public function store(Tenant $tenant, string $kind, string $entity, string $contents, string $extension, array $provenance = []): BronzeObject
            {
                throw new RuntimeException('almacén no disponible');
            }

            public function storeFile(Tenant $tenant, string $kind, string $entity, string $localPath, string $extension, array $provenance = []): BronzeObject
            {
                throw new RuntimeException('almacén no disponible');
            }

            public function read(BronzeObject $object): string
            {
                throw new RuntimeException('almacén no disponible');
            }

            public function purge(BronzeObject $object, string $reason): void {}
        };
    }
}
