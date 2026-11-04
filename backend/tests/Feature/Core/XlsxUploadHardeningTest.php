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

    /** Escribe el libro en disco (la generación de bombas de cientos de MB no cuenta en los tiempos de las pruebas). @param array<array-key, mixed> $entries */
    private function workbookFile(array $entries): string
    {
        $path = $this->files[] = sys_get_temp_dir().'/subida-'.bin2hex(random_bytes(4)).'.xlsx';
        ZipFixtures::write($path, $entries);

        return $path;
    }

    private function uploadFile(string $path, string $filename = 'ventas.xlsx'): TestResponse
    {
        return $this->post('/api/v1/imports', [
            'file' => new UploadedFile($path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'entity_type' => 'sales',
        ], ['Accept' => 'application/json']);
    }

    /** @param array<array-key, mixed> $entries */
    private function upload(array $entries, string $filename = 'ventas.xlsx'): TestResponse
    {
        return $this->uploadFile($this->workbookFile($entries), $filename);
    }

    private function assertNothingWasStored(): void
    {
        $this->assertSame(0, DB::table('import_jobs')->where('tenant_id', $this->tenant->id)->count(), 'no se creó la importación');
        $this->assertSame([], Storage::disk('local')->allFiles(), 'no se guardó el archivo');
    }

    public function test_the_audit_bomb_is_rejected_with_413_a_stable_code_and_without_reading_it(): void
    {
        $path = $this->workbookFile(ZipFixtures::workbook(null, ['xl/sharedStrings.xml' => ['repeat' => 'a', 'bytes' => 200_000_183]]));
        // Criterio del tiempo: solo se mide la petición (no la generación del libro). El rechazo temprano de la inspección de metadatos
        // tarda decenas de milisegundos más el arranque de la petición (< 1 s incluso con la máquina cargada); leer la bomba de verdad
        // tardaba 23 s. El umbral de 8 s separa ambas cosas con un margen de 8 veces sobre lo medido y de 3 veces bajo la lectura completa.
        $started = microtime(true);
        $response = $this->uploadFile($path);
        $elapsed = microtime(true) - $started;

        $response->assertStatus(413)
            ->assertJsonPath('code', 'xlsx_uncompressed_too_large')
            ->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'supera el máximo permitido'));
        $this->assertStringContainsString('cadenas de texto compartidas', $response->json('message'));
        $this->assertLessThan(8.0, $elapsed, "rechazada en {$elapsed} s (antes: 23 s de CPU dentro de la petición)");
        $this->assertNothingWasStored();
    }

    public function test_a_forged_central_directory_is_rejected_at_upload_by_counting_the_real_bytes(): void
    {
        // Una hoja de 100 MB reales que el directorio declara de 2 KB: antes pasaba la inspección y el análisis tardaba 73 s.
        $path = $this->workbookFile(ZipFixtures::workbook(null, ['xl/worksheets/sheet1.xml' => ['repeat' => '<x/>', 'bytes' => 100 * 1_048_576, 'declared_size' => 2000]]));
        $started = microtime(true);
        $response = $this->uploadFile($path);
        $elapsed = microtime(true) - $started;
        $response->assertStatus(422)->assertJsonPath('code', 'xlsx_inconsistent_entry');
        $this->assertLessThan(8.0, $elapsed, "rechazada en {$elapsed} s (antes: 73 s)");
        $this->assertNothingWasStored();

        // Cadenas compartidas renombradas (xl/ss.xml, resueltas por la relación) que superan el tope: 413, sin guardar nada.
        $this->upload(ZipFixtures::workbookWithSharedStrings('xl/ss.xml', ['random' => 2_000_000]))->assertStatus(201);
        config(['upload_safety.xlsx.max_shared_strings_bytes' => 1_000_000]);
        $this->upload(ZipFixtures::workbookWithSharedStrings('xl/ss.xml', ['random' => 2_000_000]))
            ->assertStatus(413)->assertJsonPath('code', 'xlsx_uncompressed_too_large');
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

    public function test_the_analysis_is_bounded_by_the_interactive_window_but_the_queued_validation_is_not(): void
    {
        $rows = [1 => self::SALES_HEADERS];
        foreach ($this->validSalesRows() as $i => $row) {
            $rows[$i + 2] = $row;
        }
        // Límites de la ventana interactiva imposibles de cumplir (100 bytes de hoja, 1 µs): el análisis de la subida los respeta…
        config(['upload_safety.xlsx.analysis_max_sheet_bytes' => 100, 'upload_safety.xlsx.analysis_timeout_seconds' => 0.000001]);
        $failed = $this->upload(ZipFixtures::workbook($rows))->assertCreated();
        $failed->assertJsonPath('data.status', 'failed');
        $this->assertNotSame('', (string) $failed->json('data.error_message'));

        // …mientras que la validación en cola lee el mismo libro sin ventana (xlsx_interactive = false desde ImportService::execute).
        config(['upload_safety.xlsx.analysis_max_sheet_bytes' => 8 * 1_048_576, 'upload_safety.xlsx.analysis_timeout_seconds' => 10]);
        $upload = $this->upload(ZipFixtures::workbook($rows))->assertCreated()->assertJsonPath('data.status', 'awaiting_mapping');
        config(['upload_safety.xlsx.analysis_max_sheet_bytes' => 100, 'upload_safety.xlsx.analysis_timeout_seconds' => 0.000001]);
        $this->putJson('/api/v1/imports/'.$upload->json('data.id').'/mapping', ['mapping' => $upload->json('data.suggested_mapping')])->assertAccepted();
        $this->assertSame('validated', DB::table('import_jobs')->where('id', $upload->json('data.id'))->value('status'));
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
