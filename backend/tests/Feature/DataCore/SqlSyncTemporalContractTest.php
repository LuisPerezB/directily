<?php

declare(strict_types=1);

namespace Tests\Feature\DataCore;

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataCore\Support\ErpSourceDatabase;
use Tests\Feature\DataCore\Support\SyncsErpSales;
use Tests\Feature\Sales\Support\ReadsSalesVersions;
use Tests\TestCase;

/**
 * Sincronización SQL y contrato temporal de ventas (ADR-V25-23): marca de agua acotada (una fecha de registro futura o ilegible no
 * la adelanta), modo de ingestión de la sincronización (primera = backfill, siguientes = incremental, declarable) y versiones.
 * Usa la base «ERP de origen» ficticia (PostgreSQL), como SqlSourceSyncTest; se omite si no existe.
 */
final class SqlSyncTemporalContractTest extends TestCase
{
    use BuildsImportFiles, InteractsWithTenants, ReadsSalesVersions, RefreshDatabase, SyncsErpSales;

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
                'el contrato temporal de la sincronización SQL de ventas contra un ERP real: modo de ingestión (primera sincronización = backfill, siguientes = incremental, declarable en la solicitud y en el mapeo), '
                .'versiones archivadas por la sincronización, reenvío de una versión anterior del ERP (stale_skipped), marca de agua acotada (fecha de registro futura o ilegible, marca previa envenenada), '
                .'sincronización vacía y estado desconocido en revisión.'
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

    private function sale(string $number): object
    {
        return $this->saleRow($this->tenant, $number);
    }

    public function test_a_future_recorded_at_does_not_advance_the_watermark_and_is_reported(): void
    {
        // Una sola fila del ERP con modificado_en en el futuro (p. ej. un error de captura): antes adelantaba la marca al 2099
        // y todas las incrementales siguientes devolvían 0 filas «con éxito».
        $this->erp->pdo->exec("UPDATE erp_facturas SET modificado_en = '2099-01-01 00:00:00+00' WHERE num_factura = '001-001-000000005'");
        $this->approveErpMapping($this->erpSalesMapping());

        $first = $this->runSync('full');
        $this->assertSame('completed', $first['status'], json_encode($first));
        $this->assertSame(1, $first['stats']['future_recorded_at']);
        $this->assertSame(0, $first['stats']['recorded_at_unreadable']);
        $this->assertStringContainsString('no adelantan la marca de agua', $first['stats']['watermark_note']);

        // marca = min(máx. legible, inicio de la sincronización) = la última modificación legible (la nota de crédito, hace 4 días).
        $watermark = CarbonImmutable::parse($first['watermark']);
        $startedAt = CarbonImmutable::parse($first['started_at']);
        $this->assertTrue($watermark->lessThanOrEqualTo($startedAt), 'la marca nunca supera el inicio de la sincronización');
        $expected = CarbonImmutable::now('America/Guayaquil')->subDays(4)->setTime(12, 0);
        $this->assertSame($expected->getTimestamp(), $watermark->getTimestamp(), "esperada {$expected->toIso8601String()}, obtenida {$first['watermark']} (inicio {$first['started_at']})");
        $this->assertNull($this->sale('001-001-000000005')->source_recorded_at, 'la fecha futura tampoco se guarda en la venta');

        // La incremental siguiente NO queda atascada: toma la corrección hecha en el origen.
        $this->erp->correctInvoiceLine('001-001-000000001', 'CEM-50', 12);
        $second = $this->runSync();
        $this->assertSame('incremental', $second['mode']);
        $this->assertSame('completed', $second['status']);
        $this->assertSame(1, $second['stats']['import']['documents_updated'], 'la corrección se extrajo');
        $this->assertSame(1, $second['stats']['future_recorded_at']);
        $this->assertTrue(CarbonImmutable::parse($second['watermark'])->lessThanOrEqualTo(CarbonImmutable::parse($second['started_at'])));
    }

