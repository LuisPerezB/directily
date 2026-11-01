<?php

declare(strict_types=1);

namespace App\Services\Imports\Reading;

use LogicException;
use RuntimeException;
use Throwable;

/**
 * Guarda de lectura de OpenSpout durante la ventana interactiva (V2.5 §15, ADR-V25-15; defecto MEDIUM «el presupuesto de
 * tiempo solo se evaluaba por fila»: 63 MB de nodos antes de la primera fila de un libro honesto de 1,4 MB tardaban 9,4 s).
 *
 * OpenSpout abre cada parte del libro con `zip://libro.xlsx#parte` y la analiza con XMLReader: cargar las cadenas compartidas,
 * leer los estilos, recorrer el encabezado de CADA hoja hasta `<sheetData>` y buscar la primera fila ocurren dentro de llamadas
 * que no devuelven el control, así que ningún contador «por fila» las interrumpe. Mientras dura la ventana interactiva
 * (apertura del libro y primeras `analysis_rows` filas: lo que ocurre dentro de la petición de subida) esta clase sustituye el
 * envoltorio `zip://` por GuardedZipStream, que cuenta los bytes REALES que OpenSpout consume de cada parte y, en cada lectura:
 *
 * - aborta con READ_TIMEOUT si pasa del presupuesto de tiempo de toda la ventana (`analysis_timeout_seconds`), apertura incluida;
 * - aborta con HEADER_NOT_FOUND si una hoja consume más de `analysis_max_sheet_bytes` sin haber entregado las primeras filas;
 * - aborta con INCONSISTENT_ENTRY si una parte produce más bytes de los que declara su directorio (defensa en profundidad:
 *   ZipArchiveInspector ya lo verificó);
 * - rechaza abrir como parte de datos algo que no es una hoja y supera el tope de cadenas compartidas (resolución por relación
 *   distinta de la del inspector).
 *
 * El envoltorio solo está instalado durante cada llamada a OpenSpout (`run()`), nunca mientras el generador está suspendido,
 * y se restaura siempre. Cerrada la ventana (`closeWindow()`) o en lectura no interactiva (la importación en cola) la guarda no
 * instala nada y OpenSpout trabaja a su ritmo natural.
 */
final class ZipReadGuard
{
    /** Guarda cuyo envoltorio está instalado en este momento (la que recibe los streams que se abran). */
    private static ?self $installed = null;

    private ?self $outer = null;

    private bool $windowOpen = true;

    private readonly float $deadline;

    public ?UnsafeSpreadsheetException $violation = null;

    /** @param array<string, int|float> $limits */
    public function __construct(
        private readonly string $path,
        private readonly ZipInspection $inspection,
        private readonly array $limits,
        private readonly bool $enabled = true,
    ) {
        $this->deadline = microtime(true) + (float) $limits['analysis_timeout_seconds'];
    }

