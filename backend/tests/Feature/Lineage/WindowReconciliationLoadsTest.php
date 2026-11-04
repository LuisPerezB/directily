<?php

declare(strict_types=1);

namespace Tests\Feature\Lineage;

use App\Models\ReconciliationReport;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataCore\Reconciliation\WindowReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataQuality\Support\IngestsSales;
use Tests\TestCase;

/**
 * Reconciliación por ventana de punta a punta con los informes por carga que escribe la captura REAL de ingestión (W2-A): importaciones
 * de archivos CSV por la API, sin preparar a mano ningún informe. Verifica que la capa source existe, se compara con Silver y con los
 * objetos Bronze de las cargas, y que cada diferencia queda explicada por la propia carga (anulados) o sin explicar (Silver alterado).
 */
final class WindowReconciliationLoadsTest extends TestCase
{
    use BuildsImportFiles, IngestsSales, InteractsWithTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeImportInfrastructure();
        config(['bronze.disk' => 'local', 'bronze.retention_days' => 0]);
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001', 'Matriz');
        $this->actingAsTenantUser($this->owner, $this->tenant);
    }

    private function reconcile(): ReconciliationReport
    {
        return app(WindowReconciler::class)->reconcile($this->tenant, 'sales', '2026-01-01', '2026-01-31');
    }

    /** @return list<string> */
    private function unexplained(ReconciliationReport $report): array
    {
        return array_values(array_map(fn ($d) => $d['metric'], array_filter($report->discrepancies, fn ($d) => ! $d['explained'])));
    }

    private function twoLoads(): void
    {
        $this->importSales([
            $this->salesRow('F-1', 'Factura', '', '2026-01-05 10:00:00', '001', 'CEM-50', '2', '8.50'),
            $this->salesRow('F-1', 'Factura', '', '2026-01-05 10:00:00', '001', 'VAR-12', '1', '7.20'),
            $this->salesRow('F-2', 'Factura', '', '2026-01-06 10:00:00', '001', 'CEM-50', '3', '8.50'),
        ], filename: 'ventas_a.csv');
        $this->importSales([
            $this->salesRow('F-3', 'Factura', 'ANULADA', '2026-01-08 10:00:00', '001', 'CEM-50', '1', '8.50'),
            $this->salesRow('F-4', 'Factura', '', '2026-01-09 10:00:00', '001', 'CEM-50', '5', '8.50'),
        ], filename: 'ventas_b.csv');
    }

    public function test_real_loads_feed_the_source_layer_and_the_voided_document_is_explained_by_the_load(): void
    {
        $this->twoLoads();

        $report = $this->reconcile();

        $source = $report->layers['source'];
        $this->assertTrue($source['available'] && $source['comparable'], json_encode($source));
        $this->assertSame([2, 4, 5, 1], [$source['loads'], $source['documents'], $source['lines'], $source['voided']]);
        $bronze = $report->layers['bronze'];
        $this->assertSame(2, $bronze['objects']);
        $this->assertSame(5, $bronze['loads_rows'], 'las filas de los originales de las cargas coinciden con las declaradas por el origen');
        $this->assertNotContains('rows', array_column($report->discrepancies, 'metric'));
        $this->assertSame([], $this->unexplained($report), json_encode($report->discrepancies));
        // El anulado cuenta en lo leído del origen pero no en las ventas efectivas: lo explica el informe de su carga.
        $metrics = array_column($report->discrepancies, 'metric');
        $this->assertContains('units', $metrics);
        $this->assertContains('net_amount', $metrics);
        $this->assertContains('layer_not_compared', $metrics, 'sin ejecución de demanda la capa gold no se comparó');
        $this->assertSame('explained', $report->status);
    }

    public function test_silver_altered_after_real_loads_is_unexplained(): void
    {
        $this->twoLoads();
        DB::table('sale_items')->where('tenant_id', $this->tenant->id)->limit(1)->update(['quantity' => 99, 'net_amount' => 999]);

        $report = $this->reconcile();

        $this->assertSame('unexplained', $report->status);
        $this->assertContains('units', $this->unexplained($report));
        $this->assertContains('net_amount', $this->unexplained($report));
    }

    public function test_the_same_file_loaded_twice_is_counted_once(): void
    {
        $rows = [$this->salesRow('F-1', 'Factura', '', '2026-01-05 10:00:00', '001', 'CEM-50', '2', '8.50')];
        $this->importSales($rows);
        $this->importSales($rows, filename: 'ventas_copia.csv');

        $report = $this->reconcile();

        $source = $report->layers['source'];
        $this->assertTrue($source['comparable']);
        $this->assertSame([1, 1], [$source['loads'], $source['documents']]);
        $this->assertCount(1, $source['duplicate_loads_ignored']);
        $this->assertSame([], $this->unexplained($report));
    }

    public function test_overlapping_daily_exports_are_visibly_not_compared(): void
    {
        $this->importSales([
            $this->salesRow('F-1', 'Factura', '', '2026-01-05 10:00:00'),
            $this->salesRow('F-2', 'Factura', '', '2026-01-06 10:00:00'),
        ], filename: 'a.csv');
        $this->importSales([
            $this->salesRow('F-2', 'Factura', '', '2026-01-06 10:00:00'),
            $this->salesRow('F-3', 'Factura', '', '2026-01-07 10:00:00'),
        ], filename: 'b.csv');

        $report = $this->reconcile();

        $this->assertFalse($report->layers['source']['comparable']);
        $this->assertContains('layer_not_compared', array_column($report->discrepancies, 'metric'));
        $this->assertNotSame('balanced', $report->status);
        $this->assertArrayHasKey('source', (array) $report->toApi()['unavailable_layers']);
    }
}
