<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use App\Services\Imports\Reading\UnsafeSpreadsheetException;
use App\Services\Imports\Reading\XlsxReader;
use App\Services\Imports\Reading\ZipArchiveInspector;
use App\Services\Imports\Reading\ZipInspection;
use App\Services\Imports\Reading\ZipReadGuard;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Support\ZipFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Endurecimiento de cargas XLSX (V2.5 §15, ADR-V25-15; defecto HIGH «ZIP bomb» de seguridad_ui): la inspección del directorio
 * ZIP rechaza ANTES de abrir el libro las bombas de descompresión —también cuando el directorio central MIENTE sobre los tamaños—
 * y la lectura de encabezados/vista previa está acotada en bytes y en tiempo, apertura del libro incluida.
 * Las bombas se generan en la propia prueba (en streaming: unos KB en disco, cientos de MB descomprimidos).
 *
 * Criterio de los tiempos: un rechazo temprano (inspección de metadatos, recuento de bytes que se corta al pasar de lo declarado,
 * o lectura cortada por la guarda) tarda decenas o centenas de milisegundos; leer de verdad el contenido hostil tardaba de 9 s
 * a 73 s. Los umbrales de las pruebas (2 s para el rechazo de la inspección, 5 s para el de la guarda con los límites por
 * defecto) distinguen ambas cosas con un margen de varias veces sobre lo medido, de modo que una máquina cargada no los
 * confunda con una lectura completa. Cada prueba mide solo la operación bajo prueba, no la generación del libro.
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
        $this->assertLessThan(2.0, microtime(true) - $started, 'la inspección descomprime las partes una vez para contar los bytes reales (≈ 0,1 s medidos)');
        $this->assertGreaterThan(50 * 1_048_576, $inspection->uncompressedBytes, 'es un libro grande de verdad');
        $this->assertLessThan(40, $inspection->maxEntryRatio, 'muy por debajo del tope de razón (100:1)');
        $this->assertLessThan(20 * 1_048_576, $inspection->compressedBytes, 'cabe en el tope de subida de 20 MB');

        // Ventana interactiva (encabezados + vista previa dentro de la petición): holgada para el histórico real (medido ≈ 0,05 s).
        $started = microtime(true);
        $reader = new XlsxReader($path);
        $this->assertCount(20, $reader->headers());
        $preview = 0;
        foreach ($reader->rows() as $row) {
            if (++$preview >= 20) {
                break;
            }
        }
        $this->assertSame(20, $preview);
        $this->assertLessThan(5.0, microtime(true) - $started);

        // Lectura en cola: sin ventana. (La lectura completa de las 99 334 filas tarda minutos por el ritmo de OpenSpout y se midió aparte.)
        $queue = new XlsxReader($path, null, false);
        $queue->headers();
        $count = 0;
        foreach ($queue->rows() as $row) {
            if (++$count >= 500) {
                break;
            }
        }
        $this->assertSame(500, $count);
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

    // ───────────────────── directorio central falsificado: no se confía en lo declarado ─────────────────────

    public function test_a_forged_directory_that_declares_a_tiny_sheet_is_rejected_by_counting_the_real_bytes(): void
    {
        // Réplica del hallazgo: una hoja de 200 MB reales que el directorio declara de 2 KB (antes: 73 s de lectura).
        $path = ZipFixtures::write($this->tmp('hoja-falsa'), ZipFixtures::workbook(null, ['xl/worksheets/sheet1.xml' => ['repeat' => '<x/>', 'bytes' => 200 * 1_048_576, 'declared_size' => 2000]]));
        $this->assertLessThan(2_000_000, filesize($path), 'el archivo es diminuto frente a los 200 MB que contiene');
        $this->assertSame(2000, $this->declaredSize($path, 'xl/worksheets/sheet1.xml'));
        $e = $this->assertRejected($path, UnsafeSpreadsheetException::INCONSISTENT_ENTRY, 422);
        $this->assertSame(2000, $e->context['declared_bytes']);
        $this->assertSame('xl/worksheets/sheet1.xml', $e->context['part']);
    }

    public function test_a_forged_directory_that_declares_tiny_shared_strings_is_rejected_before_the_reader_opens_the_workbook(): void
    {
        // 60 MB reales de cadenas compartidas declaradas como 1 KB: el lector tardaba 62 s en cargarlas. Ahora ni se abre.
        $path = ZipFixtures::write($this->tmp('cadenas-falsas'), ZipFixtures::workbookWithSharedStrings('xl/sharedStrings.xml', ['repeat' => '<si><t>x</t></si>', 'bytes' => 60 * 1_048_576, 'declared_size' => 1000]));
        $this->assertRejected($path, UnsafeSpreadsheetException::INCONSISTENT_ENTRY, 422);
        $this->assertReaderRejected($path, UnsafeSpreadsheetException::INCONSISTENT_ENTRY, [], 2.0);
    }

    public function test_every_part_is_counted_not_only_the_ones_the_reader_opens(): void
    {
        $path = ZipFixtures::write($this->tmp('imagen-falsa'), ZipFixtures::workbook(null, ['xl/media/image1.png' => ['random' => 3_000_000, 'declared_size' => 100]]));
        $this->assertRejected($path, UnsafeSpreadsheetException::INCONSISTENT_ENTRY, 422);
    }

    public function test_a_directory_that_declares_more_than_the_real_content_is_also_inconsistent(): void
    {
        $path = ZipFixtures::write($this->tmp('declara-de-mas'), ZipFixtures::workbook(null, ['xl/worksheets/sheet1.xml' => ['content' => ZipFixtures::sheetXml([1 => ['a']]), 'declared_size' => 50_000]]));
        $this->assertRejected($path, UnsafeSpreadsheetException::INCONSISTENT_ENTRY, 422);
    }

    public function test_a_forged_crc_is_rejected_in_every_kind_of_part(): void
    {
        // El CRC lo valida libzip al llegar al final de cada parte (la inspección las lee enteras): control, hojas y cadenas compartidas.
        foreach (['xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml'] as $part) {
            $entries = ZipFixtures::workbook();
            $entries[$part] = ['content' => $entries[$part], 'declared_crc' => 12345];
            $this->assertRejected(ZipFixtures::write($this->tmp('crc'), $entries), UnsafeSpreadsheetException::INCONSISTENT_ENTRY, 422);
        }
        $path = ZipFixtures::write($this->tmp('crc-cadenas'), ZipFixtures::workbookWithSharedStrings('xl/sharedStrings.xml', ['content' => ZipFixtures::sharedStringsOpen().'<si><t>a</t></si></sst>', 'declared_crc' => 99]));
        $this->assertRejected($path, UnsafeSpreadsheetException::INCONSISTENT_ENTRY, 422);
    }

    public function test_an_honest_workbook_with_matching_sizes_still_passes_and_reports_the_verified_roles(): void
    {
        $path = ZipFixtures::write($this->tmp('honesto'), ZipFixtures::workbookWithSharedStrings('xl/sharedStrings.xml', ZipFixtures::sharedStringsOpen().'<si><t>a</t></si></sst>'));
        $inspection = (new ZipArchiveInspector)->inspect($path);
        $this->assertSame(['xl/worksheets/sheet1.xml'], $inspection->sheetParts);
        $this->assertSame('xl/sharedStrings.xml', $inspection->sharedStringsPart);
        $this->assertNull($inspection->stylesPart);
        $this->assertSame(strlen(ZipFixtures::sharedStringsOpen().'<si><t>a</t></si></sst>'), $inspection->entrySizes['xl/sharedStrings.xml']);
    }

    // ───────────── cadenas compartidas: el tope sigue a la relación, no al nombre literal ─────────────

    public function test_the_shared_strings_cap_applies_to_the_part_named_by_the_relationship_whatever_its_name(): void
    {
        $sharedStrings = ['random' => 2_000_000];
        foreach (['xl/ss.xml', 'xl/data/cadenas.bin'] as $part) {
            $path = ZipFixtures::write($this->tmp('renombrada'), ZipFixtures::workbookWithSharedStrings($part, $sharedStrings));
            $e = $this->assertRejected($path, UnsafeSpreadsheetException::UNCOMPRESSED_TOO_LARGE, 413, ['max_shared_strings_bytes' => 1_000_000]);
            $this->assertStringContainsString('cadenas de texto compartidas', $e->getMessage(), $part);
            $this->assertSame($part, (new ZipArchiveInspector)->inspect($path)->sharedStringsPart);
        }
        // Destino absoluto en la relación («/xl/ss.xml»), como lo escriben otros generadores.
        $absolute = ZipFixtures::workbookWithSharedStrings('xl/ss.xml', $sharedStrings);
        $absolute['xl/_rels/workbook.xml.rels'] = str_replace('Target="ss.xml"', 'Target="/xl/ss.xml"', $absolute['xl/_rels/workbook.xml.rels']);
        $this->assertRejected(ZipFixtures::write($this->tmp('absoluta'), $absolute), UnsafeSpreadsheetException::UNCOMPRESSED_TOO_LARGE, 413, ['max_shared_strings_bytes' => 1_000_000]);
        // Una parte con ese tamaño que ninguna relación señala como cadenas compartidas no la lee el lector: no se le aplica el tope.
        $unreferenced = ZipFixtures::write($this->tmp('sin-relacion'), ZipFixtures::workbook(null, ['xl/ss.xml' => $sharedStrings]));
        (new ZipArchiveInspector(['max_shared_strings_bytes' => 1_000_000]))->inspect($unreferenced);
        $this->addToAssertionCount(1);
    }

    public function test_the_audit_case_of_a_renamed_40_mb_shared_strings_part_is_rejected_with_the_default_limits(): void
    {
        // Réplica del hallazgo: xl/ss.xml de 40 MB con razón ≈ 25:1 (< 100:1) que OpenSpout resuelve por la relación (antes: 16,6 s).
        $path = ZipFixtures::write($this->tmp('ss-40mb'), ZipFixtures::workbookWithSharedStrings('xl/ss.xml', ['chunks' => ZipFixtures::noise(40_000_000, 'si')]));
        $this->assertLessThan(5_000_000, filesize($path));
        $e = $this->assertRejected($path, UnsafeSpreadsheetException::UNCOMPRESSED_TOO_LARGE, 413);
        $this->assertStringContainsString('cadenas de texto compartidas', $e->getMessage());
    }

    public function test_styles_have_the_same_cap_because_the_reader_parses_them_entirely(): void
    {
        $entries = ZipFixtures::workbook(null, ['xl/estilos.bin' => ['random' => 2_000_000]]);
        $entries['xl/_rels/workbook.xml.rels'] = str_replace('</Relationships>', '<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="estilos.bin"/></Relationships>', $entries['xl/_rels/workbook.xml.rels']);
        $e = $this->assertRejected(ZipFixtures::write($this->tmp('estilos'), $entries), UnsafeSpreadsheetException::UNCOMPRESSED_TOO_LARGE, 413, ['max_shared_strings_bytes' => 1_000_000]);
        $this->assertStringContainsString('estilos', $e->getMessage());
    }

    // ───────────── la guarda de lectura, aislada del inspector ─────────────

    public function test_the_guard_refuses_to_open_a_large_non_sheet_part_even_if_the_inspector_resolved_roles_differently(): void
    {
        $path = ZipFixtures::write($this->tmp('guarda'), ZipFixtures::workbook(null, ['xl/oculta.xml' => ['random' => 2_000_000]]));
        $inspection = (new ZipArchiveInspector)->inspect($path);
        $guard = new ZipReadGuard((string) realpath($path), $inspection, (new ZipArchiveInspector(['max_shared_strings_bytes' => 1_000_000]))->limits());
        try {
            $guard->run(fn () => file_get_contents('zip://'.realpath($path).'#xl/oculta.xml'));
            $this->fail('La guarda debió negarse a abrir la parte.');
        } catch (UnsafeSpreadsheetException $e) {
            $this->assertSame(UnsafeSpreadsheetException::UNCOMPRESSED_TOO_LARGE, $e->errorCode);
        }
        $this->assertNoLeakedReaderState();
        // Las hojas no están sujetas a ese tope: se leen enteras (dentro de su presupuesto de ventana).
        $this->assertSame(strlen(ZipFixtures::sheetXml([1 => ['Nro Factura', 'Cantidad'], 2 => ['A-1', 3]])), strlen($guard->run(fn () => file_get_contents('zip://'.realpath($path).'#xl/worksheets/sheet1.xml'))));
    }

    public function test_the_guard_counts_what_is_really_read_against_what_the_directory_declares(): void
    {
        $path = ZipFixtures::write($this->tmp('guarda-real'), ZipFixtures::workbook(null, ['xl/worksheets/sheet1.xml' => ['repeat' => '<x/>', 'bytes' => 5_000_000]]));
        $real = new ZipInspection(1, 1, 1, 1.0, 0, ['xl/worksheets/sheet1.xml' => 1000], ['xl/worksheets/sheet1.xml']);   // inspección con un tamaño que miente
        $guard = new ZipReadGuard((string) realpath($path), $real, (new ZipArchiveInspector)->limits());
        $started = microtime(true);
        try {
            $guard->run(fn () => file_get_contents('zip://'.realpath($path).'#xl/worksheets/sheet1.xml'));
            $this->fail('La guarda debió cortar la lectura al pasar de lo declarado.');
        } catch (UnsafeSpreadsheetException $e) {
            $this->assertSame(UnsafeSpreadsheetException::INCONSISTENT_ENTRY, $e->errorCode);
        }
        $this->assertLessThan(1.0, microtime(true) - $started);
        $this->assertNoLeakedReaderState();
    }

    public function test_the_guard_only_serves_the_workbook_it_was_created_for(): void
    {
        $one = ZipFixtures::write($this->tmp('uno'), ZipFixtures::workbook());
        $two = ZipFixtures::write($this->tmp('dos'), ['secreto.txt' => 'no debe leerse']);
        $guard = new ZipReadGuard((string) realpath($one), (new ZipArchiveInspector)->inspect($one), (new ZipArchiveInspector)->limits());
        $this->assertFalse($guard->run(fn () => @file_get_contents('zip://'.realpath($two).'#secreto.txt')));
        $this->assertFalse($guard->run(fn () => @file_get_contents('zip://'.realpath($one).'#no/existe.xml')));
        $this->assertNoLeakedReaderState();
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

    // ───────────── ventana interactiva: bytes y tiempo (apertura del libro incluida) ─────────────

    /** Rechazo esperado del lector en la ventana interactiva. @param array<string, int|float> $limits */
    private function assertReaderRejected(string $path, string $code, array $limits, float $maxSeconds): UnsafeSpreadsheetException
    {
        $started = microtime(true);
        try {
            (new XlsxReader($path, $limits))->headers();
        } catch (UnsafeSpreadsheetException $e) {
            $elapsed = microtime(true) - $started;
            $this->assertSame($code, $e->errorCode, $e->getMessage());
            $this->assertLessThan($maxSeconds, $elapsed, "rechazado en {$elapsed} s");
            $this->assertNoLeakedReaderState();

            return $e;
        }
        $this->fail('El lector debió rechazar el libro con '.$code);
    }

    /** La guarda restauró el envoltorio zip:// nativo y el estado de libxml, también tras abortar a mitad de lectura. */
    private function assertNoLeakedReaderState(): void
    {
        $this->assertNull(ZipReadGuard::installed());
        $this->assertContains('zip', stream_get_wrappers());
        $this->assertFalse(libxml_use_internal_errors(), 'libxml volvió a su estado inicial');
        $path = $this->tmp('nativo');
        ZipFixtures::write($path, ['a.txt' => 'hola']);
        $this->assertSame('hola', file_get_contents('zip://'.$path.'#a.txt'), 'zip:// nativo restaurado');
    }

    public function test_nodes_before_the_first_row_are_cut_by_the_interactive_byte_budget(): void
    {
        // Libro HONESTO (razón ≈ 25:1, por debajo del tope de 100:1) con 3 MB de nodos antes de la primera fila: nada de lo declarado
        // delata nada; solo contar lo que el lector consume realmente.
        $path = ZipFixtures::write($this->tmp('nodos'), ZipFixtures::workbook(null, ['xl/worksheets/sheet1.xml' => ZipFixtures::sheetParts([['noise' => 3_000_000], ['rows' => [1 => ['Nro Factura', 'Cantidad'], 2 => ['A-1', 3]]]])]));
        (new ZipArchiveInspector)->inspect($path);   // la inspección lo da por bueno: es un libro coherente
        $e = $this->assertReaderRejected($path, UnsafeSpreadsheetException::HEADER_NOT_FOUND, ['analysis_max_sheet_bytes' => 1_000_000], 2.0);
        $this->assertSame(1_000_000, $e->context['max_sheet_bytes']);
        $this->assertStringContainsString('primeros 1 MB', $e->getMessage());
        // Con presupuesto suficiente el mismo libro se lee.
        $this->assertSame(['Nro Factura', 'Cantidad'], (new XlsxReader($path, ['analysis_max_sheet_bytes' => 4_000_000]))->headers());
    }

    public function test_the_audit_case_of_63_mb_of_nodes_before_the_first_row_is_cut_with_the_default_limits(): void
    {
        // Réplica del hallazgo: libro honesto de ≈ 1,6 MB con 40 MB de nodos antes de la primera `<row>` (antes: 9,4 s con 63 MB sin
        // activar ningún límite). Con los valores por defecto la guarda corta a los 8 MB consumidos (≈ 1 s); leer los 40 MB tarda > 6 s.
        $path = ZipFixtures::write($this->tmp('auditoria'), ZipFixtures::workbook(null, ['xl/worksheets/sheet1.xml' => ZipFixtures::sheetParts([['noise' => 40_000_000], ['rows' => [1 => ['Nro Factura', 'Cantidad']]]])]));
        $this->assertLessThan(5_000_000, filesize($path), 'el archivo es diminuto');
        $e = $this->assertReaderRejected($path, UnsafeSpreadsheetException::HEADER_NOT_FOUND, [], 5.0);
        $this->assertStringContainsString('primeros 8 MB', $e->getMessage());
    }

    public function test_the_interactive_time_budget_applies_to_the_sheet_scan(): void
    {
        // Presupuesto de bytes holgado: manda el reloj. 20 MB de nodos tardan ≈ 3 s; el presupuesto de 0,3 s corta mucho antes.
        $path = ZipFixtures::write($this->tmp('reloj'), ZipFixtures::workbook(null, ['xl/worksheets/sheet1.xml' => ZipFixtures::sheetParts([['noise' => 20_000_000], ['rows' => [1 => ['Nro Factura']]]])]));
        $e = $this->assertReaderRejected($path, UnsafeSpreadsheetException::READ_TIMEOUT, ['analysis_max_sheet_bytes' => 64 * 1_048_576, 'analysis_timeout_seconds' => 0.3], 3.0);
        $this->assertSame(0.3, $e->context['timeout_seconds']);
    }

    public function test_the_interactive_time_budget_covers_loading_the_shared_strings_when_opening_the_workbook(): void
    {
        // Antes el reloj empezaba con el libro ya abierto: las cadenas compartidas (carga no interrumpible) quedaban fuera.
        // 6 MB de cadenas compartidas ≈ 3 s de carga; el presupuesto de 0,3 s corta durante la apertura.
        $path = ZipFixtures::write($this->tmp('cadenas'), ZipFixtures::workbookWithSharedStrings('xl/sharedStrings.xml', ['chunks' => ZipFixtures::sharedStringsOfSize(6_000_000)]));
        $this->assertReaderRejected($path, UnsafeSpreadsheetException::READ_TIMEOUT, ['analysis_timeout_seconds' => 0.3], 3.0);
    }

    public function test_the_header_of_every_sheet_is_read_inside_the_budget_not_only_the_first_sheets(): void
    {
        // OpenSpout recorre el encabezado de TODAS las hojas al abrir el libro: una segunda hoja con nodos antes de <sheetData> también cuenta.
        $workbook = ZipFixtures::workbook();
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $workbook['xl/workbook.xml'] = $xml.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Hoja1" sheetId="1" r:id="rId1"/><sheet name="Hoja2" sheetId="2" r:id="rId2"/></sheets></workbook>';
        $workbook['xl/_rels/workbook.xml.rels'] = $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>';
        $workbook['xl/worksheets/sheet2.xml'] = ZipFixtures::sheetParts([['rows' => [1 => ['x']]]], 3_000_000);
        $path = ZipFixtures::write($this->tmp('segunda-hoja'), $workbook);
        $this->assertSame(['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'], (new ZipArchiveInspector)->inspect($path)->sheetParts);
        $this->assertReaderRejected($path, UnsafeSpreadsheetException::HEADER_NOT_FOUND, ['analysis_max_sheet_bytes' => 1_000_000], 2.0);
    }

    public function test_queue_reads_are_not_subject_to_the_interactive_window(): void
    {
        // La importación en cola (xlsx_interactive = false) lee el mismo libro que la ventana interactiva rechaza: 12 MB de nodos antes de las filas.
        $path = ZipFixtures::write($this->tmp('cola'), ZipFixtures::workbook(null, ['xl/worksheets/sheet1.xml' => ZipFixtures::sheetParts([['noise' => 12_000_000], ['rows' => [1 => ['Nro Factura', 'Cantidad'], 2 => ['A-1', 3], 3 => ['A-2', 4]]]])]));
        $this->assertReaderRejected($path, UnsafeSpreadsheetException::HEADER_NOT_FOUND, ['analysis_max_sheet_bytes' => 4_000_000], 3.0);

        $queue = new XlsxReader($path, ['analysis_max_sheet_bytes' => 4_000_000, 'analysis_timeout_seconds' => 0.001], false);
        $this->assertSame(['Nro Factura', 'Cantidad'], $queue->headers());
        $this->assertSame([1 => ['A-1', 3], 2 => ['A-2', 4]], iterator_to_array($queue->rows()));
        $this->assertNoLeakedReaderState();
    }

    public function test_the_window_closes_after_the_preview_rows_and_the_rest_of_the_sheet_is_read_at_its_own_pace(): void
    {
        // 30 filas, 12 MB de nodos y una fila 31: la ventana (25 filas) se cierra antes del hueco; el hueco no la agota ni aborta la lectura completa.
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[$i] = $i === 1 ? ['Nro Factura', 'Cantidad'] : ["A-{$i}", $i];
        }
        $path = ZipFixtures::write($this->tmp('hueco'), ZipFixtures::workbook(null, ['xl/worksheets/sheet1.xml' => ZipFixtures::sheetParts([['rows' => $rows], ['noise' => 12_000_000], ['rows' => [31 => ['A-31', 31]]]])]));
        $reader = new XlsxReader($path, ['analysis_max_sheet_bytes' => 4_000_000, 'analysis_timeout_seconds' => 0.5]);
        $this->assertSame(['Nro Factura', 'Cantidad'], $reader->headers());
        $all = iterator_to_array($reader->rows());
        $this->assertCount(30, $all);
        $this->assertSame(['A-31', 31], $all[30]);
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