    public function test_the_sync_and_the_normalizer_share_the_same_recorded_at_bound(): void
    {
        // Una cota ÚNICA (ahora + tolerancia, 5 minutos): antes el normalizador aceptaba hasta el fin de mañana y la preparación de la
        // sincronización acotaba al inicio de la sincronización, así que una marca a +10 minutos se guardaba como orden de versión
        // pero se contaba otra cosa en las estadísticas de la sincronización. Ahora ambos coinciden.
        $this->erp->pdo->exec("UPDATE erp_facturas SET modificado_en = now() + interval '2 minutes' WHERE num_factura = '001-001-000000002'");  // 1 línea, dentro de la tolerancia
        $this->erp->pdo->exec("UPDATE erp_facturas SET modificado_en = now() + interval '10 minutes' WHERE num_factura = '001-001-000000004'"); // 2 líneas, fuera
        $this->approveErpMapping($this->erpSalesMapping());

        $sync = $this->runSync('full');
        $this->assertSame('completed', $sync['status'], json_encode($sync));
        $this->assertSame(2, $sync['stats']['future_recorded_at'], 'preparación: las 2 filas de la factura 4');
        $this->assertSame(2, $sync['stats']['import']['future_recorded_at'], 'importación: las mismas 2 filas');
        $this->assertSame(0, $sync['stats']['recorded_at_unreadable']);
        $this->assertNotNull($this->sale('001-001-000000002')->source_recorded_at, 'a +2 min: dentro de la tolerancia, ordena versiones');
        $this->assertNull($this->sale('001-001-000000004')->source_recorded_at, 'a +10 min: no se guarda como orden de versión');
        $this->assertTrue(CarbonImmutable::parse($sync['watermark'])->lessThanOrEqualTo(CarbonImmutable::parse($sync['started_at'])), 'la marca de agua sigue acotada al inicio de la sincronización');
    }

    public function test_unreadable_recorded_at_is_an_anomaly_and_falls_back_to_the_sync_start(): void
    {
        $mapping = $this->erpSalesMapping();
        $mapping['field_map']['source_recorded_at'] = ['const' => 'ayer por la tarde'];
        $this->approveErpMapping($mapping);

        $sync = $this->runSync('full');
        $this->assertSame('completed', $sync['status'], json_encode($sync));
        $this->assertSame(8, $sync['rows_extracted']);
        $this->assertSame(8, $sync['stats']['recorded_at_unreadable']);
        $this->assertSame(8, $sync['stats']['future_recorded_at'], 'futuras o ilegibles se cuentan como anomalía');
        $this->assertNotNull($sync['watermark']);
        $this->assertTrue(CarbonImmutable::parse($sync['watermark'])->lessThanOrEqualTo(CarbonImmutable::parse($sync['started_at'])));
        $this->assertSame(0, DB::table('sales')->where('tenant_id', $this->tenant->id)->whereNotNull('source_recorded_at')->count());
    }

    public function test_a_poisoned_previous_watermark_is_bounded_when_it_is_read(): void
    {
        $this->approveErpMapping($this->erpSalesMapping());
        $first = $this->runSync('full');
        // Marca guardada por una versión anterior al arreglo: en el futuro.
        DB::table('source_syncs')->where('id', $first['id'])->update(['watermark' => '2099-01-01 00:00:00+00']);
        $this->erp->correctInvoiceLine('001-001-000000001', 'CEM-50', 12);

        $second = $this->runSync();
        $this->assertSame('incremental', $second['mode']);
        $this->assertTrue(CarbonImmutable::parse($second['since'])->lessThanOrEqualTo(CarbonImmutable::parse($second['started_at'])), 'since nunca queda en el futuro');
        $this->assertSame(1, $second['stats']['import']['documents_updated']);
    }

