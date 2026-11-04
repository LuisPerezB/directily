<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use Generator;

/**
 * Lectura EN FLUJO y acotada de un lote Bridge (NDJSON UTF-8 comprimido con gzip). La usan la recepción (que valida TODAS las líneas ANTES de guardar nada en
 * Bronze) y el procesador (defensa en profundidad). Límites (config/bridge.php), todos con mensajes que nunca citan valores del lote:
 *  - magia gzip `1f 8b 08` (B-10): un archivo que no es gzip no llega a `gzopen` (que aceptaría texto plano);
 *  - filas (`max_batch_rows`), bytes descomprimidos (`max_decompressed_mb`) y RELACIÓN de descompresión (`max_decompression_ratio`, B-6) frente a bombas de
 *    compresión: pequeño comprimido, enorme descomprimido;
 *  - longitud de línea (`max_line_bytes`) y de cada valor de texto (`max_value_length`);
 *  - cada línea es un objeto JSON y sus valores son ESCALARES o null (un arreglo u objeto anidado no es un dato de negocio del importador).
 */
final class BridgeBatchReader
{
    /** Por debajo de este tamaño descomprimido no se aplica la relación (un lote pequeño y repetitivo no es una bomba). */
    private const RATIO_FLOOR_BYTES = 8 * 1024 * 1024;

    /** @throws BridgeBatchInvalid */
    public static function assertGzip(string $path): void
    {
        $head = (string) @file_get_contents($path, false, null, 0, 3);
        if (strlen($head) < 3 || $head[0] !== "\x1f" || $head[1] !== "\x8b" || $head[2] !== "\x08") {
            throw new BridgeBatchInvalid('El lote no es un archivo gzip (faltan los bytes 1f 8b 08).', 'batch_invalid');
        }
    }

    /**
     * @return Generator<int, array<string, mixed>> número de línea (1…) => fila
     *
     * @throws BridgeBatchInvalid
     */
    public static function rows(string $path, int $compressedBytes): Generator
    {
        self::assertGzip($path);
        $handle = @gzopen($path, 'rb');
        if ($handle === false) {
            throw new BridgeBatchInvalid('El lote no es un gzip legible.', 'batch_invalid');
        }
        $maxRows = (int) config('bridge.max_batch_rows', 50_000);
        $maxBytes = (int) config('bridge.max_decompressed_mb', 512) * 1024 * 1024;
        $maxRatio = max(1, (int) config('bridge.max_decompression_ratio', 200));
        $maxLine = max(1024, (int) config('bridge.max_line_bytes', 262_144));
        $maxValue = max(16, (int) config('bridge.max_value_length', 2000));
        $bytes = 0;
        $number = 0;
        try {
            while (($line = gzgets($handle, $maxLine + 1)) !== false) {
                $length = strlen($line);
                $bytes += $length;
                if ($bytes > $maxBytes) {
                    throw new BridgeBatchInvalid('El lote supera el tamaño descomprimido máximo permitido.', 'batch_too_large');
                }
                if ($bytes > self::RATIO_FLOOR_BYTES && $bytes > $maxRatio * max(1, $compressedBytes)) {
                    throw new BridgeBatchInvalid('El lote supera la relación de descompresión permitida (posible bomba de compresión).', 'batch_too_large');
                }
                // gzgets devuelve a lo sumo `$maxLine` bytes: si llegó al tope sin fin de línea y el archivo continúa, la línea es demasiado larga.
                if ($length >= $maxLine && ! str_ends_with($line, "\n") && ! gzeof($handle)) {
                    throw new BridgeBatchInvalid('Una línea del lote supera la longitud máxima permitida.', 'invalid_row');
                }
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }
                $number++;
                if ($number > $maxRows) {
                    throw new BridgeBatchInvalid('El lote supera el máximo de filas permitido.', 'batch_too_large');
                }
                $row = json_decode($line, true, 4);
                if (! is_array($row) || array_is_list($row) || $row === []) {
                    throw new BridgeBatchInvalid("La línea {$number} del lote no es un objeto JSON.", 'invalid_row');
                }
                foreach ($row as $column => $value) {
                    if (is_array($value)) {
                        throw new BridgeBatchInvalid("La línea {$number} trae un valor no escalar (arreglo u objeto) en la columna «".mb_substr((string) $column, 0, 40).'».', 'invalid_row');
                    }
                    if (is_string($value) && mb_strlen($value) > $maxValue) {
                        throw new BridgeBatchInvalid("La línea {$number} trae un texto de más de {$maxValue} caracteres en la columna «".mb_substr((string) $column, 0, 40).'».', 'invalid_row');
                    }
                }
                yield $number => $row;
            }
        } finally {
            gzclose($handle);
        }
    }
}
