<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use App\Services\Imports\Reading\UnsafeSpreadsheetException;
use App\Services\Imports\Reading\XlsxReader;
use App\Services\Imports\Reading\ZipArchiveInspector;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Support\ZipFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Endurecimiento de cargas XLSX (V2.5 §15, ADR-V25-15; defecto HIGH «ZIP bomb» de seguridad_ui): la inspección del directorio
 * ZIP rechaza ANTES de abrir el libro las bombas de descompresión, y la lectura de encabezados/vista previa está acotada.
 * Las bombas se generan en la propia prueba (en streaming: unos KB en disco, cientos de MB descomprimidos).
 */
final class ZipArchiveInspectorTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function tmp(string $name = 'libro'): string
    {
        return $this->files[] = sys_get_temp_dir().'/'.$name.'-'.bin2hex(random_bytes(4)).'.xlsx';
    }

    /** Rechazo esperado, con código y estado estables y en menos de 2 s (el requisito de la auditoría). */
    private function assertRejected(string $path, string $code, int $status, array $limits = []): UnsafeSpreadsheetException
    {
        $started = microtime(true);
        try {
            (new ZipArchiveInspector($limits))->inspect($path);
        } catch (UnsafeSpreadsheetException $e) {
            $elapsed = microtime(true) - $started;
            $this->assertSame([$code, $status], [$e->errorCode, $e->status], $e->getMessage());
            $this->assertLessThan(2.0, $elapsed, "rechazado en {$elapsed} s");
            $this->assertNotSame('', $e->getMessage());
            $this->assertMatchesRegularExpression('/[áéíóúñ]|[Ee]l |[Ll]a |[Ee]xport/u', $e->getMessage(), 'mensaje en español');

            return $e;
        }
        $this->fail('El libro debió rechazarse con '.$code);
    }

    // ───────────────────────── libros legítimos ─────────────────────────

    public function test_a_normal_workbook_passes_the_default_limits(): void
    {
        $path = $this->tmp();
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Numero Documento', 'Fecha', 'SKU', 'Producto', 'Cantidad', 'PVP']));
        for ($i = 1; $i <= 2000; $i++) {
            $writer->addRow(Row::fromValues(["F-{$i}", new \DateTimeImmutable('2026-02-10 10:30:00'), "SKU-{$i}", "Producto {$i}", $i % 17, 1.25 * $i]));
        }
        $writer->close();

        $inspection = (new ZipArchiveInspector)->inspect($path);
        $this->assertGreaterThan(5, $inspection->entries);
        $this->assertLessThan(100, $inspection->maxEntryRatio);
        $this->assertGreaterThan(0, $inspection->uncompressedBytes);
    }

    public function test_the_default_limits_accept_the_real_history_profile_of_99334_lines(): void
    {
        $csv = base_path('../data/demo/ferreteria_andina_sales.csv');
        if (! is_file($csv)) {
            $this->markTestSkipped('No está el CSV demo (data/demo/ferreteria_andina_sales.csv).');
        }
        // Libro equivalente al histórico real: 99 334 líneas × 20 columnas (≈ 120 MB de XML descomprimido), cadenas «inline».
        $rows = (function () use ($csv) {
            $handle = fopen($csv, 'rb');
            $n = 0;
            $buffer = '';
            while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $cells = array_map(fn ($v) => is_numeric($v) && ! str_starts_with((string) $v, '0') ? $v + 0 : $v, $cells);
                $buffer .= ZipFixtures::rowXml(++$n, $cells);
                if ($n % 2000 === 0) {
                    yield $buffer;
                    $buffer = '';
                }
            }
            fclose($handle);
            yield $buffer;
        })();
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $sheet = (function () use ($rows, $head) {
            yield $head;
            yield from $rows;
            yield '</sheetData></worksheet>';
        })();
        $path = ZipFixtures::write($this->tmp('historico'), ZipFixtures::workbook(null, ['xl/worksheets/sheet1.xml' => ['chunks' => $sheet]]));

        $started = microtime(true);
        $inspection = (new ZipArchiveInspector)->inspect($path);
        $this->assertLessThan(0.5, microtime(true) - $started, 'la inspección solo lee el directorio central');
        $this->assertGreaterThan(50 * 1_048_576, $inspection->uncompressedBytes, 'es un libro grande de verdad');
        $this->assertLessThan(40, $inspection->maxEntryRatio, 'muy por debajo del tope de razón (100:1)');
        $this->assertLessThan(20 * 1_048_576, $inspection->compressedBytes, 'cabe en el tope de subida de 20 MB');
    }

    // ───────────────────────── bombas y estructuras abusivas ─────────────────────────

    public function test_the_audit_bomb_is_rejected_in_under_two_seconds(): void
    {
        // Réplica de la bomba de la auditoría (bomb_many.xlsx): cadenas compartidas de 200 000 183 bytes en un libro diminuto.
        $path = ZipFixtures::write($this->tmp('bomba'), ZipFixtures::workbook(null, ['xl/sharedStrings.xml' => ['repeat' => 'a', 'bytes' => 200_000_183]]));
        $this->assertLessThan(1_000_000, filesize($path), 'el archivo comprimido es diminuto');
        $this->assertGreaterThan(190_000_000, $this->declaredSize($path, 'xl/sharedStrings.xml'));

        $e = $this->assertRejected($path, UnsafeSpreadsheetException::UNCOMPRESSED_TOO_LARGE, 413);
        $this->assertStringContainsString('cadenas de texto compartidas', $e->getMessage());
    }

    public function test_a_highly_compressed_sheet_is_rejected_by_the_per_entry_ratio(): void
    {
        $path = ZipFixtures::write($this->tmp('razon'), ZipFixtures::workbook(null, ['xl/worksheets/sheet1.xml' => ['repeat' => '<c/>', 'bytes' => 64 * 1_048_576]]));
        $this->assertRejected($path, UnsafeSpreadsheetException::COMPRESSION_RATIO, 413);
    }

    public function test_the_total_and_per_entry_sizes_are_capped(): void
    {
        $path = ZipFixtures::write($this->tmp('total'), ZipFixtures::workbook(null, [
            'xl/worksheets/sheet1.xml' => ['random' => 3_000_000],
            'xl/worksheets/sheet2.xml' => ['random' => 3_000_000],
        ]));
        $this->assertRejected($path, UnsafeSpreadsheetException::UNCOMPRESSED_TOO_LARGE, 413, ['max_total_bytes' => 4_000_000]);
        $this->assertRejected($path, UnsafeSpreadsheetException::UNCOMPRESSED_TOO_LARGE, 413, ['max_entry_bytes' => 2_000_000]);
        (new ZipArchiveInspector(['max_total_bytes' => 7_000_000]))->inspect($path);
        $this->addToAssertionCount(1);
    }

    public function test_shared_strings_have_their_own_cap(): void
    {
        $path = ZipFixtures::write($this->tmp('shared'), ZipFixtures::workbook(null, ['xl/sharedStrings.xml' => ['random' => 2_000_000]]));
        $this->assertRejected($path, UnsafeSpreadsheetException::UNCOMPRESSED_TOO_LARGE, 413, ['max_shared_strings_bytes' => 1_000_000]);
    }

    public function test_too_many_entries_are_rejected(): void
    {
        $entries = ZipFixtures::workbook();
        for ($i = 0; $i < 1200; $i++) {
            $entries["xl/extra/{$i}.xml"] = '<a/>';
        }
        $path = ZipFixtures::write($this->tmp('entradas'), $entries);
        $e = $this->assertRejected($path, UnsafeSpreadsheetException::TOO_MANY_ENTRIES, 422);
        $this->assertSame(1205, $e->context['entries']);
    }

    public function test_nested_archives_are_rejected_by_extension_and_by_magic_bytes(): void
    {
        $inner = ZipFixtures::write($this->tmp('interno'), ['a.txt' => 'hola']);
        $byExtension = ZipFixtures::write($this->tmp('anidado'), ZipFixtures::workbook(null, ['xl/embeddings/interno.zip' => (string) file_get_contents($inner)]));
        $this->assertRejected($byExtension, UnsafeSpreadsheetException::NESTED_ARCHIVE, 422);

        $disguised = ZipFixtures::write($this->tmp('disfrazado'), ZipFixtures::workbook(null, ['xl/media/image1.png' => (string) file_get_contents($inner)]));
        $this->assertRejected($disguised, UnsafeSpreadsheetException::NESTED_ARCHIVE, 422);

        $gzip = ZipFixtures::write($this->tmp('gzip'), ZipFixtures::workbook(null, ['xl/media/data.bin' => gzencode('contenido')]));
        $this->assertRejected($gzip, UnsafeSpreadsheetException::NESTED_ARCHIVE, 422);

        $embeddedWorkbook = ZipFixtures::write($this->tmp('libro-dentro'), ZipFixtures::workbook(null, ['xl/embeddings/Microsoft_Excel_Sheet1.xlsx' => 'x']));
        $this->assertRejected($embeddedWorkbook, UnsafeSpreadsheetException::NESTED_ARCHIVE, 422);
    }

    public function test_overlapping_entries_that_share_data_are_rejected(): void
    {
        // 200 registros del directorio central apuntan al mismo bloque de 500 KB: 100 MB «comprimidos» en un archivo de 0,5 MB.
        $path = ZipFixtures::overlapping($this->tmp('solapado'), 200);
        $this->assertLessThan(700_000, filesize($path));
        $this->assertRejected($path, UnsafeSpreadsheetException::INVALID_ARCHIVE, 422);
    }

    public function test_malformed_and_non_workbook_archives_are_rejected(): void
    {
        $text = $this->tmp('texto');
        file_put_contents($text, 'esto no es un zip, solo texto con extensión xlsx');
        $this->assertRejected($text, UnsafeSpreadsheetException::INVALID_ARCHIVE, 422);

        $empty = $this->tmp('vacio');
        file_put_contents($empty, '');
        $this->assertRejected($empty, UnsafeSpreadsheetException::INVALID_ARCHIVE, 422);

        $this->assertRejected(sys_get_temp_dir().'/no-existe-'.bin2hex(random_bytes(4)).'.xlsx', UnsafeSpreadsheetException::INVALID_ARCHIVE, 422);

        $truncated = $this->tmp('truncado');
        file_put_contents($truncated, substr((string) file_get_contents(ZipFixtures::write($this->tmp('base'), ZipFixtures::workbook())), 0, 120));
        $this->assertRejected($truncated, UnsafeSpreadsheetException::INVALID_ARCHIVE, 422);

        $notWorkbook = ZipFixtures::write($this->tmp('docx'), ['word/document.xml' => '<w/>', '[Content_Types].xml' => '<Types/>']);
        $this->assertRejected($notWorkbook, UnsafeSpreadsheetException::INVALID_ARCHIVE, 422);

        $slip = ZipFixtures::write($this->tmp('slip'), ZipFixtures::workbook(null, ['../../evil.xml' => '<a/>']));
        $this->assertRejected($slip, UnsafeSpreadsheetException::INVALID_ARCHIVE, 422);

        $pairs = [];
        foreach (ZipFixtures::workbook() as $name => $content) {
            $pairs[] = [$name, $content];
        }
        $pairs[] = ['xl/workbook.xml', '<otra/>'];   // nombre repetido: ambigüedad sobre cuál contenido se lee
        $this->assertRejected(ZipFixtures::write($this->tmp('duplicado'), $pairs), UnsafeSpreadsheetException::INVALID_ARCHIVE, 422);
    }

    public function test_encrypted_workbooks_are_rejected(): void
    {
        $path = $this->tmp('cifrado');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (ZipFixtures::workbook() as $name => $content) {
            $zip->addFromString($name, (string) $content);
        }
        if (! $zip->setEncryptionName('xl/worksheets/sheet1.xml', ZipArchive::EM_AES_256, 'clave-de-prueba')) {
            $zip->close();
            $this->markTestSkipped('La libzip de este entorno no soporta cifrado.');
        }
        $zip->close();
        $this->assertRejected($path, UnsafeSpreadsheetException::ENCRYPTED, 422);
    }

    // ───────────────────────── lectura de encabezados y vista previa ─────────────────────────

    public function test_the_reader_reads_headers_and_rows_with_one_opening(): void
    {
        $path = ZipFixtures::write($this->tmp('lectura'), ZipFixtures::workbook([
            3 => ['Nro Factura', 'Cantidad', 'Notas'],   // dos filas vacías antes del encabezado
            4 => ['A-1', 3],
            6 => ['A-2', 4, 'x'],
        ]));
        $reader = new XlsxReader($path);
        $this->assertSame(['Nro Factura', 'Cantidad', 'Notas'], $reader->headers());
        $this->assertSame('Hoja1', $reader->dialect()['sheet']);
        $this->assertSame([1 => ['A-1', 3, null], 2 => ['A-2', 4, 'x']], iterator_to_array($reader->rows()));
        // Segunda lectura completa (reabre el libro): mismo resultado.
        $this->assertSame([1 => ['A-1', 3, null], 2 => ['A-2', 4, 'x']], iterator_to_array($reader->rows()));
        // Sin haber pedido los encabezados antes.
        $this->assertCount(2, iterator_to_array((new XlsxReader($path))->rows()));
    }

    public function test_an_empty_sheet_has_no_headers_and_no_rows(): void
    {
        $path = ZipFixtures::write($this->tmp('vacia'), ZipFixtures::workbook([]));
        $reader = new XlsxReader($path);
        $this->assertSame([], $reader->headers());
        $this->assertSame([], iterator_to_array($reader->rows()));
    }

    public function test_a_header_row_wider_than_the_column_limit_is_rejected(): void
    {
        $path = ZipFixtures::write($this->tmp('columnas'), ZipFixtures::workbook([1 => array_map(fn ($i) => "C{$i}", range(1, 12))]));
        try {
            (new XlsxReader($path, ['max_columns' => 10]))->headers();
            $this->fail('Debió rechazar el encabezado demasiado ancho.');
        } catch (UnsafeSpreadsheetException $e) {
            $this->assertSame([UnsafeSpreadsheetException::TOO_MANY_COLUMNS, 422], [$e->errorCode, $e->status]);
            $this->assertStringContainsString('12 columnas', $e->getMessage());
        }
    }

    public function test_the_default_column_limit_accepts_a_wide_but_reasonable_sheet(): void
    {
        $path = ZipFixtures::write($this->tmp('ancho'), ZipFixtures::workbook([1 => array_map(fn ($i) => "C{$i}", range(1, 200)), 2 => range(1, 200)]));
        $this->assertCount(200, (new XlsxReader($path))->headers());
    }

    public function test_scanning_empty_rows_before_the_header_is_bounded(): void
    {
        // El encabezado está en la fila 500 y el recorrido interactivo admite 100 filas: no se queda recorriendo millones.
        $path = ZipFixtures::write($this->tmp('dispersa'), ZipFixtures::workbook([500 => ['Nro Factura', 'Cantidad'], 501 => ['A-1', 3]]));
        try {
            (new XlsxReader($path, ['analysis_max_scanned_rows' => 100]))->headers();
            $this->fail('Debió rechazar el recorrido de filas vacías.');
        } catch (UnsafeSpreadsheetException $e) {
            $this->assertSame([UnsafeSpreadsheetException::HEADER_NOT_FOUND, 422], [$e->errorCode, $e->status]);
        }
        // Con el tope por defecto (20 000) el mismo libro se lee.
        $this->assertSame(['Nro Factura', 'Cantidad'], (new XlsxReader($path))->headers());
    }

    public function test_the_header_and_preview_read_has_a_time_budget(): void
    {
        $path = ZipFixtures::write($this->tmp('tiempo'), ZipFixtures::workbook());
        try {
            (new XlsxReader($path, ['analysis_timeout_seconds' => 0.000001]))->headers();
            $this->fail('Debió agotar el presupuesto de tiempo.');
        } catch (UnsafeSpreadsheetException $e) {
            $this->assertSame([UnsafeSpreadsheetException::READ_TIMEOUT, 422], [$e->errorCode, $e->status]);
        }
    }

    public function test_the_reader_rejects_the_bomb_before_parsing_anything(): void
    {
        $path = ZipFixtures::write($this->tmp('bomba-lector'), ZipFixtures::workbook(null, ['xl/sharedStrings.xml' => ['repeat' => 'a', 'bytes' => 200_000_183]]));
        $started = microtime(true);
        try {
            (new XlsxReader($path))->headers();
            $this->fail('Debió rechazar la bomba.');
        } catch (UnsafeSpreadsheetException $e) {
            $this->assertSame(UnsafeSpreadsheetException::UNCOMPRESSED_TOO_LARGE, $e->errorCode);
        }
        $this->assertLessThan(2.0, microtime(true) - $started, 'antes: 23 s de CPU para leer solo los encabezados');
    }

    private function declaredSize(string $path, string $entry): int
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $size = (int) ($zip->statName($entry)['size'] ?? 0);
        $zip->close();

        return $size;
    }
}