    public function test_first_sync_is_backfill_and_later_syncs_are_incremental_with_archived_versions(): void
    {
        $this->approveErpMapping($this->erpSalesMapping());
        $first = $this->runSync('full');
        $this->assertSame('completed', $first['status'], json_encode($first));
        $this->assertSame('backfill', $first['stats']['ingestion_mode']);
        $this->assertSame('first_sync', $first['stats']['ingestion_mode_basis']);
        $this->assertSame('backfill', $this->sale('001-001-000000001')->ingestion_mode);
        $this->assertNull($this->sale('001-001-000000001')->version_known_from);
        $importOptions = json_decode((string) DB::table('import_jobs')->where('id', $first['import_job_id'])->value('options'), true);
        $this->assertSame('backfill', $importOptions['ingestion_mode'], 'la sincronización fija el modo del import que lanza');

        // Corrección en el origen: la siguiente sincronización es incremental y archiva la versión anterior.
        $this->erp->correctInvoiceLine('001-001-000000001', 'CEM-50', 12);
        $second = $this->runSync();
        $this->assertSame('completed', $second['status'], json_encode($second));
        $this->assertSame('incremental', $second['stats']['ingestion_mode']);
        $this->assertSame('subsequent_sync', $second['stats']['ingestion_mode_basis']);
        $this->assertSame(1, $second['stats']['import']['documents_updated']);
        $this->assertSame(1, $second['stats']['import']['revisions_archived']);

        $sale = $this->sale('001-001-000000001');
        $this->assertSame('incremental', $sale->ingestion_mode);
        $this->assertNotNull($sale->version_known_from);
        $this->assertSame(1, (int) $sale->current_revision);
        $revision = $this->headerRevisions($this->tenant, '001-001-000000001')[0];
        $this->assertSame('backfill', $revision->ingestion_mode);
        $this->assertSame('lines_changed', $revision->change_kind);
        $this->assertSame($second['import_job_id'], (int) $revision->superseded_by_import_job_id);
        $this->assertSame($first['import_job_id'], (int) $revision->import_job_id);
        $this->assertSame($this->utc($sale->version_known_from), $this->utc($revision->known_until));
        $this->assertSame($this->sourceId, (int) $revision->data_source_id);
    }

    public function test_an_older_version_from_the_source_is_stale_skipped_by_the_sync(): void
    {
        $this->approveErpMapping($this->erpSalesMapping());
        $this->runSync('full');
        $this->erp->correctInvoiceLine('001-001-000000001', 'CEM-50', 12);
        $this->runSync();
        $this->assertSame(1, (int) $this->sale('001-001-000000001')->current_revision);

        // El ERP reenvía una versión ANTERIOR (cantidad 10, modificada hace una semana): no debe revertir la corrección.
        $this->erp->pdo->exec("UPDATE erp_factura_lineas SET cantidad = 10, subtotal = 85 WHERE cod_item = 'CEM-50' AND id_factura = (SELECT id_factura FROM erp_facturas WHERE num_factura = '001-001-000000001')");
        $this->erp->pdo->exec("UPDATE erp_facturas SET modificado_en = now() - interval '7 days' WHERE num_factura = '001-001-000000001'");
        $replay = $this->runSync('full');
        $this->assertSame('completed', $replay['status'], json_encode($replay));
        $this->assertSame(1, $replay['stats']['import']['stale_skipped']);
        $this->assertSame(0, $replay['stats']['import']['documents_updated']);
        $this->assertSame(1, (int) $this->sale('001-001-000000001')->current_revision);
        $this->assertEqualsWithDelta(12.0, (float) DB::table('sale_items')->where('sale_id', $this->sale('001-001-000000001')->id)->where('line_number', 1)->value('quantity'), 0.0001);
    }

    public function test_ingestion_mode_can_be_declared_in_the_request_and_overrides_the_mapping(): void
    {
        $this->approveErpMapping($this->erpSalesMapping());
        $declared = $this->runSync('full', ['ingestion_mode' => 'incremental']);
        $this->assertSame('incremental', $declared['stats']['ingestion_mode']);
        $this->assertSame('declared', $declared['stats']['ingestion_mode_basis']);
        $this->assertSame('incremental', $this->sale('001-001-000000001')->ingestion_mode, 'la primera sincronización declarada incremental no es backfill');
        $this->assertNotNull($this->sale('001-001-000000001')->version_known_from);

        $this->assertSame('declared', $this->runSync('full', ['ingestion_mode' => 'backfill'])['stats']['ingestion_mode_basis']);
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/sync", ['entity' => 'sales', 'ingestion_mode' => 'semanal'])->assertUnprocessable()->assertJsonValidationErrors('ingestion_mode');
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/sync", ['entity' => 'products', 'ingestion_mode' => 'backfill'])->assertUnprocessable()->assertJsonValidationErrors('ingestion_mode');
    }

