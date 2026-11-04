<?php

declare(strict_types=1);

namespace Tests\Unit\Bridge;

use App\Services\Bridge\BridgeBatchInvalid;
use App\Services\Bridge\BridgeBatchReader;
use Tests\TestCase;

/** W6-B · Lectura en flujo y acotada de un lote: cada límite, con su código y su mensaje (sin citar valores del lote). */
final class BridgeBatchReaderTest extends TestCase
{
    private function gz(string $ndjson): string
    {
        $path = tempnam(sys_get_temp_dir(), 'brd-');
        file_put_contents($path, gzencode($ndjson, 6));

        return $path;
    }

    /** @return array{0: ?string, 1: ?string, 2: int} código, mensaje y filas leídas */
    private function read(string $ndjson): array
    {
        $path = $this->gz($ndjson);
        $rows = 0;
        try {
            foreach (BridgeBatchReader::rows($path, (int) filesize($path)) as $row) {
                $rows++;
            }
        } catch (BridgeBatchInvalid $e) {
            return [$e->errorCode, $e->getMessage(), $rows];
        } finally {
            @unlink($path);
        }

        return [null, null, $rows];
    }

    public function test_valid_ndjson_is_read_row_by_row_with_blank_lines_skipped(): void
    {
        $this->assertSame([null, null, 3], $this->read("{\"a\":1}\n\n{\"a\":2,\"b\":null}\r\n{\"a\":\"x\"}"));
    }

    public function test_row_cap_stops_reading_with_its_own_message(): void
    {
        config(['bridge.max_batch_rows' => 3]);
        [$code, $message, $rows] = $this->read(str_repeat("{\"a\":1}\n", 10));
        $this->assertSame('batch_too_large', $code);
        $this->assertStringContainsString('máximo de filas', (string) $message);
        $this->assertSame(3, $rows, 'se detiene al pasar el tope: no lee el resto');
    }

    public function test_line_length_cap_has_its_own_message_and_is_not_a_json_error(): void
    {
        config(['bridge.max_line_bytes' => 1024, 'bridge.max_value_length' => 100_000]);
        [$code, $message] = $this->read(json_encode(['a' => str_repeat('x', 3000)])."\n");
        $this->assertSame('invalid_row', $code);
        $this->assertStringContainsString('longitud máxima', (string) $message);
        // Una línea justo dentro del tope sí pasa.
        [$code] = $this->read(json_encode(['a' => str_repeat('x', 900)])."\n");
        $this->assertNull($code);
    }

    public function test_value_and_scalar_rules_and_gzip_magic(): void
    {
        [$code, $message] = $this->read(json_encode(['a' => ['b' => 1]])."\n");
        $this->assertSame('invalid_row', $code);
        $this->assertStringContainsString('no escalar', (string) $message);
        config(['bridge.max_value_length' => 50]);
        [$code, $message] = $this->read(json_encode(['a' => str_repeat('y', 51)])."\n");
        $this->assertSame('invalid_row', $code);
        $this->assertStringContainsString('más de 50 caracteres', (string) $message);
        $this->assertStringNotContainsString('yyyy', (string) $message, 'el mensaje nunca cita valores del lote');

        $plain = tempnam(sys_get_temp_dir(), 'brd-');
        file_put_contents($plain, "{\"a\":1}\n");
        $this->expectException(BridgeBatchInvalid::class);
        $this->expectExceptionMessage('1f 8b 08');
        try {
            iterator_to_array(BridgeBatchReader::rows($plain, 8));
        } finally {
            @unlink($plain);
        }
    }

    public function test_decompressed_bytes_cap_and_ratio_cap_have_distinct_messages(): void
    {
        config(['bridge.max_decompressed_mb' => 1, 'bridge.max_decompression_ratio' => 100_000]);
        $line = json_encode(['a' => str_repeat('z', 1900)])."\n";
        [$code, $message] = $this->read(str_repeat($line, 700));   // ≈ 1,3 MB descomprimidos
        $this->assertSame('batch_too_large', $code);
        $this->assertStringContainsString('tamaño descomprimido', (string) $message);

        config(['bridge.max_decompressed_mb' => 512, 'bridge.max_decompression_ratio' => 50]);
        [$code, $message] = $this->read(str_repeat($line, 4500));   // > 8 MB y altísima relación
        $this->assertSame('batch_too_large', $code);
        $this->assertStringContainsString('relación de descompresión', (string) $message);
    }
}
