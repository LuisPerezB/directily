<?php

declare(strict_types=1);

namespace App\Services\Imports\Reading;

use ZipArchive;

/**
 * Inspección del directorio central de un ZIP (un XLSX lo es) ANTES de abrirlo para leer datos (V2.5 §15, ADR-V25-15; defecto
 * HIGH «ZIP bomb»: un XLSX de 680 KB consumía 23 s de CPU dentro de la petición de subida).
 *
 * Solo lee metadatos (ZipArchive::statIndex) y, como mucho, los 4 primeros bytes de las entradas no XML para detectar archivos
 * anidados: cuesta milisegundos aunque el contenido descomprimido sea de gigabytes. Comprueba, en este orden:
 * firma y estructura del ZIP; nº de entradas; por entrada: nombre seguro, sin cifrado, sin archivos anidados, tamaño
 * descomprimido, tope de cadenas compartidas y razón descomprimido/comprimido; coherencia (la suma de tamaños comprimidos no
 * puede superar el archivo: entradas solapadas); suma de tamaños descomprimidos; razón global; partes mínimas de un libro.
 *
 * Los límites vienen de config('upload_safety.xlsx') (valores por defecto en DEFAULTS).
 */
final class ZipArchiveInspector
{
    /** Valores por defecto: ver config/upload_safety.php para su justificación. */
    public const DEFAULTS = [
        'max_entries' => 1000,
        'max_entry_bytes' => 536_870_912,
        'max_total_bytes' => 1_073_741_824,
        'max_shared_strings_bytes' => 33_554_432,
        'max_compression_ratio' => 100,
        'ratio_min_bytes' => 5_242_880,
        'max_columns' => 500,
        'analysis_rows' => 25,
        'analysis_max_scanned_rows' => 20_000,
        'analysis_timeout_seconds' => 10.0,
    ];

    /** Extensiones de contenedores que no deben ir dentro de un libro de datos. */
    private const NESTED_EXTENSIONS = ['zip', 'jar', 'war', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'tar', 'xlsx', 'xlsm', 'xlsb', 'docx', 'docm', 'pptx', 'odt', 'ods'];

    /** Partes XML cuyo contenido ya se lee con un analizador XML: no se comprueba su firma. */
    private const XML_EXTENSIONS = ['xml', 'rels', 'vml'];

    /** @param array<string, int|float> $limits sobrescrituras de DEFAULTS */
    public function __construct(private readonly array $limits = []) {}

    public static function fromConfig(): self
    {
        return new self(self::configured());
    }

