<?php

declare(strict_types=1);

namespace Tests\Feature\DataQuality;

use App\Models\BronzeObject;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataCore\Bronze\BronzeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataCore\Support\ErpSourceDatabase;
use Tests\Feature\DataCore\Support\SyncsErpSales;
use Tests\Feature\DataQuality\Support\IngestsSales;
use Tests\Feature\Sales\Support\ReadsSalesVersions;
use Tests\TestCase;

/**
 * Calidad de ingestión en la sincronización SQL (ADR-V25-04, ADR-V25-05 y ADR-V25-24; W2-A): la extracción CRUDA va a Bronze (NDJSON comprimido,
 * `source_syncs.bronze_object_id`) antes de normalizar, las ventas llevan su linaje, el informe de la importación queda enlazado a la sincronización
 * con sus transformaciones y su deriva de esquema, la reconciliación de la sincronización cuenta origen → Bronze → Silver, un mapeo roto es BLOCKER
 * y un estado desconocido va a cuarentena y se libera con un mapeo de valores nuevo. Usa la base «ERP de origen» ficticia; se omite si no existe.
 */
final class SqlIngestionQualityTest extends TestCase
{
    use BuildsImportFiles, IngestsSales, InteractsWithTenants, ReadsSalesVersions, RefreshDatabase, SyncsErpSales;

    private ErpSourceDatabase $erp;

    private Tenant $tenant;

    private User $owner;

    private int $sourceId;

    protected function setUp(): void
    {
        parent::setUp();
        $erp = ErpSourceDatabase::tryConnect();
        if ($erp === null) {
            $this->markTestSkipped(ErpSourceDatabase::skipMessage(
                'la calidad de ingestión de la sincronización SQL: extracción cruda en Bronze, linaje de las ventas, informe de calidad y reconciliación de la sincronización, '
                .'deriva de esquema, mapeo roto como BLOCKER, cuarentena y reprocesamiento de la extracción.'
            ));
        }
        $this->erp = $erp;
        $this->erp->createSchema();
        $this->erp->seed();
        $this->fakeImportInfrastructure();
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001', 'Matriz');
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $this->sourceId = $this->createErpSource();
    }

