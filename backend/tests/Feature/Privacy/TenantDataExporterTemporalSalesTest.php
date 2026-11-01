<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;
use ZipArchive;

/**
 * Exportación LOPDP y contrato temporal de ventas (ADR-V25-23): la portabilidad incluye las versiones reemplazadas de los documentos,
 * sus líneas, los huecos de cobertura y las columnas de versión de `sales`, y nada de otra empresa.
 */
final class TenantDataExporterTemporalSalesTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /** @return array<string, string> contenido del ZIP por nombre de archivo */
    private function exportFiles(): array
    {
        $exportId = $this->postJson('/api/v1/tenant/export')->assertStatus(202)->json('data.export_id');
        $this->getJson("/api/v1/tenant/export/{$exportId}")->assertOk()->assertJsonPath('data.status', 'completed');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path((string) DB::table('tenant_exports')->where('id', $exportId)->value('path'))) === true);
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[(string) $zip->getNameIndex($i)] = ltrim((string) $zip->getFromIndex($i), "\xEF\xBB\xBF");
        }
        $zip->close();

        return $files;
    }

    /** @return list<array<string, string>> filas del CSV como arreglos asociativos */
    private function rows(string $csv): array
    {
        $lines = array_values(array_filter(preg_split('/\R/', trim($csv)) ?: []));
        $header = str_getcsv((string) array_shift($lines), ',', '"', '');

        return array_map(fn (string $line) => array_combine($header, str_getcsv($line, ',', '"', '')), $lines);
    }

    /** @param  array<string, mixed>  $overrides */
    private function ingest(string $number, float $qty, array $overrides = []): void
    {
        $this->postJson('/api/v1/sales/batch', ['documents' => [array_merge([
            'document_number' => $number, 'issued_at' => '2026-05-04T10:00:00-05:00', 'branch_code' => '001',
            'items' => [['product_sku' => 'A', 'product_name' => 'Producto A', 'quantity' => $qty, 'unit_price' => 5]],
        ], $overrides)]])->assertOk();
    }

    public function test_export_includes_revisions_lines_gaps_and_version_columns_of_the_company_only(): void
    {
        Storage::fake('local');
        [$tenantA, $ownerA] = $this->createTenantWithUser();
        [$tenantB, $ownerB] = $this->createTenantWithUser();
        $this->createBranch($tenantA, '001');
        $this->createBranch($tenantB, '001');

        $this->actingAsTenantUser($ownerB, $tenantB);
        $this->ingest('B-ONLY', 1);
        $this->ingest('B-ONLY', 2);
        $this->postJson('/api/v1/data-coverage/gaps', ['entity' => 'sales', 'gap_from' => '2026-01-01', 'gap_to' => '2026-01-02', 'notes' => 'HUECO-DE-B'])->assertCreated();

        $this->actingAsTenantUser($ownerA, $tenantA);
        $this->ingest('A-1', 2);
        $this->ingest('A-1', 7);
        $this->postJson('/api/v1/data-coverage/gaps', ['entity' => 'sales', 'gap_from' => '2026-03-01', 'gap_to' => '2026-03-03', 'reason' => 'migration', 'notes' => 'HUECO-DE-A'])->assertCreated();
        $files = $this->exportFiles();

        $revisions = $this->rows($files['sales_document_revisions.csv']);
        $this->assertCount(1, $revisions);
        $this->assertSame(['0', 'incremental', 'lines_changed'], [$revisions[0]['revision'], $revisions[0]['ingestion_mode'], $revisions[0]['change_kind']]);
        $this->assertNotSame('', $revisions[0]['known_until']);
        $this->assertCount(1, $this->rows($files['sale_item_revisions.csv']));
        $this->assertSame('2.0000', $this->rows($files['sale_item_revisions.csv'])[0]['quantity']);

        $sales = $this->rows($files['sales.csv']);
        $this->assertCount(1, $sales);
        $this->assertSame(['incremental', '1'], [$sales[0]['ingestion_mode'], $sales[0]['current_revision']]);
        $this->assertNotSame('', $sales[0]['version_known_from']);
        $this->assertNotSame('', $sales[0]['first_seen_at']);

        $gaps = $this->rows($files['data_coverage_gaps.csv']);
        $this->assertCount(1, $gaps);
        $this->assertSame(['sales', '2026-03-01', '2026-03-03', 'migration', 'declared', 'HUECO-DE-A'], [$gaps[0]['entity'], $gaps[0]['gap_from'], $gaps[0]['gap_to'], $gaps[0]['reason'], $gaps[0]['basis'], $gaps[0]['notes']]);

        $everything = implode("\n", $files);
        $this->assertStringNotContainsString('B-ONLY', $everything);
        $this->assertStringNotContainsString('HUECO-DE-B', $everything);
        $this->assertArrayNotHasKey('sales_line_versions.csv', $files, 'la vista no guarda datos propios: se exporta desde sus tablas');
    }
}
