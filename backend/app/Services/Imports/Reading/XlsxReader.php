<?php

declare(strict_types=1);

namespace App\Services\Imports\Reading;

use Generator;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * XLSX en streaming (OpenSpout). Usa la primera hoja; la primera fila no vacía es el encabezado.
 *
 * Endurecimiento (V2.5 §15, ADR-V25-15; defecto HIGH «ZIP bomb»):
 * - Antes de abrir el libro se inspecciona (ZipArchiveInspector): directorio central, partes de control, tamaños REALES de cada
 *   parte (no se confía en los declarados) y CRC de las partes que el lector abre siempre. Un libro que no cumple lanza
 *   UnsafeSpreadsheetException.
 * - La lectura de encabezados y vista previa —la ventana interactiva: la apertura del libro y las primeras `analysis_rows`
 *   filas, que ocurre dentro de la petición de subida— está acotada en columnas, en filas recorridas (también las vacías), en
 *   bytes de hoja consumidos y en tiempo; los dos últimos los hace cumplir ZipReadGuard sobre lo que OpenSpout lee de verdad,
 *   también cuando la apertura carga cadenas compartidas o recorre nodos que no son filas. Pasada la ventana, y siempre que la
 *   lectura no sea interactiva (la importación en cola), la lectura completa sigue el ritmo del archivo.
 * - `headers()` y `rows()` comparten la misma apertura del libro: las cadenas compartidas se cargan una sola vez.
 */
final class XlsxReader implements TabularReader
{
    /** @var list<string>|null */
    private ?array $headers = null;

    private ?string $sheetName = null;

    /** Recorrido abierto por headers() y todavía no entregado a rows(). */
    private ?Generator $primed = null;

    private ?ZipInspection $inspection = null;

    /**
     * @param  array<string, int|float>|null  $limits  sobrescrituras de config('upload_safety.xlsx') (pruebas)
     * @param  bool  $interactive  true (por defecto, seguro): la apertura y las primeras filas están sujetas a la ventana interactiva
     *                             (tiempo y bytes). false para la lectura en cola (validación e importación), que no la tiene.
     */
    public function __construct(private readonly string $path, private readonly ?array $limits = null, private readonly bool $interactive = true) {}

    public function dialect(): array
    {
        $this->headers();

        return ['format' => 'xlsx', 'sheet' => $this->sheetName];
    }

    public function headers(): array
    {
        if ($this->headers === null) {
            $stream = $this->rawRows();
            foreach ($stream as $row) {
                $this->headers = array_map(fn ($h) => trim($this->stringify($h)), $row);
                break;
            }
            $this->headers ??= [];
            // El recorrido queda posicionado en el encabezado: rows() continúa desde aquí sin reabrir el libro.
            $this->primed = $stream->valid() ? $stream : null;
        }

        return $this->headers;
    }

    public function rows(): Generator
    {
        $first = true;
        $n = 0;
        $width = count($this->headers());
        $stream = $this->primed ?? $this->rawRows();
        $this->primed = null;
        foreach ($stream as $row) {
            if ($first) {
                $first = false;

                continue;
            }
            yield ++$n => array_pad($row, $width, null);
        }
    }

    /** @return array<string, int|float> */
    private function effectiveLimits(): array
    {
        return array_merge((new ZipArchiveInspector(ZipArchiveInspector::configured()))->limits(), $this->limits ?? []);
    }

    /** @return Generator<int, list<mixed>> */
    private function rawRows(): Generator
    {
        $limits = $this->effectiveLimits();
        $this->inspection ??= (new ZipArchiveInspector($limits))->inspect($this->path);
        $guard = new ZipReadGuard((string) realpath($this->path), $this->inspection, $limits, $this->interactive);
        $scanned = 0;
        $delivered = 0;
        // Se conservan las filas vacías para poder contarlas y acotarlas; el filtro de abajo las descarta como antes.
        $reader = new Reader(new Options(SHOULD_FORMAT_DATES: false, SHOULD_PRESERVE_EMPTY_ROWS: true));
        // OpenSpout carga aquí las cadenas compartidas y recorre el encabezado de cada hoja: la guarda acota bytes y tiempo.
        $guard->run(fn () => $reader->open($this->path));
        try {
            $sheets = $reader->getSheetIterator();
            $sheets->rewind();
            if (! $sheets->valid()) {
                return;
            }
            $sheet = $sheets->current();   // solo la primera hoja
            $this->sheetName = $sheet->getName();
            $rows = $sheet->getRowIterator();
            $guard->run(fn () => $rows->rewind());   // abre la hoja y lee hasta la primera fila
            for (; $rows->valid(); $guard->run(fn () => $rows->next())) {
                $interactive = $delivered < (int) $limits['analysis_rows'];
                if ($interactive) {
                    if (++$scanned > (int) $limits['analysis_max_scanned_rows']) {
                        throw new UnsafeSpreadsheetException(
                            sprintf('No se encontraron los encabezados y las primeras filas de datos en las primeras %s filas de la hoja. Quite las filas vacías del inicio o use CSV.', number_format((int) $limits['analysis_max_scanned_rows'], 0, ',', '.')),
                            UnsafeSpreadsheetException::HEADER_NOT_FOUND, 422, ['max_scanned_rows' => (int) $limits['analysis_max_scanned_rows']],
                        );
                    }
                    $guard->checkDeadline();
                }
                $values = $rows->current()->toArray();
                if (count(array_filter($values, fn ($v) => $v !== null && $v !== '')) === 0) {
                    continue;
                }
                if ($interactive && count($values) > (int) $limits['max_columns']) {
                    throw new UnsafeSpreadsheetException(
                        sprintf('La hoja tiene una fila con %s columnas (máximo permitido: %s). Quite las columnas innecesarias o use CSV.', number_format(count($values), 0, ',', '.'), number_format((int) $limits['max_columns'], 0, ',', '.')),
                        UnsafeSpreadsheetException::TOO_MANY_COLUMNS, 422, ['columns' => count($values), 'max_columns' => (int) $limits['max_columns']],
                    );
                }
                if (++$delivered >= (int) $limits['analysis_rows']) {
                    $guard->closeWindow();   // entregadas las filas de la ventana interactiva: el resto sigue el ritmo del archivo
                }
                yield $values;
            }
        } finally {
            $reader->close();
        }
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }
}