    public function test_ingestion_mode_can_be_declared_in_the_mapping_options(): void
    {
        $this->approveErpMapping($this->erpSalesMapping(['ingestion_mode' => 'incremental']));
        $first = $this->runSync('full');
        $this->assertSame('incremental', $first['stats']['ingestion_mode']);
        $this->assertSame('declared', $first['stats']['ingestion_mode_basis']);
        $this->assertSame('incremental', $this->sale('001-001-000000002')->ingestion_mode);

        // La solicitud manda sobre el mapeo.
        $over = $this->runSync('full', ['ingestion_mode' => 'backfill']);
        $this->assertSame('backfill', $over['stats']['ingestion_mode']);

        $this->postJson("/api/v1/data-sources/{$this->sourceId}/mappings", $this->erpSalesMapping(['ingestion_mode' => 'semanal']))
            ->assertUnprocessable()->assertJsonValidationErrors('options.ingestion_mode');
        $products = ['entity' => 'products', 'query' => 'SELECT cod_item, descripcion FROM erp_productos WHERE :since IS NOT NULL', 'field_map' => ['sku' => 'cod_item', 'name' => 'descripcion'], 'options' => ['ingestion_mode' => 'backfill']];
        $this->postJson("/api/v1/data-sources/{$this->sourceId}/mappings", $products)->assertUnprocessable()->assertJsonValidationErrors('options.ingestion_mode');
    }

    public function test_an_unknown_source_status_needs_review_and_a_value_map_resolves_it(): void
    {
        // El ERP marca un documento como RECHAZADA: no es una venta ni se supone completada.
        $this->erp->pdo->exec("UPDATE erp_facturas SET estado = 'RECHAZADA' WHERE num_factura = '001-001-000000004'");
        $this->approveErpMapping($this->erpSalesMapping());
        $review = $this->runSync('full');
        $this->assertSame('needs_review', $review['status'], json_encode($review));
        $this->assertSame(0, DB::table('sales')->where('tenant_id', $this->tenant->id)->count(), 'nada se importa hasta revisar');
        $errors = collect($this->getJson("/api/v1/imports/{$review['import_job_id']}/errors?severity=error")->assertOk()->json('data'));
        $this->assertSame(['unknown_document_status'], $errors->pluck('error_code')->unique()->values()->all());
        $this->assertSame(['REVIEW_REQUIRED'], $errors->pluck('dq_class')->unique()->values()->all());
        $this->assertSame('RECHAZADA', $errors->first()['raw_value']);

        // Una versión nueva del mapeo traduce el código de la fuente: queda anulada.
        $this->approveErpMapping($this->erpSalesMapping(['value_maps' => ['status' => ['RECHAZADA' => 'ANULADA']]]));
        $ok = $this->runSync('full');
        $this->assertSame('completed', $ok['status'], json_encode($ok));
        $this->assertSame('voided', $this->sale('001-001-000000004')->status);
        $this->assertSame(6, DB::table('sales')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_an_empty_first_sync_does_not_count_as_the_historical_load(): void
    {
        // Mapeo v1 que no trae nada (p. ej. el ETL del origen aún no replicó): completa con 0 filas.
        $this->approveErpMapping($this->erpSalesMapping(extraWhere: "AND f.num_factura LIKE 'ZZZ%'"));
        $empty = $this->runSync('full');
        $this->assertSame('completed', $empty['status']);
        $this->assertSame(0, $empty['rows_extracted']);
        $this->assertSame(0, DB::table('sales')->where('tenant_id', $this->tenant->id)->count());

        $this->approveErpMapping($this->erpSalesMapping());
        $real = $this->runSync('full');
        $this->assertSame('completed', $real['status'], json_encode($real));
        $this->assertSame('backfill', $real['stats']['ingestion_mode'], 'la primera sincronización que trajo datos es la carga histórica');
        $this->assertSame('first_sync', $real['stats']['ingestion_mode_basis']);
        $this->assertSame('backfill', $this->sale('001-001-000000001')->ingestion_mode);
    }
}
