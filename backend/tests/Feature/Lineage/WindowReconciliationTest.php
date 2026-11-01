<?php

declare(strict_types=1);

namespace Tests\Feature\Lineage;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\BronzeObject;
use App\Models\ReconciliationReport;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataCore\Bronze\BronzeStore;
use App\Services\DataCore\Reconciliation\WindowReconciler;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Reconciliación por ventana fuente → Bronze → Silver → Gold (ADR-V25-04 y ADR-V25-24). Los datos de Silver se escriben con
 * POST /sales/batch (la misma escritura versionada de W1); las capas source (informes por carga de W2-A), Bronze y Gold se
 * preparan a mano para controlar cada caso. Cada prueba parte de un estado sin vínculos Bronze ni informes por carga, así no depende
 * de qué rutas de ingestión capturen ya el original.
 *
 * Ventana de las pruebas: 2026-05-04 → 2026-05-06 (empresa en America/Guayaquil).
 *  F-1 (04-may): A 2×5 + B 1×10   · F-2 (05-may): A 4×5   · F-3 (05-may, ANULADA): A 1×5   · NC-1 (06-may, nota de crédito): A 1×5
 *  Silver esperado: 4 documentos, 1 anulado, 1 nota de crédito, 5 líneas; ventas EFECTIVAS (documentos completados): 7 unidades y
 *  35,00 de importe neto firmado (40,00 de ventas − 5,00 de la nota de crédito). Sin el anulado (`standardData(false)`): 3 documentos, 4 líneas.
 */