    public static function installed(): ?self
    {
        return self::$installed;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function windowOpen(): bool
    {
        return $this->enabled && $this->windowOpen;
    }

    public function closeWindow(): void
    {
        $this->windowOpen = false;
    }

    /**
     * Ejecuta una llamada a OpenSpout bajo la guarda mientras la ventana siga abierta (si no, la ejecuta tal cual).
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    public function run(callable $call): mixed
    {
        if (! $this->windowOpen()) {
            return $call();
        }
        $internalErrors = libxml_use_internal_errors();
        $this->install();
        try {
            $this->checkDeadline();

            return $call();
        } catch (Throwable $e) {
            // OpenSpout activa los errores internos de libxml y los restablece tras cada lectura; si la lectura se corta aquí no lo hace.
            libxml_clear_errors();
            libxml_use_internal_errors($internalErrors);
            // Si OpenSpout envolvió nuestro aborto en otra excepción, el motivo real es el que la guarda registró.
            throw $this->violation ?? $e;
        } finally {
            $this->uninstall();
        }
    }

    /** @throws UnsafeSpreadsheetException */
    public function checkDeadline(): void
    {
        if ($this->windowOpen() && microtime(true) > $this->deadline) {
            $seconds = (float) $this->limits['analysis_timeout_seconds'];
            throw $this->violation = new UnsafeSpreadsheetException(
                sprintf('El análisis del libro superó el tiempo máximo permitido (%s s). Simplifique el libro (menos columnas o textos distintos) o use CSV.', rtrim(rtrim(number_format($seconds, 2, ',', ''), '0'), ',')),
                UnsafeSpreadsheetException::READ_TIMEOUT, 422, ['timeout_seconds' => $seconds],
            );
        }
    }

    /**
     * @return array{declared: int, sheet: bool}|null null si la parte no existe o no pertenece al libro que se lee
     */
    public function lookup(string $file, string $entry): ?array
    {
        if ($file !== $this->path && realpath($file) !== $this->path) {
            return null;
        }
        $declared = $this->inspection->entrySizes[$entry] ?? null;

        return $declared === null ? null : ['declared' => $declared, 'sheet' => in_array($entry, $this->inspection->sheetParts, true)];
    }

    /**
     * Autoriza abrir una parte. Todo lo que OpenSpout lee y no es una hoja (relaciones, workbook, cadenas compartidas, estilos)
     * está acotado por el tope de cadenas compartidas.
     *
     * @return array{declared: int, sheet: bool}|null
     *
     * @throws UnsafeSpreadsheetException
     */
    public function authorize(string $file, string $entry): ?array
    {
        $info = $this->lookup($file, $entry);
        if ($info !== null && ! $info['sheet'] && $info['declared'] > (int) $this->limits['max_shared_strings_bytes']) {
            $max = (int) $this->limits['max_shared_strings_bytes'];
            throw $this->violation = new UnsafeSpreadsheetException(
                sprintf('El tamaño descomprimido de una parte del libro que no es una hoja (%s MB) supera el máximo permitido (%s MB). Divida el archivo o use CSV.', number_format($info['declared'] / 1_048_576, 0, ',', '.'), number_format($max / 1_048_576, 0, ',', '.')),
                UnsafeSpreadsheetException::UNCOMPRESSED_TOO_LARGE, 413, ['bytes' => $info['declared'], 'max_bytes' => $max],
            );
        }

        return $info;
    }

    /**
     * Cuenta de una lectura: $read son los bytes que OpenSpout lleva consumidos de esa apertura de la parte.
     *
     * @throws UnsafeSpreadsheetException
     */
    public function onRead(int $read, int $declared, bool $sheet): void
    {
        if ($read > $declared) {
            throw $this->violation = new UnsafeSpreadsheetException(
                'El libro de Excel está dañado o fue manipulado: el contenido real de una de sus partes no coincide con lo que declara su directorio. Expórtelo de nuevo desde Excel o use CSV.',
                UnsafeSpreadsheetException::INCONSISTENT_ENTRY, 422, ['declared_bytes' => $declared],
            );
        }
        if (! $this->windowOpen()) {
            return;
        }
        $max = (int) $this->limits['analysis_max_sheet_bytes'];
        if ($sheet && $read > $max) {
            throw $this->violation = new UnsafeSpreadsheetException(
                sprintf('No se encontraron los encabezados y las primeras filas de datos en los primeros %s MB de la hoja. Quite el contenido que precede a los datos o use CSV.', number_format($max / 1_048_576, 0, ',', '.')),
                UnsafeSpreadsheetException::HEADER_NOT_FOUND, 422, ['max_sheet_bytes' => $max],
            );
        }
        $this->checkDeadline();
    }

    private function install(): void
    {
        $this->outer = self::$installed;
        self::$installed = $this;
        if ($this->outer !== null) {
            return;   // ya hay un envoltorio instalado (llamada anidada): solo cambia la guarda activa
        }
        if (! in_array('zip', stream_get_wrappers(), true) || ! stream_wrapper_unregister('zip')) {
            self::$installed = null;
            throw new RuntimeException('No se pudo preparar la guarda de lectura del libro.');
        }
        if (! stream_wrapper_register('zip', GuardedZipStream::class)) {
            stream_wrapper_restore('zip');
            self::$installed = null;
            throw new RuntimeException('No se pudo preparar la guarda de lectura del libro.');
        }
    }

    private function uninstall(): void
    {
        if (self::$installed !== $this) {
            throw new LogicException('Guarda de lectura desbalanceada.');
        }
        self::$installed = $this->outer;
        $this->outer = null;
        if (self::$installed === null) {
            stream_wrapper_unregister('zip');
            stream_wrapper_restore('zip');
        }
    }
}