    protected function tearDown(): void
    {
        if (isset($this->erp)) {
            $this->erp->dropSchema();
        }
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> filas de la extracción cruda guardada en el objeto Bronze */
    private function rawRows(int $bronzeId): array
    {
        $contents = $this->withinTenant($this->tenant, fn () => app(BronzeStore::class)->read(BronzeObject::query()->findOrFail($bronzeId)));

        return array_map(fn (string $line) => json_decode($line, true), array_values(array_filter(explode("\n", (string) gzdecode($contents)))));
    }

    public function test_the_raw_extraction_goes_to_bronze_and_the_sales_carry_their_lineage(): void
    {
        $this->approveErpMapping($this->erpSalesMapping());
        $sync = $this->runSync('full');
        $this->assertSame('completed', $sync['status'], json_encode($sync));
        $this->assertSame(8, $sync['rows_extracted']);

        $object = DB::table('bronze_objects')->where('id', $sync['bronze_object_id'])->first();
        $this->assertNotNull($object, 'source_syncs.bronze_object_id');
        $this->assertSame(['sql_extract', 'sales', 8, $this->sourceId], [$object->kind, $object->entity, (int) $object->row_count, (int) $object->data_source_id]);
        $this->assertStringEndsWith('.ndjson.gz', $object->object_key);
        $raw = $this->rawRows($object->id);
        $this->assertCount(8, $raw, 'una línea por fila de origen');
        // La fila CRUDA tal como la devolvió la consulta, antes de convertir: el estado conserva el código del ERP y el decimal su escala.
        $this->assertSame('001-001-000000001', $raw[0]['num_factura']);
        $this->assertSame(['FACTURA', 'EMITIDA'], [$raw[0]['tipo'], $raw[0]['estado']]);
        $this->assertSame('10.0000', $raw[0]['cantidad']);
        $this->assertArrayHasKey('cliente', $raw[0]);
        $this->assertArrayNotHasKey('nombre', $raw[0], 'solo las columnas que usa el mapeo aprobado');

        // Linaje: cada venta apunta al objeto y a la primera línea de su documento en la extracción (orden id_factura, línea).
        $sales = DB::table('sales')->where('tenant_id', $this->tenant->id)->orderBy('document_number')->get();
        $this->assertSame([1, 3, 4, 5, 7, 8], $sales->map(fn ($s) => (int) $s->source_row)->all());
        foreach ($sales as $s) {
            $this->assertSame([$object->id, 'sales@2026.03.1'], [(int) $s->bronze_object_id, $s->schema_version]);
            $this->assertSame($s->document_number, $raw[(int) $s->source_row - 1]['num_factura'], 'source_row apunta a la línea del original');
        }
        $this->assertSame($object->id, (int) DB::table('import_jobs')->where('id', $sync['import_job_id'])->value('bronze_object_id'), 'el original de la importación es la extracción cruda, no el CSV de preparación');
        $this->assertSame(1, DB::table('bronze_objects')->count(), 'el CSV de preparación no se guarda como original');
    }

    public function test_the_import_report_is_linked_to_the_sync_with_transformations_and_the_sync_reconciles_all_layers(): void
    {
        $this->approveErpMapping($this->erpSalesMapping(['value_maps' => ['status' => ['EMITIDA' => 'Emitida']]]));
        $sync = $this->runSync('full');
        $this->assertSame('completed', $sync['status'], json_encode($sync));

        $report = DB::table('data_quality_reports')->where('import_job_id', $sync['import_job_id'])->first();
        $this->assertSame(['sales', $sync['id'], $sync['bronze_object_id']], [$report->dataset, (int) $report->source_sync_id, (int) $report->bronze_object_id]);
        $metrics = $this->decode($report->metrics);
        $applied = collect($metrics['transformations']['value_maps']['status'])->firstWhere('from', 'EMITIDA');
        $this->assertEquals(['from' => 'EMITIDA', 'to' => 'Emitida', 'count' => 7], $applied, 'los 7 renglones EMITIDA (el ERP trae 8 y uno ANULADA)');
        $this->assertArrayHasKey('transformations', $sync['stats']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $report->rules_version);

        // Reconciliación de la sincronización: origen (lo leído) → Bronze (la extracción) → Silver (lo escrito).
        $reconciliation = DB::table('reconciliation_reports')->where('source_sync_id', $sync['id'])->first();
        $this->assertSame(['sync', 'sales', (int) $sync['import_job_id'], (int) $sync['bronze_object_id']], [$reconciliation->scope, $reconciliation->entity, (int) $reconciliation->import_job_id, (int) $reconciliation->bronze_object_id]);
        $this->assertSame('explained', $reconciliation->status, 'la anulada (y la nota de crédito) explican la diferencia en ventas efectivas');
        $layers = $this->decode($reconciliation->layers);
        $this->assertSame([8, 6, 8, 1, 1], [$layers['source']['rows'], $layers['source']['documents'], $layers['source']['lines'], $layers['source']['voided'], $layers['source']['credit_notes']]);
        $this->assertSame(8, $layers['source']['rows_extracted']);
        $this->assertSame([$sync['bronze_object_id'], 'sql_extract', 8], [$layers['bronze']['object_id'], $layers['bronze']['kind'], $layers['bronze']['rows']]);
        $this->assertSame([6, 8, 6], [$layers['silver']['documents'], $layers['silver']['lines'], $layers['silver']['new']]);
        $this->assertSame(0, DB::table('reconciliation_reports')->where('scope', 'import')->where('import_job_id', $sync['import_job_id'])->count(), 'un solo informe de reconciliación por sincronización');
        $categories = collect($this->decode($reconciliation->discrepancies))->firstWhere('metric', 'net_amount')['categories'];
        $this->assertArrayHasKey('voided', $categories);
    }

    public function test_a_sync_that_returns_no_rows_reconciles_in_zero_without_a_bronze_object(): void
    {
        $this->approveErpMapping($this->erpSalesMapping(extraWhere: "AND f.num_factura LIKE 'ZZZ%'"));
        $empty = $this->runSync('full');
        $this->assertSame('completed', $empty['status']);
        $this->assertNull($empty['bronze_object_id']);
        $report = DB::table('reconciliation_reports')->where('source_sync_id', $empty['id'])->first();
        $this->assertSame(['sync', 'balanced'], [$report->scope, $report->status]);
        $this->assertSame(0, DB::table('bronze_objects')->count());
    }

    public function test_schema_drift_is_detected_from_the_columns_the_query_returns(): void
    {
        $this->approveErpMapping($this->erpSalesMapping());
        $first = $this->runSync('full');
        $firstObject = DB::table('bronze_objects')->where('id', $first['bronze_object_id'])->first();
        $this->assertSame(1, (int) $firstObject->schema_version);
        $this->assertNull($first['stats']['schema']['drift']);
        $source = DB::table('data_sources')->where('id', $this->sourceId)->first();
        $this->assertSame(1, (int) $source->schema_version);
        $this->assertNotNull($source->schema_fingerprint);

        // El ERP agrega una columna a la consulta (y cambian datos): la huella cambia; el informe lo registra sin valores.
        $mapping = $this->erpSalesMapping();
        $mapping['query'] = str_replace('f.canal, f.modificado_en', 'f.canal, f.modificado_en, f.id_cliente AS id_cliente_interno', $mapping['query']);
        $this->approveErpMapping($mapping);
        $this->erp->correctInvoiceLine('001-001-000000001', 'CEM-50', 12);
        $second = $this->runSync('full');
        $this->assertSame('completed', $second['status'], json_encode($second));
        $this->assertSame(['id_cliente_interno'], $second['stats']['schema']['drift']['added']);
        $this->assertSame(2, $second['stats']['schema']['version']);
        $this->assertNotSame($firstObject->schema_fingerprint, $second['stats']['schema']['fingerprint']);
        $this->assertSame(2, (int) DB::table('bronze_objects')->where('id', $second['bronze_object_id'])->value('schema_version'));
        $this->assertSame(2, (int) DB::table('data_sources')->where('id', $this->sourceId)->value('schema_version'));

        $report = DB::table('data_quality_reports')->where('import_job_id', $second['import_job_id'])->first();
        $drift = DB::table('data_quality_issues')->where('report_id', $report->id)->where('code', 'schema_drift')->first();
        $this->assertSame('WARNING', $drift->dq_class);
        $this->assertStringContainsString('id_cliente_interno', $drift->reason);
    }

    public function test_a_mapping_that_references_a_missing_column_is_a_blocker_and_nothing_is_loaded(): void
    {
        $mapping = $this->erpSalesMapping();
        $mapping['field_map']['quantity'] = 'cantidad_que_ya_no_existe';
        $this->approveErpMapping($mapping);
        $sync = $this->runSync('full');

        $this->assertSame('failed', $sync['status']);
        $this->assertStringContainsString('cantidad_que_ya_no_existe', $sync['error_message']);
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(0, DB::table('bronze_objects')->count(), 'no hubo extracción que conservar');
        $report = DB::table('data_quality_reports')->where('source_sync_id', $sync['id'])->first();
        $this->assertSame(['sync', 1], [$report->scope, $this->decode($report->issue_counts)['BLOCKER']]);
        $this->assertSame('source_columns_missing', DB::table('data_quality_issues')->where('report_id', $report->id)->value('code'));
        $this->assertStringContainsString('cantidad_que_ya_no_existe', DB::table('data_quality_issues')->where('report_id', $report->id)->value('reason'));
    }

    public function test_when_bronze_fails_the_sync_stops_before_writing_anything(): void
    {
        $this->app->bind(BronzeStore::class, fn () => new class implements BronzeStore
        {
            public function store(Tenant $tenant, string $kind, string $entity, string $contents, string $extension, array $provenance = []): BronzeObject
            {
                throw new \RuntimeException('almacén no disponible');
            }

            public function storeFile(Tenant $tenant, string $kind, string $entity, string $localPath, string $extension, array $provenance = []): BronzeObject
            {
                throw new \RuntimeException('almacén no disponible');
            }

            public function read(BronzeObject $object): string
            {
                throw new \RuntimeException('almacén no disponible');
            }

            public function purge(BronzeObject $object, string $reason): void {}
        });
        $this->approveErpMapping($this->erpSalesMapping());
        $sync = $this->runSync('full');

        $this->assertSame('failed', $sync['status']);
        $this->assertStringContainsString('Bronze', $sync['error_message']);
        $this->assertSame(0, DB::table('sales')->count(), 'sin original no se escribe nada en Silver');
        $report = DB::table('data_quality_reports')->where('source_sync_id', $sync['id'])->first();
        $this->assertSame('bronze_capture_failed', DB::table('data_quality_issues')->where('report_id', $report->id)->value('code'));
    }

    public function test_an_unknown_status_goes_to_quarantine_with_skip_invalid_rows_and_is_released_after_a_value_map(): void
    {
        $this->erp->pdo->exec("UPDATE erp_facturas SET estado = 'RECHAZADA' WHERE num_factura = '001-001-000000004'");
        $this->approveErpMapping($this->erpSalesMapping(['import' => ['skip_invalid_rows' => true]]));
        $sync = $this->runSync('full');

        $this->assertSame('completed', $sync['status'], json_encode($sync));
        $this->assertSame(5, DB::table('sales')->where('tenant_id', $this->tenant->id)->count(), 'lo válido se carga');
        $this->assertFalse(DB::table('sales')->where('document_number', '001-001-000000004')->exists());
        $record = DB::table('quarantine_records')->first();
        $this->assertSame(['invoice|001-001-000000004', $sync['id'], $sync['import_job_id'], $sync['bronze_object_id']], [$record->record_key, (int) $record->source_sync_id, (int) $record->import_job_id, (int) $record->bronze_object_id]);
        $this->assertSame(['unknown_document_status'], $this->decode($record->issue_codes));
        $payload = $this->decode($record->payload);
        $this->assertCount(2, $payload['rows'], 'las dos líneas de la factura 4');
        $this->assertSame([5, 6], array_column($payload['rows'], 'source_row'), 'líneas 5 y 6 de la extracción');
        $this->assertSame('RECHAZADA', $payload['rows'][0]['fields']['status']);

        // Sin el mapeo de valores: sigue en revisión. El mapeo vigente es el de la versión APROBADA del mapeo SQL.
        $this->postJson("/api/v1/data-quality/quarantine/{$record->id}/release")->assertUnprocessable()->assertJsonPath('findings.0.code', 'unknown_document_status');
        $this->approveErpMapping($this->erpSalesMapping(['import' => ['skip_invalid_rows' => true], 'value_maps' => ['status' => ['RECHAZADA' => 'ANULADA']]]));
        $this->postJson("/api/v1/data-quality/quarantine/{$record->id}/release")->assertOk()->assertJsonPath('data.status', 'released')->assertJsonPath('data.write.state', 'new');

        $sale = $this->saleRow($this->tenant, '001-001-000000004');
        $this->assertSame(['voided', 'incremental', $sync['bronze_object_id'], 5], [$sale->status, $sale->ingestion_mode, (int) $sale->bronze_object_id, (int) $sale->source_row]);
        $this->assertSame($this->sourceId, (int) $sale->data_source_id);
    }

    public function test_reprocessing_an_old_extraction_without_source_order_is_refused_when_it_would_revert_newer_data_unless_forced(): void
    {
        // Mapeo SIN `source_recorded_at`: no hay orden de fuente, así que la última ingestión gana.
        $mapping = $this->erpSalesMapping();
        unset($mapping['field_map']['source_recorded_at']);
        $this->approveErpMapping($mapping);
        $this->travelTo(now()->addDay());
        $first = $this->runSync('full');
        $this->assertSame('completed', $first['status'], json_encode($first));
        $this->erp->correctInvoiceLine('001-001-000000001', 'CEM-50', 12);
        $this->travelTo(now()->addDay());
        $second = $this->runSync('full');
        $this->assertSame(1, $second['stats']['import']['documents_updated']);
        $quantity = fn () => (float) DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')->where('s.document_number', '001-001-000000001')->where('i.line_number', 1)->value('i.quantity');
        $this->assertSame(12.0, $quantity());

        $this->travelTo(now()->addDay());
        $code = Artisan::call('predictily:bronze-reprocess', ['id' => $first['bronze_object_id']]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--force', Artisan::output());
        $this->assertSame(12.0, $quantity(), 'la extracción vieja no revierte la versión nueva');
        $this->assertSame($first['import_job_id'], (int) DB::table('source_syncs')->where('id', $first['id'])->value('import_job_id'));

        $this->assertSame(0, Artisan::call('predictily:bronze-reprocess', ['id' => $first['bronze_object_id'], '--force' => true, '--json' => true]));
        $this->assertSame(1, json_decode(trim(Artisan::output()), true)['overwrote_newer_documents']);
        $this->assertSame(10.0, $quantity());
    }

    public function test_reprocessing_the_raw_extraction_is_idempotent(): void
    {
        $this->approveErpMapping($this->erpSalesMapping());
        $sync = $this->runSync('full');
        $before = DB::table('sales')->where('tenant_id', $this->tenant->id)->orderBy('document_number')->get(['document_number', 'source_hash', 'current_revision'])->all();

        foreach ([1, 2] as $_) {
            $code = Artisan::call('predictily:bronze-reprocess', ['id' => $sync['bronze_object_id'], '--json' => true]);
            $result = json_decode(trim(Artisan::output()), true);
            $this->assertSame(0, $code);
            $this->assertSame(['new' => 0, 'updated' => 0, 'unchanged' => 6, 'stale_skipped' => 0, 'rejected' => 0], $result['documents'], 'la extracción cruda reproduce exactamente los mismos documentos');
        }
        $this->assertEquals($before, DB::table('sales')->where('tenant_id', $this->tenant->id)->orderBy('document_number')->get(['document_number', 'source_hash', 'current_revision'])->all());
        $this->assertSame(0, DB::table('sales_document_revisions')->count());
        $this->assertSame($sync['import_job_id'], (int) DB::table('source_syncs')->where('id', $sync['id'])->value('import_job_id'), 'el reprocesamiento no reasigna la importación de la sincronización original');
    }
}
