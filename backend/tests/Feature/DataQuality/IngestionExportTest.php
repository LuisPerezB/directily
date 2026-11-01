<?php

declare(strict_types=1);

namespace Tests\Feature\DataQuality;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\DataQuality\Support\IngestsSales;
use Tests\TestCase;
use ZipArchive;

/**
 * La exportación LOPDP (portabilidad) incluye lo que W2-A produce —informes ampliados, hallazgos, cuarentena, reconciliación, objetos Bronze y
 * linaje— solo de la empresa, sin la clave interna del almacén y sin identificaciones de clientes (consumidor del informe de calidad).
 */
final class IngestionExportTest extends TestCase
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

    /** @return array<string, string> */
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

    /** @return list<array<string, string>> */
    private function rows(string $csv): array
    {
        $lines = array_values(array_filter(preg_split('/\R/', trim($csv)) ?: []));
        $header = str_getcsv((string) array_shift($lines), ',', '"', '');

        return array_map(fn (string $line) => array_combine($header, str_getcsv($line, ',', '"', '')), $lines);
    }

    public function test_the_export_carries_the_ingestion_quality_tables_of_the_company_only(): void
    {
        [$other, $otherOwner] = $this->createTenantWithUser();
        $this->createBranch($other, '001');
        $this->importSales([$this->salesRow('F-1', 'Factura', '', '2026-01-05 10:00:00', '001', 'CEM-50', '2', '8.50', '1710034065'), $this->salesRow('COT-1', 'Cotización', '', '2026-01-06 10:00:00', '001', 'CEM-50', '1', '8.50', '1710034065')], ['skip_invalid_rows' => true]);
        $this->actingAsTenantUser($otherOwner, $other);
        $this->importSales([$this->salesRow('OTRA-1', 'Cotización'), $this->salesRow('OTRA-2')], ['skip_invalid_rows' => true], tenant: $other);
        $this->actingAsTenantUser($this->owner, $this->tenant);

        $files = $this->exportFiles();
        foreach (['data_quality_reports', 'data_quality_issues', 'quarantine_records', 'reconciliation_reports', 'bronze_objects'] as $table) {
            $this->assertArrayHasKey("{$table}.csv", $files, $table);
        }
        $this->assertCount(1, $this->rows($files['quarantine_records.csv']));
        $this->assertSame('unknown|COT-1', $this->rows($files['quarantine_records.csv'])[0]['record_key']);
        $this->assertStringNotContainsString('OTRA-1', implode("\n", $files), 'nada de otra empresa');
        $this->assertCount(1, $this->rows($files['reconciliation_reports.csv']));
        $this->assertCount(1, $this->rows($files['bronze_objects.csv']));
        $this->assertStringNotContainsString('object_key', explode("\n", $files['bronze_objects.csv'])[0], 'la clave interna del almacén no se exporta');
        $report = $this->rows($files['data_quality_reports.csv'])[0];
        $this->assertSame('sales', $report['dataset']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $report['rules_version']);
        $this->assertNotEmpty($this->rows($files['data_quality_issues.csv']));
        $sale = $this->rows($files['sales.csv'])[0];
        $this->assertSame('sales@2026.03.1', $sale['schema_version']);
        $this->assertSame('1', $sale['source_row']);
        $this->assertStringNotContainsString('1710034065', implode("\n", $files), 'las identificaciones de clientes no salen en claro en ninguna tabla exportada');
    }
}
