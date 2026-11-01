<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsImportFiles;
use Tests\Concerns\InteractsWithTenants;
use Tests\Support\ZipFixtures;
use Tests\TestCase;

/**
 * Endurecimiento de la subida de XLSX por la API (V2.5 §15, ADR-V25-15; defecto HIGH «ZIP bomb» de seguridad_ui): el directorio
 * ZIP se inspecciona en la validación de la petición, antes de guardar el archivo y de analizarlo. Antes, un XLSX de 680 KB
 * consumía 23 s de CPU de un worker de php-fpm solo para leer los encabezados.
 */
final class XlsxUploadHardeningTest extends TestCase
{
    use BuildsImportFiles, InteractsWithTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeImportInfrastructure();
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001', 'Matriz');
        $this->actingAsTenantUser($this->owner, $this->tenant);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** @param array<array-key, mixed> $entries */
    private function upload(array $entries, string $filename = 'ventas.xlsx'): TestResponse
    {
        $path = $this->files[] = sys_get_temp_dir().'/subida-'.bin2hex(random_bytes(4)).'.xlsx';
        ZipFixtures::write($path, $entries);

        return $this->post('/api/v1/imports', [
            'file' => new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'entity_type' => 'sales',
        ], ['Accept' => 'application/json']);
    }

    private function assertNothingWasStored(): void
    {
        $this->assertSame(0, DB::table('import_jobs')->where('tenant_id', $this->tenant->id)->count(), 'no se creó la importación');
        $this->assertSame([], Storage::disk('local')->allFiles(), 'no se guardó el archivo');
    }

    public function test_the_audit_bomb_is_rejected_with_413_a_stable_code_and_in_under_two_seconds(): void
    {
        $started = microtime(true);
        $response = $this->upload(ZipFixtures::workbook(null, ['xl/sharedStrings.xml' => ['repeat' => 'a', 'bytes' => 200_000_183]]));
        $elapsed = microtime(true) - $started;

        $response->assertStatus(413)
            ->assertJsonPath('code', 'xlsx_uncompressed_too_large')
            ->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'supera el máximo permitido'));
        $this->assertStringContainsString('cadenas de texto compartidas', $response->json('message'));
        $this->assertLessThan(2.0, $elapsed, "rechazada en {$elapsed} s (antes: 23 s de CPU dentro de la petición)");
        $this->assertNothingWasStored();
    }

    public function test_an_extreme_compression_ratio_is_rejected_with_413(): void
    {
        $this->upload(ZipFixtures::workbook(null, ['xl/worksheets/sheet1.xml' => ['repeat' => '<c/>', 'bytes' => 64 * 1_048_576]]))
            ->assertStatus(413)->assertJsonPath('code', 'xlsx_compression_ratio_exceeded');
        $this->assertNothingWasStored();
    }

    public function test_too_many_entries_and_nested_archives_are_rejected_with_422(): void
    {
        $entries = ZipFixtures::workbook();
        for ($i = 0; $i < 1100; $i++) {
            $entries["xl/extra/{$i}.xml"] = '<a/>';
        }
        $this->upload($entries)->assertStatus(422)->assertJsonPath('code', 'xlsx_too_many_entries')
            ->assertJsonPath('context.max_entries', 1000);

        $this->upload(ZipFixtures::workbook(null, ['xl/embeddings/oculto.zip' => 'PK']))
            ->assertStatus(422)->assertJsonPath('code', 'xlsx_nested_archive');
        $this->assertNothingWasStored();
    }

    public function test_a_file_named_xlsx_that_is_not_a_workbook_is_rejected_with_422(): void
    {
        $path = $this->files[] = sys_get_temp_dir().'/falso-'.bin2hex(random_bytes(4)).'.xlsx';
        file_put_contents($path, "id,nombre\n1,esto es un CSV con extensión xlsx\n");
        $this->post('/api/v1/imports', [
            'file' => new UploadedFile($path, 'ventas.xlsx', null, null, true),
            'entity_type' => 'sales',
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'xlsx_invalid_archive');
        $this->assertNothingWasStored();
    }

    public function test_limits_are_configurable_per_environment(): void
    {
        $workbook = ZipFixtures::workbook(null, ['xl/worksheets/sheet1.xml' => ['random' => 2_000_000]]);
        $this->upload($workbook)->assertStatus(201);   // con los límites por defecto cabe de sobra

        config(['upload_safety.xlsx.max_total_bytes' => 1_000_000]);
        $this->upload($workbook)->assertStatus(413)->assertJsonPath('code', 'xlsx_uncompressed_too_large');
    }

    public function test_a_legitimate_workbook_and_csv_uploads_are_unaffected(): void
    {
        $this->upload(ZipFixtures::workbook())->assertCreated()->assertJsonPath('data.status', 'awaiting_mapping')
            ->assertJsonPath('data.detected_columns', ['Nro Factura', 'Cantidad']);
        $this->uploadImport($this->csv(self::SALES_HEADERS, $this->validSalesRows()))->assertCreated()->assertJsonPath('data.status', 'awaiting_mapping');
    }

    public function test_reader_limits_fail_the_analysis_with_a_spanish_message_instead_of_hanging_the_request(): void
    {
        // 600 columnas > 500 (tope por defecto): el análisis termina en «failed» con el motivo en español.
        $wide = ZipFixtures::workbook([1 => array_map(fn ($i) => "C{$i}", range(1, 600)), 2 => range(1, 600)]);
        $response = $this->upload($wide)->assertCreated();
        $response->assertJsonPath('data.status', 'failed');
        $this->assertStringContainsString('600 columnas', (string) $response->json('data.error_message'));
        $this->assertStringContainsString('máximo permitido: 500', (string) $response->json('data.error_message'));
    }
}