final class WindowReconciliationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['bronze.disk' => 'local', 'bronze.retention_days' => 0]);
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001');
        $this->actingAsTenantUser($this->owner, $this->tenant);
    }

    // ───────── preparación ─────────

    /** @param list<array<string, mixed>> $documents */
    private function ingest(array $documents, string $mode = 'backfill'): TestResponse
    {
        return $this->postJson('/api/v1/sales/batch', ['documents' => $documents, 'ingestion_mode' => $mode]);
    }

    /** @param list<array{0: string, 1: float, 2: float}> $items */
    private function doc(string $number, string $date, array $items, array $extra = []): array
    {
        return array_merge([
            'document_number' => $number, 'issued_at' => "{$date}T10:00:00-05:00", 'branch_code' => '001',
            'items' => array_map(fn ($i) => ['product_sku' => $i[0], 'product_name' => "Producto {$i[0]}", 'quantity' => $i[1], 'unit_price' => $i[2]], $items),
        ], $extra);
    }

    private function standardData(bool $withVoided = true): void
    {
        $this->ingest(array_values(array_filter([
            $this->doc('F-1', '2026-05-04', [['A', 2, 5], ['B', 1, 10]]),
            $this->doc('F-2', '2026-05-05', [['A', 4, 5]]),
            $withVoided ? $this->doc('F-3', '2026-05-05', [['A', 1, 5]], ['status' => 'voided']) : null,
            $this->doc('NC-1', '2026-05-06', [['A', 1, 5]], ['document_type' => 'credit_note']),
        ])))->assertOk();
        $this->detachBronzeAndLoadReports();
    }

    /** Vincula todos los documentos a un objeto Bronze (linaje completo) para aislar la comparación fuente → Silver. */
    private function completeLineage(): void
    {
        $object = $this->bronze("x\n", 5);
        foreach (DB::table('sales')->where('tenant_id', $this->tenant->id)->pluck('document_number') as $number) {
            $this->linkBronze($number, $object);
        }
    }

    /** Estado neutro: ningún vínculo a Bronze, ningún objeto, ningún informe de reconciliación (de cualquier ruta de ingestión). */
    private function detachBronzeAndLoadReports(): void
    {
        DB::table('sales')->update(['bronze_object_id' => null]);
        DB::table('sales_document_revisions')->update(['bronze_object_id' => null]);
        DB::table('data_quality_reports')->update(['bronze_object_id' => null]);
        DB::table('import_jobs')->update(['bronze_object_id' => null]);
        DB::table('source_syncs')->update(['bronze_object_id' => null]);
        DB::table('reconciliation_reports')->delete();
        DB::table('bronze_objects')->delete();
    }

    private function bronze(string $contents, ?int $rows = null, string $entity = 'sales'): BronzeObject
    {
        return $this->withinTenant($this->tenant, fn () => app(BronzeStore::class)->store($this->tenant, 'file_upload', $entity, $contents, 'csv', ['row_count' => $rows]));
    }

    private function linkBronze(string $number, BronzeObject $object): void
    {
        DB::table('sales')->where('tenant_id', $this->tenant->id)->where('document_number', $number)->update(['bronze_object_id' => $object->id]);
    }

    /** Informe por carga (lo escribe W2-A): fuente declarada + estado + discrepancias. */
    private function loadReport(string $scope, ?string $from, ?string $to, array $source, string $status = 'balanced', array $discrepancies = [], ?int $importJobId = null, ?Tenant $tenant = null, ?int $bronzeObjectId = null): int
    {
        return (int) DB::table('reconciliation_reports')->insertGetId([
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'scope' => $scope, 'entity' => 'sales', 'import_job_id' => $importJobId, 'bronze_object_id' => $bronzeObjectId,
            'window_from' => $from, 'window_to' => $to, 'layers' => json_encode(['source' => $source]),
            'discrepancies' => json_encode($discrepancies), 'status' => $status, 'created_at' => now(),
        ]);
    }

    private function importJob(string $hash): int
    {
        return (int) DB::table('import_jobs')->insertGetId([
            'tenant_id' => $this->tenant->id, 'entity_type' => 'sales', 'original_filename' => 'v.csv', 'stored_path' => 'x', 'file_hash' => $hash,
            'file_size' => 1, 'file_format' => 'csv', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Cargas que, sumadas, coinciden exactamente con Silver de `standardData(false)` (cifras como las declara W2-A: importe neto firmado). */
    private function matchingLoads(): void
    {
        $this->loadReport('import', '2026-05-04', '2026-05-05', ['rows' => 3, 'documents' => 2, 'lines' => 3, 'units' => 7, 'net_amount' => 40, 'voided' => 0, 'credit_notes' => 0]);
        $this->loadReport('sync', '2026-05-06', '2026-05-06', ['rows' => 1, 'documents' => 1, 'lines' => 1, 'units' => 0, 'net_amount' => -5, 'voided' => 0, 'credit_notes' => 1]);
    }

    /** Ejecución de demanda cuyas celdas coinciden con Silver (3 celdas de ventas válidas): la capa gold SÍ se compara y no hay diferencia. */
    private function matchingGold(): void
    {
        $this->goldRun(['sales' => 3], '2026-05-06', '2026-05-06 20:00:00');
    }

    private function reconcile(string $from = '2026-05-04', string $to = '2026-05-06'): ReconciliationReport
    {
        return app(WindowReconciler::class)->reconcile($this->tenant, 'sales', $from, $to);
    }

    /** @return array<string, mixed> */
    private function discrepancy(ReconciliationReport $report, string $metric): array
    {
        $found = array_values(array_filter($report->discrepancies, fn ($d) => $d['metric'] === $metric));
        $this->assertCount(1, $found, "discrepancia {$metric}");

        return $found[0];
    }

    // ───────── Silver y capa source no disponible ─────────

    public function test_silver_counts_the_current_versions_and_an_absent_source_layer_is_reported_without_inventing_figures(): void
    {
        $this->standardData();

        $report = $this->reconcile();

        $silver = $report->layers['silver'];
        $this->assertSame([4, 1, 1, 5], [$silver['documents'], $silver['voided'], $silver['credit_notes'], $silver['lines']]);
        $this->assertEqualsWithDelta(7.0, $silver['units'], 0.0001, 'unidades de ventas efectivas: sin el anulado ni la nota de crédito');
        $this->assertEqualsWithDelta(35.0, $silver['net_amount'], 0.0001, '40,00 de ventas − 5,00 de la nota de crédito; el anulado no cuenta');
        $this->assertEqualsWithDelta(40.0, $silver['sales_net_amount'], 0.0001);
        $this->assertEqualsWithDelta(5.0, $silver['returns_net_amount'], 0.0001);

        $source = $report->layers['source'];
        $this->assertFalse($source['available']);
        $this->assertStringContainsString('No hay informes de reconciliación por carga', $source['reason']);
        $this->assertStringContainsString('No se inventan cifras', $source['reason']);
        $this->assertEqualsCanonicalizing(['available', 'compared', 'reason', 'report_ids'], array_keys($source), 'la capa no trae ni una sola cifra');
        $this->assertFalse($report->layers['bronze']['available']);
        $this->assertFalse($report->layers['gold']['available']);
        $this->assertStringContainsString('ejecución de demanda', $report->layers['gold']['reason']);

        $this->assertSame('window', $report->scope);
        $this->assertSame('sales', $report->entity);
        $this->assertSame(['2026-05-04', '2026-05-06'], [$report->window_from->toDateString(), $report->window_to->toDateString()]);
        // Con las capas faltantes solo se compara lo disponible: falta el original de los documentos (anterior a Bronze) y se registran, visibles,
        // las dos capas que NO se compararon (nunca `balanced` sin comparar).
        $this->assertSame(['documents_without_bronze_before_capture', 'layer_not_compared', 'layer_not_compared'], array_column($report->discrepancies, 'metric'));
        $this->assertSame('explained', $report->status);
        $this->assertFalse($report->layers['source']['compared']);
        $this->assertFalse($report->layers['gold']['compared']);
        $this->assertTrue($report->layers['silver']['compared']);
        $this->assertStringContainsString('antes de la captura Bronze', $this->discrepancy($report, 'documents_without_bronze_before_capture')['explanation']);
    }

    public function test_silver_uses_only_the_current_version_of_a_corrected_document_and_only_the_window(): void
    {
        $this->travelTo('2026-05-10 12:00:00');
        $this->standardData();
        $this->travelTo('2026-05-12 12:00:00');
        // Corrección posterior de F-2 (A: 4 → 6 unidades): la versión anterior se archiva y NO cuenta dos veces.
        $this->ingest([$this->doc('F-2', '2026-05-05', [['A', 6, 5]], ['source_recorded_at' => '2026-05-12T05:00:00-05:00'])], 'incremental')->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertSame(1, DB::table('sales_document_revisions')->count());
        // Fuera de la ventana y de otra empresa: no cuentan.
        $this->ingest([$this->doc('F-9', '2026-05-09', [['A', 100, 5]])])->assertOk();
        [$other, $otherUser] = $this->createTenantWithUser();
        $this->createBranch($other, '001');
        $this->actingAsTenantUser($otherUser, $other);
        $this->ingest([$this->doc('F-1', '2026-05-04', [['A', 1000, 5]])])->assertOk();
        $this->detachBronzeAndLoadReports();

        $silver = $this->reconcile()->layers['silver'];

        $this->assertSame(4, $silver['documents']);
        $this->assertSame(5, $silver['lines'], 'la revisión archivada no suma líneas');
        $this->assertEqualsWithDelta(9.0, $silver['units'], 0.0001, '2+1 de F-1 + 6 de F-2 corregido');
        $this->assertEqualsWithDelta(45.0, $silver['net_amount'], 0.0001, '20 + 30 − 5 de la nota de crédito');
    }

    // ───────── capa source ─────────

    public function test_declared_source_totals_that_match_silver_give_a_balanced_report_when_lineage_is_complete(): void
    {
        $this->standardData(false);
        $this->completeLineage();
        $this->matchingLoads();
        $this->matchingGold();

        $report = $this->reconcile();

        $source = $report->layers['source'];
        $this->assertTrue($source['available']);
        $this->assertTrue($source['comparable']);
        $this->assertSame([2, 3, 4, 0, 1], [$source['loads'], $source['documents'], $source['lines'], $source['voided'], $source['credit_notes']]);
        $this->assertSame(7, $source['units']);
        $this->assertEqualsWithDelta(35.0, $source['net_amount'], 0.0001);
        $this->assertSame([], $report->discrepancies);
        $this->assertSame('balanced', $report->status, 'se compararon fuente → Silver, el linaje y Silver → Gold, y coinciden');
        $this->assertSame(['source', 'bronze', 'silver', 'gold'], $report->toApi()['compared_layers']);
        $this->assertTrue($report->layers['bronze']['available']);
        $this->assertSame(1, $report->layers['bronze']['objects']);
        $this->assertSame(5, $report->layers['bronze']['rows']);
    }

    public function test_a_difference_between_source_and_silver_that_no_load_explains_is_unexplained(): void
    {
        $this->standardData(false);
        $this->completeLineage();
        $this->matchingGold();
        // Una carga declara un documento y 5,50 de importe que Silver no tiene, sin que ningún informe de carga lo explique.
        $this->loadReport('import', '2026-05-04', '2026-05-05', ['rows' => 3, 'documents' => 3, 'lines' => 3, 'units' => 7, 'net_amount' => 45.5, 'voided' => 0, 'credit_notes' => 0]);
        $this->loadReport('sync', '2026-05-06', '2026-05-06', ['documents' => 1, 'lines' => 1, 'units' => 0, 'net_amount' => -5, 'voided' => 0, 'credit_notes' => 1]);

        $report = $this->reconcile();

        $this->assertSame('unexplained', $report->status);
        $documents = $this->discrepancy($report, 'documents');
        $this->assertEquals([4, 3, false], [$documents['expected'], $documents['observed'], $documents['explained']]);
        $this->assertSame('source', $documents['from']);
        $this->assertSame('silver', $documents['to']);
        $amount = $this->discrepancy($report, 'net_amount');
        $this->assertEqualsWithDelta(5.5, $amount['expected'] - $amount['observed'], 0.0001);
        $this->assertSame(['documents', 'net_amount'], array_column($report->discrepancies, 'metric'), 'solo las métricas que difieren');
    }

    public function test_a_voided_document_is_explained_by_the_load_report_in_the_w2a_format(): void
    {
        $this->standardData();   // F-3 anulado: su fila cuenta en la fuente (8 unidades, 45,00) pero no en las ventas efectivas de Silver (7, 40,00)
        $this->completeLineage();
        $this->matchingGold();
        $voided = fn (string $metric, float $expected, float $observed) => [
            'from' => 'source', 'to' => 'silver', 'metric' => $metric, 'expected' => $expected, 'observed' => $observed, 'difference' => $expected - $observed,
            'categories' => ['voided' => $expected - $observed], 'unexplained' => 0, 'explained' => true, 'explanation' => 'documentos anulados: están en Silver pero no cuentan como venta',
        ];
        $this->loadReport('import', '2026-05-04', '2026-05-05', ['rows' => 4, 'documents' => 3, 'lines' => 4, 'units' => 8, 'net_amount' => 45, 'voided' => 1, 'credit_notes' => 0], 'explained', [$voided('units', 8, 7), $voided('net_amount', 45, 40)]);
        $this->loadReport('sync', '2026-05-06', '2026-05-06', ['rows' => 1, 'documents' => 1, 'lines' => 1, 'units' => 0, 'net_amount' => -5, 'voided' => 0, 'credit_notes' => 1]);

        $report = $this->reconcile();

        $this->assertSame(['units', 'net_amount'], array_column($report->discrepancies, 'metric'), 'documentos, líneas, anulados y notas de crédito coinciden');
        foreach ($report->discrepancies as $d) {
            $this->assertTrue($d['explained'], $d['metric']);
            $this->assertStringContainsString('anulados', $d['explanation']);
        }
        $this->assertSame('explained', $report->status);
        $this->assertSame(1, $report->layers['source']['voided']);
        $this->assertSame(1, $report->layers['silver']['voided']);
    }

    public function test_partially_explained_differences_report_how_much_remains_unexplained(): void
    {
        $this->standardData(false);
        $this->completeLineage();
        // La carga declaró 3 documentos (Silver 2: uno en cuarentena, explicado) y 5 líneas (Silver 3: dos de menos, la carga explica solo una).
        $documents = ['from' => 'source', 'to' => 'silver', 'metric' => 'documents', 'expected' => 3, 'observed' => 2, 'difference' => 1, 'categories' => ['quarantine' => 1], 'unexplained' => 0, 'explained' => true, 'explanation' => 'un documento en cuarentena'];
        $lines = ['from' => 'source', 'to' => 'silver', 'metric' => 'lines', 'expected' => 5, 'observed' => 3, 'difference' => 2, 'categories' => ['rejected_rows' => 1], 'unexplained' => 1, 'explained' => false, 'explanation' => 'una fila rechazada; 1 SIN EXPLICAR'];
        $this->loadReport('import', '2026-05-04', '2026-05-05', ['rows' => 5, 'documents' => 3, 'lines' => 5, 'units' => 7, 'net_amount' => 40, 'voided' => 0, 'credit_notes' => 0], 'unexplained', [$documents, $lines]);
        $this->loadReport('sync', '2026-05-06', '2026-05-06', ['documents' => 1, 'lines' => 1, 'units' => 0, 'net_amount' => -5, 'voided' => 0, 'credit_notes' => 1]);

        $report = $this->reconcile();

        $docs = $this->discrepancy($report, 'documents');
        $this->assertTrue($docs['explained'], 'una entrada explicada de un informe «unexplained» sí cuenta');
        $this->assertStringContainsString('explicada por los informes de las cargas', $docs['explanation']);
        $lineDiff = $this->discrepancy($report, 'lines');
        $this->assertFalse($lineDiff['explained'], 'la carga explicó 1 línea pero faltan 2');
        $this->assertStringContainsString('quedan 1 sin explicar', $lineDiff['explanation']);
        $this->assertSame('unexplained', $report->status);
    }

    public function test_a_load_report_marked_unexplained_never_counts_as_explanation(): void
    {
        $this->standardData(false);
        $this->completeLineage();
        // Formato anterior (sin banderas por entrada): el informe está `unexplained`, así que su texto no explica nada.
        $d = ['from' => 'source', 'to' => 'silver', 'metric' => 'documents', 'expected' => 3, 'observed' => 2, 'explanation' => 'texto'];
        $this->loadReport('import', '2026-05-04', '2026-05-05', ['documents' => 3, 'lines' => 3, 'units' => 7, 'net_amount' => 40, 'voided' => 0, 'credit_notes' => 0], 'unexplained', [$d]);
        $this->loadReport('sync', '2026-05-06', '2026-05-06', ['documents' => 1, 'lines' => 1, 'units' => 0, 'net_amount' => -5, 'voided' => 0, 'credit_notes' => 1]);

        $report = $this->reconcile();

        $this->assertFalse($this->discrepancy($report, 'documents')['explained']);
        $this->assertSame('unexplained', $report->status);
        // Con el formato anterior y el informe `explained` sí se acepta.
        DB::table('reconciliation_reports')->where('scope', 'import')->update(['status' => 'explained']);
        $this->assertTrue($this->discrepancy($this->reconcile(), 'documents')['explained']);
    }

    public function test_loads_crossing_the_window_or_overlapping_are_reported_but_not_compared_and_never_balanced(): void
    {
        $this->standardData(false);
        $this->completeLineage();
        $this->matchingGold();
        // Una carga cruza el límite inicial de la ventana: no se puede prorratear.
        $partial = $this->loadReport('import', '2026-05-01', '2026-05-05', ['documents' => 3, 'lines' => 3, 'units' => 1, 'net_amount' => 1]);
        $this->loadReport('sync', '2026-05-06', '2026-05-06', ['documents' => 1, 'lines' => 1]);
        $report = $this->reconcile();
        $this->assertTrue($report->layers['source']['available']);
        $this->assertFalse($report->layers['source']['comparable']);
        $this->assertFalse($report->layers['source']['compared']);
        $this->assertStringContainsString("{$partial}", $report->layers['source']['reason']);
        $this->assertStringContainsString('no se pueden prorratear', $report->layers['source']['reason']);
        // Sin comparación no hay cifras inventadas, pero tampoco un `balanced` silencioso: queda una discrepancia visible.
        $this->assertSame(['layer_not_compared'], array_column($report->discrepancies, 'metric'));
        $this->assertSame('source', $report->discrepancies[0]['from']);
        $this->assertStringContainsString('Capa source NO comparada', $report->discrepancies[0]['explanation']);
        $this->assertStringContainsString('no se pueden prorratear', $report->discrepancies[0]['explanation']);
        $this->assertSame('explained', $report->status);
        $api = $report->toApi();
        $this->assertArrayHasKey('source', (array) $api['unavailable_layers'], 'disponible pero no comparable también se informa con su razón');
        $this->assertSame(['bronze', 'silver', 'gold'], $api['compared_layers']);
        $this->artisan('predictily:reconcile', ['tenant_uuid' => $this->tenant->uuid, '--from' => '2026-05-04', '--to' => '2026-05-06'])->expectsOutputToContain('NO COMPARADA')->assertExitCode(0);

        // Cargas que se solapan (exportaciones diarias con días en común): los totales declarados no son aditivos.
        DB::table('reconciliation_reports')->delete();
        $this->loadReport('import', '2026-05-04', '2026-05-05', ['documents' => 3, 'lines' => 4]);
        $this->loadReport('import', '2026-05-05', '2026-05-06', ['documents' => 2, 'lines' => 2]);
        $overlapping = $this->reconcile();
        $this->assertFalse($overlapping->layers['source']['comparable']);
        $this->assertStringContainsString('se solapan', $overlapping->layers['source']['reason']);
        $this->assertSame(['layer_not_compared'], array_column($overlapping->discrepancies, 'metric'));
        $this->assertNotSame('balanced', $overlapping->status);

        // La ventana que corta una carga (06-may en adelante, con la carga del 04 al 06) tampoco se compara.
        DB::table('reconciliation_reports')->delete();
        $this->loadReport('import', '2026-05-04', '2026-05-06', ['documents' => 3, 'lines' => 4, 'units' => 7, 'net_amount' => 35]);
        $cut = $this->reconcile('2026-05-05', '2026-05-06');
        $this->assertFalse($cut->layers['source']['compared']);
        $this->assertStringContainsString('cruzan el límite', $cut->layers['source']['reason']);
        $this->assertSame(['layer_not_compared'], array_column($cut->discrepancies, 'metric'));
        $this->assertNotSame('balanced', $cut->status);
    }

    public function test_the_same_file_loaded_twice_counts_once_and_a_tampered_silver_is_unexplained(): void
    {
        $this->standardData(false);
        $object = $this->bronze("x\n", 4);
        foreach (['F-1', 'F-2', 'NC-1'] as $number) {
            $this->linkBronze($number, $object);
        }
        $this->matchingGold();
        // El MISMO archivo (mismo objeto Bronze) cargado dos veces: dos informes por carga con rangos idénticos y cifras idénticas.
        $declared = ['rows' => 4, 'documents' => 3, 'lines' => 4, 'units' => 7, 'net_amount' => 35, 'voided' => 0, 'credit_notes' => 1];
        $first = $this->loadReport('import', '2026-05-04', '2026-05-06', $declared, 'balanced', [], $this->importJob(str_repeat('b', 64)), null, $object->id);
        $second = $this->loadReport('import', '2026-05-04', '2026-05-06', $declared, 'balanced', [], $this->importJob(str_repeat('c', 64)), null, $object->id);

        $report = $this->reconcile();

        $this->assertTrue($report->layers['source']['comparable'], 'la recarga del mismo archivo no vuelve «solapadas» las cargas');
        $this->assertSame(1, $report->layers['source']['loads']);
        $this->assertSame(3, $report->layers['source']['documents'], 'no se cuenta dos veces');
        $this->assertSame([$second], $report->layers['source']['report_ids'], 'cuenta la más reciente');
        $this->assertSame([$first], $report->layers['source']['duplicate_loads_ignored'] ?? [], 'la anterior se informa como duplicada');
        $this->assertSame('balanced', $report->status);

        // Silver alterado fuera de las cargas: debe aparecer SIN EXPLICAR (con doble conteo de la fuente se habría escondido o falseado).
        DB::table('sale_items')->where('tenant_id', $this->tenant->id)->update(['quantity' => 500, 'net_amount' => 4250]);
        $tampered = $this->reconcile();
        $this->assertSame('unexplained', $tampered->status);
        $units = $this->discrepancy($tampered, 'units');
        $this->assertFalse($units['explained']);
        $this->assertSame('source', $units['from']);
    }

    public function test_source_rows_are_compared_with_the_bronze_objects_of_the_loads_and_partial_sums_are_never_shown(): void
    {
        $this->standardData(false);
        $this->matchingGold();
        $one = $this->bronze("a\n", 3);
        $two = $this->bronze("b\n", 1);
        foreach (['F-1', 'F-2'] as $number) {
            $this->linkBronze($number, $one);
        }
        $this->linkBronze('NC-1', $two);
        $this->loadReport('import', '2026-05-04', '2026-05-05', ['rows' => 3, 'documents' => 2, 'lines' => 3, 'units' => 7, 'net_amount' => 40, 'voided' => 0, 'credit_notes' => 0], 'balanced', [], null, null, $one->id);
        $this->loadReport('sync', '2026-05-06', '2026-05-06', ['rows' => 1, 'documents' => 1, 'lines' => 1, 'units' => 0, 'net_amount' => -5, 'voided' => 0, 'credit_notes' => 1], 'balanced', [], null, null, $two->id);

        $matching = $this->reconcile();
        $this->assertSame(4, $matching->layers['bronze']['loads_rows']);
        $this->assertSame(4, $matching->layers['bronze']['rows']);
        $this->assertSame('balanced', $matching->status);

        // El objeto Bronze de una carga tiene menos filas que las que declaró el origen.
        DB::table('bronze_objects')->where('id', $one->id)->update(['row_count' => 2]);
        $short = $this->reconcile();
        $rows = $this->discrepancy($short, 'rows');
        $this->assertEquals([4, 3, false], [$rows['expected'], $rows['observed'], $rows['explained']]);
        $this->assertSame(['source', 'bronze'], [$rows['from'], $rows['to']]);
        $this->assertSame('unexplained', $short->status);

        // Un objeto sin `row_count`: la suma sería parcial, así que no hay cifra (null) ni comparación.
        DB::table('bronze_objects')->where('id', $one->id)->update(['row_count' => null]);
        $partial = $this->reconcile();
        $this->assertNull($partial->layers['bronze']['rows']);
        $this->assertNull($partial->layers['bronze']['loads_rows']);
        $this->assertSame(1, $partial->layers['bronze']['objects_without_row_count']);
        $this->assertNotContains('rows', array_column($partial->discrepancies, 'metric'));
        $this->assertSame('balanced', $partial->status);
    }

    public function test_a_report_is_never_balanced_when_a_layer_was_not_compared(): void
    {
        $this->standardData(false);
        $this->completeLineage();
        $this->matchingLoads();

        // Fuente y linaje coinciden, pero no hay ejecución que cubra la ventana: Gold no se comparó.
        $report = $this->reconcile();
        $this->assertSame(['layer_not_compared'], array_column($report->discrepancies, 'metric'));
        $this->assertSame(['silver', 'gold'], [$report->discrepancies[0]['from'], $report->discrepancies[0]['to']]);
        $this->assertStringContainsString('Capa gold NO comparada', $report->discrepancies[0]['explanation']);
        $this->assertSame('explained', $report->status);
        $this->assertSame(['source', 'bronze', 'silver'], $report->toApi()['compared_layers']);

        // Con Gold comparada y sin diferencias, ahora sí.
        $this->matchingGold();
        $this->assertSame('balanced', $this->reconcile()->status);
    }

    public function test_only_the_latest_report_of_each_load_counts_and_unusable_reports_are_ignored(): void
    {
        $this->standardData(false);
        $this->completeLineage();
        $this->matchingGold();
        $job = $this->importJob(str_repeat('a', 64));
        $this->loadReport('import', '2026-05-04', '2026-05-05', ['documents' => 99, 'lines' => 99, 'units' => 99, 'net_amount' => 99, 'voided' => 99, 'credit_notes' => 99], 'unexplained', [], $job);   // obsoleto
        $this->loadReport('import', '2026-05-04', '2026-05-05', ['documents' => 2, 'lines' => 3, 'units' => 7, 'net_amount' => 40, 'voided' => 0, 'credit_notes' => 0], 'balanced', [], $job);   // vigente
        $this->loadReport('sync', '2026-05-06', '2026-05-06', ['documents' => 1, 'lines' => 1, 'units' => 0, 'net_amount' => -5, 'voided' => 0, 'credit_notes' => 1]);
        $this->loadReport('import', '2026-04-01', '2026-04-30', ['documents' => 500]);                       // fuera de la ventana
        $this->loadReport('import', null, null, ['documents' => 700]);                                          // sin rango de fechas
        $this->loadReport('sync', '2026-05-05', '2026-05-05', ['note' => 'sin totales']);                       // sin totales declarados
        [$other] = $this->createTenantWithUser();
        $this->loadReport('import', '2026-05-04', '2026-05-06', ['documents' => 9999], 'balanced', [], null, $other);   // otra empresa

        $report = $this->reconcile();

        $this->assertSame(2, $report->layers['source']['loads']);
        $this->assertSame(3, $report->layers['source']['documents']);
        $this->assertSame('balanced', $report->status);
        $this->assertCount(1, $report->layers['source']['reports_without_declared_totals']);
    }

    // ───────── Bronze y linaje ─────────

    public function test_bronze_layer_counts_the_objects_referenced_by_the_window_and_flags_lineage_gaps(): void
    {
        $this->standardData();
        $one = $this->bronze("a\n", 3);
        $two = $this->bronze("b\n", 1);
        $unrelated = $this->bronze("c\n", 100);    // sin documentos en la ventana
        $this->linkBronze('F-1', $one);
        $this->linkBronze('F-2', $one);
        $this->linkBronze('NC-1', $two);
        // F-3 sin original: uno anterior a la captura (explicado) y otro posterior (sin explicar)
        DB::table('sales')->where('document_number', 'F-3')->update(['first_seen_at' => '2020-01-01 00:00:00']);
        $this->withinTenant($this->tenant, fn () => app(BronzeStore::class)->purge($two, 'retention'));

        $report = $this->reconcile();

        $bronze = $report->layers['bronze'];
        $this->assertTrue($bronze['available']);
        $this->assertSame([2, 4, 1], [$bronze['objects'], $bronze['rows'], $bronze['purged']]);
        $this->assertSame($one->size_bytes + $two->size_bytes, $bronze['bytes']);
        $this->assertNotContains($unrelated->id, $bronze['object_ids']);
        $this->assertSame(1, $report->layers['silver']['documents_without_bronze']);
        $this->assertSame('explained', $report->status, 'el único documento sin original es anterior a la captura');

        // Un documento ingerido después de empezar la captura y sin objeto: ruta sin captura → sin explicar.
        DB::table('sales')->where('document_number', 'F-3')->update(['first_seen_at' => now()->addDay()]);
        $unexplained = $this->reconcile();
        $this->assertSame('unexplained', $unexplained->status);
        $gap = $this->discrepancy($unexplained, 'documents_without_bronze_after_capture');
        $this->assertEquals([0, 1, false], [$gap['expected'], $gap['observed'], $gap['explained']]);
    }

    // ───────── Gold ─────────

    /** Ejecución de demanda completada con su huella de datos. */
    private function goldRun(array $rowCounts, string $asOf, string $createdAt, string $status = 'completed', ?int $version = null, ?string $dataTo = null): int
    {
        $snapshot = DB::table('data_snapshots')->insertGetId([
            'tenant_id' => $this->tenant->id, 'core' => 'demand', 'as_of' => $asOf, 'hash' => hash('sha256', uniqid('', true)), 'components' => '{}',
            'row_counts' => json_encode($rowCounts), 'coverage' => '{}', 'created_at' => $createdAt,
        ]);
        DB::table('forecast_runs')->insert([
            'tenant_id' => $this->tenant->id, 'version' => $version ?? ((int) DB::table('forecast_runs')->max('version') + 1), 'status' => $status, 'horizon_days' => 30,
            'cutoff_date' => $asOf, 'data_from' => '2026-05-04', 'data_to' => $dataTo ?? $asOf, 'data_snapshot_id' => $snapshot, 'options' => '{}', 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        return $snapshot;
    }

    public function test_gold_cells_are_compared_with_silver_at_the_cutoff_of_the_covering_run(): void
    {
        $this->standardData();   // celdas válidas: (A, 04-may), (B, 04-may), (A, 05-may) = 3

        $this->assertFalse($this->reconcile()->layers['gold']['available']);

        $this->goldRun(['sales' => 3, 'returns' => 1], '2026-05-06', '2026-05-06 20:00:00');
        $balanced = $this->reconcile();
        $this->assertTrue($balanced->layers['gold']['available']);
        $this->assertSame([3, 3, 3], [$balanced->layers['gold']['rows'], $balanced->layers['gold']['silver_cells_known_at_cutoff'], $balanced->layers['gold']['silver_cells_known_at_run']]);
        $this->assertNotContains('sales_cells', array_column($balanced->discrepancies, 'metric'));

        // La última ejecución que cubre la ventana manda; las fallidas y las que no llegan al fin de la ventana se ignoran.
        $this->goldRun(['sales' => 99], '2026-05-07', '2026-05-07 20:00:00', 'failed');
        $this->goldRun(['sales' => 77], '2026-05-05', '2026-05-07 21:00:00', 'completed', null, '2026-05-05');
        $wrong = $this->goldRun(['sales' => 4], '2026-05-06', '2026-05-07 22:00:00');
        $report = $this->reconcile();
        $this->assertSame($wrong, $report->layers['gold']['data_snapshot_id']);
        $sales = $this->discrepancy($report, 'sales_cells');
        $this->assertEquals([3, 4, false], [$sales['expected'], $sales['observed'], $sales['explained']]);
        $this->assertSame('silver', $sales['from']);
        $this->assertSame('gold', $sales['to']);
        $this->assertSame('unexplained', $report->status);
    }

    public function test_a_live_run_that_knew_late_documents_is_explained_not_a_discrepancy_of_silver(): void
    {
        $this->travelTo('2026-05-07 12:00:00');
        $this->standardData();
        $this->travelTo('2026-05-08 12:00:00');
        // Llega tarde un documento del 5-may con un producto nuevo: no se conocía al corte (6-may) pero sí el día de la ejecución (9-may).
        $this->ingest([$this->doc('F-4', '2026-05-05', [['C', 1, 5]])], 'incremental')->assertOk();
        $this->detachBronzeAndLoadReports();
        $this->goldRun(['sales' => 4], '2026-05-06', '2026-05-09 15:00:00');

        $report = $this->reconcile();

        $gold = $report->layers['gold'];
        $this->assertSame([4, 3, 4], [$gold['rows'], $gold['silver_cells_known_at_cutoff'], $gold['silver_cells_known_at_run']]);
        $sales = $this->discrepancy($report, 'sales_cells');
        $this->assertTrue($sales['explained']);
        $this->assertStringContainsString('Ejecución vigente', $sales['explanation']);
    }

    public function test_gold_without_sales_row_counts_is_unavailable_with_the_reason(): void
    {
        $this->standardData();
        $this->goldRun(['returns' => 3], '2026-05-06', '2026-05-06 20:00:00');

        $gold = $this->reconcile()->layers['gold'];

        $this->assertFalse($gold['available']);
        $this->assertStringContainsString('row_counts', $gold['reason']);
    }

    // ───────── entradas, comando, API, aislamiento ─────────

    public function test_it_validates_entity_dates_and_window_length(): void
    {
        $reconciler = app(WindowReconciler::class);
        foreach ([
            ['inventory', '2026-05-01', '2026-05-02', 'DISEÑADAS'],
            ['sales', '2026-5-1', '2026-05-02', 'AAAA-MM-DD'],
            ['sales', '2026-02-30', '2026-03-02', 'no es válida'],
            ['sales', '2026-05-10', '2026-05-09', 'anterior'],
            ['sales', '2025-01-01', '2026-05-09', '366 días'],
        ] as [$entity, $from, $to, $message]) {
            try {
                $reconciler->reconcile($this->tenant, $entity, $from, $to);
                $this->fail("Debió rechazar {$entity} {$from} {$to}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->assertSame(0, DB::table('reconciliation_reports')->count());
        $this->assertNotSame('unexplained', $reconciler->reconcile($this->tenant, 'sales', '2025-05-06', '2026-05-06')->status, '366 días exactos es válido');
    }

    public function test_the_command_writes_a_window_report_and_signals_unexplained_differences(): void
    {
        $this->standardData();
        $base = ['tenant_uuid' => $this->tenant->uuid, '--entity' => 'sales', '--from' => '2026-05-04', '--to' => '2026-05-06'];

        $this->artisan('predictily:reconcile', $base)->expectsOutputToContain('estado explained')->expectsOutputToContain('NO DISPONIBLE')->assertExitCode(0);
        $this->assertSame(1, DB::table('reconciliation_reports')->where('scope', 'window')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'reconciliation.window')->count());

        $this->loadReport('import', '2026-05-04', '2026-05-06', ['documents' => 10, 'lines' => 5, 'units' => 7, 'net_amount' => 40, 'voided' => 1, 'credit_notes' => 1]);
        $this->artisan('predictily:reconcile', $base)->expectsOutputToContain('SIN EXPLICAR')->assertExitCode(2);

        $this->artisan('predictily:reconcile', ['tenant_uuid' => '00000000-0000-0000-0000-000000000000', '--from' => '2026-05-04', '--to' => '2026-05-06'])->assertExitCode(1);
        $this->artisan('predictily:reconcile', ['tenant_uuid' => $this->tenant->uuid, '--from' => '2026-05-04'])->assertExitCode(1);
        $this->artisan('predictily:reconcile', ['tenant_uuid' => $this->tenant->uuid, '--entity' => 'inventory', '--from' => '2026-05-04', '--to' => '2026-05-06'])->assertExitCode(1);
    }

    public function test_the_api_lists_and_shows_reports_of_the_active_company_only(): void
    {
        $this->standardData();
        $mine = $this->reconcile();
        $loadId = $this->loadReport('import', '2026-05-04', '2026-05-05', ['documents' => 1]);
        [$other, $otherUser] = $this->createTenantWithUser();
        $foreign = $this->loadReport('import', '2026-05-04', '2026-05-05', ['documents' => 1], 'balanced', [], null, $other);

        $viewer = $this->addUserToTenant($this->tenant, TenantRole::Viewer);
        $this->actingAsTenantUser($viewer, $this->tenant);
        $list = $this->getJson('/api/v1/lineage/reconciliation')->assertOk();
        $ids = array_column($list->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$mine->id, $loadId], $ids);
        $this->assertNotContains($foreign, $ids);
        $this->assertSame([$mine->id], array_column($this->getJson('/api/v1/lineage/reconciliation?scope=window')->json('data'), 'id'));
        $this->assertSame([$loadId], array_column($this->getJson('/api/v1/lineage/reconciliation?scope=import')->json('data'), 'id'));
        $this->assertSame([], $this->getJson('/api/v1/lineage/reconciliation?status=balanced&scope=window')->json('data'));
        $this->getJson('/api/v1/lineage/reconciliation?scope=otro')->assertUnprocessable();

        $detail = $this->getJson("/api/v1/lineage/reconciliation/{$mine->id}")->assertOk()->json('data');
        $this->assertSame('window', $detail['scope']);
        $this->assertSame('explained', $detail['status']);
        $this->assertSame(4, $detail['layers']['silver']['documents']);
        $this->assertEqualsCanonicalizing(['source', 'bronze', 'gold'], array_keys($detail['unavailable_layers']), 'las capas sin datos se informan con su razón');
        $this->assertStringContainsString('No se inventan cifras', $detail['unavailable_layers']['source']);
        $this->assertSame('2026-05-04', $detail['window_from']);

        $this->getJson("/api/v1/lineage/reconciliation/{$foreign}")->assertNotFound();
        $this->actingAsTenantUser($otherUser, $other);
        $this->getJson("/api/v1/lineage/reconciliation/{$mine->id}")->assertNotFound();
        $this->assertSame([$foreign], array_column($this->getJson('/api/v1/lineage/reconciliation')->json('data'), 'id'));
    }

    public function test_the_api_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withoutHeader('X-Tenant-ID')->getJson('/api/v1/lineage/reconciliation')->assertUnauthorized();
    }

    public function test_reconciliation_reports_are_append_only_history(): void
    {
        $this->standardData();
        $first = $this->reconcile();
        $second = $this->reconcile();

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, DB::table('reconciliation_reports')->where('scope', 'window')->count());
        $this->assertNull(ReconciliationReport::UPDATED_AT);
        $tenantContext = app(TenantContext::class);
        $this->assertNull($tenantContext->get(), 'el conciliador no deja la empresa en contexto');
    }
}
