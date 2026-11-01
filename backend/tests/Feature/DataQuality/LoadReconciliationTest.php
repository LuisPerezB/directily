<?php

declare(strict_types=1);

namespace Tests\Feature\DataQuality;

use App\Models\ImportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataCore\Quality\SalesLoadTally;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataQuality\Support\IngestsSales;
use Tests\TestCase;

/**
 * Reconciliación por carga (ADR-V25-24; W2-A): al confirmar cada importación se crea un `reconciliation_reports` (alcance `import`) con las
 * capas fuente (lo leído del origen), Bronze (SHA-256, tamaño, filas) y Silver (lo escrito según las versiones vigentes) y las discrepancias
 * explicadas por categorías conocidas; estado `balanced`, `explained` o `unexplained`.
 */
final class LoadReconciliationTest extends TestCase
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

    /** @return list<string> */
    private function headers(): array
    {
        return [...self::SALES_HEADERS, 'Modificado en'];
    }

    /** Fila con la marca de modificación en la fuente (orden de versiones). @return list<string|int|float> */
    private function row(string $number, string $status = '', string $sku = 'CEM-50', string $qty = '2', string $price = '8.50', string $modified = '2026-01-10 08:00:00', string $type = 'Factura'): array
    {
        return [...$this->salesRow($number, $type, $status, '2026-01-05 10:00:00', '001', $sku, $qty, $price), $modified];
    }

    /** @param list<list<string|int|float>> $rows */
    private function load(array $rows, array $options = [], bool $confirm = true): ImportJob
    {
        $job = $this->uploadAndValidate($this->tenant, $this->csv($this->headers(), $rows), 'sales', [], $options);
        if ($confirm) {
            $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
        }

        return $this->withinTenant($this->tenant, fn () => ImportJob::query()->findOrFail($job->id));
    }

    private function reconciliation(ImportJob $job): object
    {
        return DB::table('reconciliation_reports')->where('import_job_id', $job->id)->latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function layers(object $report): array
    {
        return $this->decode($report->layers);
    }

    /** @return array<string, array<string, mixed>> métrica => descubrimiento */
    private function discrepancies(object $report): array
    {
        return collect($this->decode($report->discrepancies))->keyBy(fn ($d) => $d['from'].'>'.$d['to'].':'.$d['metric'])->all();
    }

    public function test_a_clean_load_is_balanced_with_source_bronze_and_silver_layers(): void
    {
        $job = $this->load([$this->row('F-1'), $this->row('F-1', '', 'VAR-12', '1', '7.20'), $this->row('F-2', '', 'CEM-50', '1')], confirm: false);
        $this->assertSame(0, DB::table('reconciliation_reports')->count(), 'la validación no concilia: no cargó nada');
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();

        $report = $this->reconciliation($job);
        $layers = $this->layers($report);
        $this->assertSame('balanced', $report->status);
        $this->assertSame([], $this->decode($report->discrepancies));
        $this->assertSame(['import', 'sales', null], [$report->scope, $report->entity, $report->source_sync_id]);
        $this->assertSame((int) $job->bronze_object_id, (int) $report->bronze_object_id);
        $this->assertSame(['2026-01-05', '2026-01-05'], [substr((string) $report->window_from, 0, 10), substr((string) $report->window_to, 0, 10)], 'rango de fechas de negocio de la carga');

        $source = $layers['source'];
        $this->assertEquals(['rows' => 3, 'documents' => 2, 'lines' => 3, 'units' => 4.0, 'net_amount' => 32.7, 'voided' => 0, 'credit_notes' => 0, 'duplicates' => 0], array_intersect_key($source, array_flip(['rows', 'documents', 'lines', 'units', 'net_amount', 'voided', 'credit_notes', 'duplicates'])));
        $silver = $layers['silver'];
        $this->assertEquals(['documents' => 2, 'lines' => 3, 'units' => 4.0, 'net_amount' => 32.7, 'new' => 2, 'updated' => 0, 'unchanged' => 0, 'stale_skipped' => 0, 'quarantined' => 0],
            array_intersect_key($silver, array_flip(['documents', 'lines', 'units', 'net_amount', 'new', 'updated', 'unchanged', 'stale_skipped', 'quarantined'])));
        $bronze = $layers['bronze'];
        $this->assertSame([$job->bronze_object_id, 'file_upload', 3], [$bronze['object_id'], $bronze['kind'], $bronze['rows']]);
        $this->assertSame(DB::table('bronze_objects')->where('id', $job->bronze_object_id)->value('sha256'), $bronze['sha256']);
        $this->assertGreaterThan(0, $bronze['size_bytes']);
        $this->assertNull($layers['gold']);

        // Los totales de Silver coinciden con lo realmente escrito.
        $this->assertEquals(4.0, (float) DB::table('sale_items')->sum('quantity'));
        $this->assertEquals(32.7, round((float) DB::table('sale_items')->sum('net_amount'), 2));
    }

    public function test_a_repeated_identical_row_is_explained_as_a_duplicate_in_the_file(): void
    {
        $job = $this->load([$this->row('F-1'), $this->row('F-1'), $this->row('F-2', '', 'VAR-12', '1', '7.20')]);
        $report = $this->reconciliation($job);
        $this->assertSame('explained', $report->status);
        $d = $this->discrepancies($report);
        $this->assertEquals(['duplicates_in_file' => 1], $d['source>silver:lines']['categories']);
        $this->assertEquals(['duplicates_in_file' => 2.0], $d['source>silver:units']['categories']);
        $this->assertEquals(['duplicates_in_file' => 17.0], $d['source>silver:net_amount']['categories']);
        $this->assertTrue($d['source>silver:lines']['explained']);
        $this->assertEquals(0, $d['source>silver:lines']['unexplained']);
        $this->assertArrayNotHasKey('source>silver:documents', $d, 'los documentos sí coinciden');
        $this->assertSame(1, $this->layers($report)['source']['duplicates']);
        $this->assertStringContainsString('filas idénticas repetidas', $d['source>silver:lines']['explanation']);
    }

    public function test_voided_documents_are_written_but_do_not_count_as_sales_so_the_difference_is_explained(): void
    {
        $job = $this->load([$this->row('F-1'), $this->row('F-2', 'Anulada', 'VAR-12', '3', '7.20')]);
        $report = $this->reconciliation($job);
        $layers = $this->layers($report);
        $this->assertSame('explained', $report->status);
        $d = $this->discrepancies($report);
        $this->assertEquals(['voided' => 3.0], $d['source>silver:units']['categories'], 'las unidades de la anulada están en el origen, no en las ventas efectivas');
        $this->assertEquals(['voided' => 21.6], $d['source>silver:net_amount']['categories']);
        $this->assertArrayNotHasKey('source>silver:documents', $d, 'la anulada SÍ está en Silver (trazabilidad)');
        $this->assertSame([1, 2, 1], [$layers['source']['voided'], $layers['silver']['documents'], $layers['silver']['voided']]);
        $this->assertEquals(2.0, $layers['silver']['units']);
        $this->assertEquals(3.0, $layers['silver']['voided_units']);
    }

    public function test_a_stale_version_is_explained_and_not_applied(): void
    {
        $this->load([$this->row('F-1', '', 'CEM-50', '2', '8.50', '2026-01-10 08:00:00')]);
        $job = $this->load([$this->row('F-1', '', 'CEM-50', '9', '8.50', '2026-01-09 08:00:00'), $this->row('F-2', '', 'VAR-12', '1', '7.20')]);
        $report = $this->reconciliation($job);
        $this->assertSame('explained', $report->status);
        $d = $this->discrepancies($report);
        $this->assertEquals(['stale' => 1], $d['source>silver:documents']['categories']);
        $this->assertEquals(['stale' => 9.0], $d['source>silver:units']['categories']);
        $this->assertEquals(1, $this->layers($report)['silver']['stale_skipped']);
        $this->assertEquals(2.0, (float) DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')->where('s.document_number', 'F-1')->value('si.quantity'), 'la versión anterior no se aplicó');
    }

    public function test_quarantined_documents_are_explained(): void
    {
        $job = $this->load([$this->row('F-1'), $this->row('COT-1', '', 'CEM-50', '3', '8.50', '2026-01-10 08:00:00', 'Cotización'), $this->row('COT-1', '', 'VAR-12', '1', '7.20', '2026-01-10 08:00:00', 'Cotización')], ['skip_invalid_rows' => true]);
        $report = $this->reconciliation($job);
        $this->assertSame('explained', $report->status);
        $d = $this->discrepancies($report);
        $this->assertEquals(['quarantine' => 1], $d['source>silver:documents']['categories']);
        $this->assertEquals(['quarantine' => 2], $d['source>silver:lines']['categories']);
        $this->assertEquals(['quarantine' => 4.0], $d['source>silver:units']['categories']);
        $this->assertEquals(32.7, round($d['source>silver:net_amount']['categories']['quarantine'], 2), 'importe neto de las dos líneas de la cotización (3 × 8.50 + 1 × 7.20)');
        $this->assertSame(1, $this->layers($report)['silver']['quarantined']);
        $this->assertSame(1, DB::table('quarantine_records')->count());
    }

    public function test_a_bronze_object_with_other_rows_than_the_ones_read_is_unexplained(): void
    {
        $job = $this->load([$this->row('F-1'), $this->row('F-2')], confirm: false);
        // El objeto Bronze debería contener las filas que se leyeron; si dice otra cosa, la diferencia no tiene explicación.
        $bronze = DB::table('bronze_objects')->where('id', $job->bronze_object_id)->first();
        DB::table('bronze_objects')->where('id', $bronze->id)->update(['metadata' => json_encode(array_merge($this->decode($bronze->metadata), ['rows_read' => 99]))]);
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();

        $report = $this->reconciliation($job);
        $this->assertSame('unexplained', $report->status);
        $d = $this->discrepancies($report);
        $this->assertSame([2, 99, false], [$d['source>bronze:rows']['expected'], $d['source>bronze:rows']['observed'], $d['source>bronze:rows']['explained']]);
    }

    public function test_the_tally_marks_a_difference_with_no_known_category_as_unexplained(): void
    {
        $tally = new SalesLoadTally;
        $tally->sourceDocument('invoice', 'completed');
        $tally->sourceRow(10.0, 100.0);
        // El documento nunca llegó a ninguna parte: ni Silver ni una categoría conocida.
        $built = $tally->build(null, null);
        $this->assertSame('unexplained', $built['status']);
        $units = collect($built['discrepancies'])->firstWhere('metric', 'units');
        $this->assertEquals(10.0, $units['unexplained']);
        $this->assertFalse($units['explained']);
        $this->assertStringContainsString('SIN EXPLICAR', $units['explanation']);

        $balanced = new SalesLoadTally;
        $balanced->sourceDocument('invoice', 'completed');
        $balanced->sourceRow(10.0, 100.0);
        $balanced->documentState('new', 'completed', 'invoice', ['lines' => 1, 'units' => 10.0, 'net' => 100.0]);
        $this->assertSame('balanced', $balanced->build(null, null)['status']);
    }

    public function test_non_sales_entities_reconcile_rows_with_the_rejected_ones_explained(): void
    {
        $csv = $this->csv(['codigo', 'nombre'], [['A', 'Producto A'], ['B', 'Producto B'], ['', 'Sin código']]);
        $job = $this->uploadAndValidate($this->tenant, $csv, 'products', [], ['skip_invalid_rows' => true], 'productos.csv');
        $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
        $report = DB::table('reconciliation_reports')->where('import_job_id', $job->id)->first();
        $this->assertSame(['products', 'explained'], [$report->entity, $report->status]);
        $layers = $this->decode($report->layers);
        $this->assertSame([3, 2], [$layers['source']['rows'], $layers['silver']['rows']]);
        $this->assertSame(['rejected_rows' => 1], $this->decode($report->discrepancies)[0]['categories']);
    }
}