    /** Límites de config('upload_safety.xlsx'); fuera de Laravel (scripts) rigen los valores por defecto. @return array<string, int|float> */
    public static function configured(): array
    {
        try {
            return (array) config('upload_safety.xlsx', []);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, int|float> los límites efectivos */
    public function limits(): array
    {
        return array_merge(self::DEFAULTS, $this->limits);
    }

    /** @throws UnsafeSpreadsheetException */
    public function inspect(string $path): ZipInspection
    {
        $limits = $this->limits();
        $fileSize = is_file($path) ? (int) filesize($path) : 0;
        if ($fileSize < 22 || ! $this->hasZipSignature($path)) {   // 22 bytes = registro de fin de directorio vacío
            throw $this->invalid();
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw $this->invalid();
        }
        try {
            return $this->inspectOpened($zip, $fileSize, $limits);
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, int|float> $limits */
    private function inspectOpened(ZipArchive $zip, int $fileSize, array $limits): ZipInspection
    {
        $count = $zip->numFiles;
        if ($count > $limits['max_entries']) {
            throw new UnsafeSpreadsheetException(
                "El libro de Excel contiene {$count} archivos internos (máximo permitido: {$limits['max_entries']}). Expórtelo de nuevo desde Excel o use CSV.",
                UnsafeSpreadsheetException::TOO_MANY_ENTRIES, 422, ['entries' => $count, 'max_entries' => (int) $limits['max_entries']],
            );
        }

        $names = [];
        $compressed = 0;
        $uncompressed = 0;
        $maxRatio = 0.0;
        $sharedStrings = 0;
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw $this->invalid();
            }
            $name = (string) $stat['name'];
            $size = (int) $stat['size'];
            $packed = (int) $stat['comp_size'];
            $this->assertSafeName($name, $names);
            $names[$name] = true;
            if (str_ends_with($name, '/')) {
                continue;   // directorio
            }
            if (($stat['encryption_method'] ?? 0) !== 0) {
                throw new UnsafeSpreadsheetException('El libro de Excel está cifrado o protegido con contraseña: quite la protección y vuelva a subirlo.', UnsafeSpreadsheetException::ENCRYPTED, 422);
            }
            $this->assertNotNested($zip, $name);

            if ($size > $limits['max_entry_bytes']) {
                throw $this->tooLarge($size, (int) $limits['max_entry_bytes'], 'una parte del libro');
            }
            if ($name === 'xl/sharedStrings.xml') {
                $sharedStrings = $size;
                if ($size > $limits['max_shared_strings_bytes']) {
                    throw $this->tooLarge($size, (int) $limits['max_shared_strings_bytes'], 'las cadenas de texto compartidas del libro');
                }
            }
            if ($size >= $limits['ratio_min_bytes']) {
                $ratio = $packed > 0 ? $size / $packed : INF;
                $maxRatio = max($maxRatio, $ratio);
                if ($ratio > $limits['max_compression_ratio']) {
                    throw $this->ratio($ratio, (int) $limits['max_compression_ratio']);
                }
            }
            $compressed += $packed;
            $uncompressed += $size;
        }

        // Entradas que apuntan a los mismos datos (bomba solapada): lo comprimido declarado no cabe en el archivo.
        if ($compressed > $fileSize) {
            throw $this->invalid();
        }
        if ($uncompressed > $limits['max_total_bytes']) {
            throw $this->tooLarge($uncompressed, (int) $limits['max_total_bytes'], 'todo el libro');
        }
        if ($uncompressed >= $limits['ratio_min_bytes']) {
            $ratio = $uncompressed / max(1, $fileSize);
            $maxRatio = max($maxRatio, $ratio);
            if ($ratio > $limits['max_compression_ratio']) {
                throw $this->ratio($ratio, (int) $limits['max_compression_ratio']);
            }
        }
        if (! isset($names['[Content_Types].xml'], $names['xl/workbook.xml'])) {
            throw $this->invalid();   // es un ZIP, pero no un libro de Excel
        }

        return new ZipInspection($count, $fileSize, $uncompressed, $maxRatio === INF ? PHP_FLOAT_MAX : $maxRatio, $sharedStrings);
    }

    private function hasZipSignature(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $magic = (string) fread($handle, 4);
        fclose($handle);

        return $magic === "PK\x03\x04" || $magic === "PK\x05\x06";
    }

    /** @param array<string, true> $seen */
    private function assertSafeName(string $name, array $seen): void
    {
        $segments = preg_split('#[/\\\\]#', $name) ?: [];
        if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || in_array('..', $segments, true) || isset($seen[$name])) {
            throw $this->invalid();   // nombre vacío, con NUL, absoluto, con «..» o repetido
        }
    }

    private function assertNotNested(ZipArchive $zip, string $name): void
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $nested = in_array($extension, self::NESTED_EXTENSIONS, true);
        if (! $nested && ! in_array($extension, self::XML_EXTENSIONS, true)) {
            $stream = $zip->getStream($name);
            if (is_resource($stream)) {
                $magic = (string) fread($stream, 4);   // solo se descomprimen los primeros bytes
                fclose($stream);
                $nested = str_starts_with($magic, "PK\x03\x04") || str_starts_with($magic, "PK\x05\x06") || str_starts_with($magic, "\x1f\x8b")
                    || str_starts_with($magic, 'Rar!') || str_starts_with($magic, "7z\xbc\xaf");
            }
        }
        if ($nested) {
            throw new UnsafeSpreadsheetException('El libro de Excel contiene archivos comprimidos anidados, que no se admiten. Expórtelo de nuevo desde Excel o use CSV.', UnsafeSpreadsheetException::NESTED_ARCHIVE, 422);
        }
    }

    private function invalid(): UnsafeSpreadsheetException
    {
        return new UnsafeSpreadsheetException('El archivo no es un libro de Excel (.xlsx) válido o está dañado. Expórtelo de nuevo desde Excel o use CSV.', UnsafeSpreadsheetException::INVALID_ARCHIVE, 422);
    }

    private function tooLarge(int $bytes, int $max, string $what): UnsafeSpreadsheetException
    {
        return new UnsafeSpreadsheetException(
            sprintf('El tamaño descomprimido de %s (%s MB) supera el máximo permitido (%s MB). Divida el archivo, quite hojas o columnas innecesarias, o use CSV.', $what, $this->mb($bytes), $this->mb($max)),
            UnsafeSpreadsheetException::UNCOMPRESSED_TOO_LARGE, 413, ['bytes' => $bytes, 'max_bytes' => $max],
        );
    }

    private function ratio(float $ratio, int $max): UnsafeSpreadsheetException
    {
        return new UnsafeSpreadsheetException(
            sprintf('El libro de Excel tiene una razón de compresión anómala (%s:1, máximo %d:1): podría ser un archivo malicioso. Expórtelo de nuevo desde Excel o use CSV.', is_finite($ratio) ? number_format($ratio, 0, ',', '.') : 'infinita', $max),
            UnsafeSpreadsheetException::COMPRESSION_RATIO, 413, ['max_ratio' => $max],
        );
    }

    private function mb(int $bytes): string
    {
        return number_format($bytes / 1_048_576, $bytes < 10 * 1_048_576 ? 1 : 0, ',', '.');
    }
}
