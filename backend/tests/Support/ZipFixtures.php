<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Generador de ZIP/XLSX para las pruebas de endurecimiento de cargas. Escribe el ZIP en streaming (deflate por bloques), así
 * una «bomba» de cientos de MB descomprimidos ocupa unos pocos KB y se genera en menos de un segundo sin memoria.
 *
 * Una entrada es: string (contenido), ['repeat' => 'a', 'bytes' => N] (N bytes de un patrón repetido), ['random' => N]
 * (N bytes pseudoaleatorios incompresibles), ['chunks' => iterable<string>] (contenido por bloques) o ['stored' => string]
 * (sin comprimir). Las entradas pueden pasarse como mapa nombre => spec o como lista de pares [nombre, spec] (nombres repetidos).
 *
 * Directorio central FALSIFICADO: cualquier spec en forma de arreglo admite `declared_size`, `declared_packed` y `declared_crc`,
 * que se escriben en el directorio central (y en la cabecera local) en lugar de los valores reales, como haría un atacante
 * («256 KB que declaran 2 KB descomprimidos y contienen 100 MB»). Las cadenas se pasan como ['content' => $texto, ...].
 */
final class ZipFixtures
{
    private const CHUNK = 1_048_576;

    /** @param array<array-key, mixed> $entries */
    public static function write(string $path, array $entries): string
    {
        $out = fopen($path, 'wb');
        $central = '';
        $offset = 0;
        $count = 0;
        foreach (self::pairs($entries) as [$name, $spec]) {
            [$data, $size, $crc, $method] = self::compress($spec);
            $packed = strlen($data);
            if (is_array($spec)) {   // directorio falsificado: lo declarado difiere de lo real
                $size = (int) ($spec['declared_size'] ?? $size);
                $packed = (int) ($spec['declared_packed'] ?? $packed);
                $crc = (int) ($spec['declared_crc'] ?? $crc);
            }
            $local = pack('VvvvvvVVVvv', 0x04034B50, 20, 0, $method, 0, 0x21, $crc, $packed, $size, strlen($name), 0).$name;
            fwrite($out, $local.$data);
            $central .= self::centralRecord($name, $method, $crc, $packed, $size, $offset);
            $offset += strlen($local) + strlen($data);
            $count++;
        }
        self::finish($out, $central, $count, $offset);

        return $path;
    }

    /**
     * ZIP con entradas «solapadas»: un solo bloque de datos referenciado por $copies registros del directorio central, cada uno
     * declarando el tamaño completo (la suma de tamaños comprimidos declarados excede con mucho al archivo).
     */
    public static function overlapping(string $path, int $copies, int $blockBytes = 500_000): string
    {
        $out = fopen($path, 'wb');
        $block = random_bytes($blockBytes);
        $crc = crc32($block);
        $local = pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, 0x21, $crc, $blockBytes, $blockBytes, 11, 0).'xl/block.bin';
        fwrite($out, $local.$block);
        $central = '';
        for ($i = 0; $i < $copies; $i++) {
            $central .= self::centralRecord("xl/part{$i}.bin", 0, $crc, $blockBytes, $blockBytes, 0);
        }
        self::finish($out, $central, $copies, strlen($local) + $blockBytes);

        return $path;
    }

    /**
     * Partes mínimas de un libro XLSX legible (una hoja con cadenas «inline»).
     *
     * @param  array<int, list<string|int|float|null>>|null  $rows  fila (1-based) => celdas; null = encabezado + una fila de datos
     * @param  array<string, mixed>  $extra  entradas adicionales o sustitutas
     * @return array<string, mixed>
     */
    public static function workbook(?array $rows = null, array $extra = []): array
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

        return array_merge([
            '[Content_Types].xml' => $xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => $xml.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Hoja1" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => self::sheetXml($rows ?? [1 => ['Nro Factura', 'Cantidad'], 2 => ['A-1', 3]]),
        ], $extra);
    }

    /**
     * Libro con cadenas compartidas en la parte $part (el destino de la relación puede ser cualquier nombre: OpenSpout lo resuelve
     * por workbook.xml.rels). $spec es el contenido de esa parte (cualquier spec de entrada).
     *
     * @param  array<int, list<string|int|float|null>>|null  $rows
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function workbookWithSharedStrings(string $part, mixed $spec, ?array $rows = null, array $extra = []): array
    {
        $target = str_starts_with($part, 'xl/') ? substr($part, 3) : $part;
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="'.$target.'"/></Relationships>';

        return self::workbook($rows, array_merge(['xl/_rels/workbook.xml.rels' => $rels, $part => $spec], $extra));
    }

    /** Cabecera y cierre de una cadena compartida mínima para llenar `$part` de entradas `<si>`. */
    public static function sharedStringsOpen(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    }

    /**
     * Cadenas compartidas honestas de ≈ $bytes: entradas `<si><t>palabraN</t></si>` elegidas al azar entre 64 (razón ≈ 35:1).
     *
     * @return \Generator<int, string>
     */
    public static function sharedStringsOfSize(int $bytes): \Generator
    {
        mt_srand(11);
        $block = '';
        while (strlen($block) < 262_144) {
            $block .= '<si><t>palabra'.mt_rand(0, 63).'</t></si>';
        }
        yield self::sharedStringsOpen();
        for ($left = $bytes; $left > 0; $left -= strlen($block)) {
            yield $block;
        }
        yield '</sst>';
    }

    /**
     * Relleno XML «honesto»: nodos `<x n="k"/>` elegidos al azar entre 4 variantes (razón de compresión ≈ 25:1, por debajo del
     * tope de 100:1), como los que un libro legítimo podría traer antes de la primera fila. Genera un bloque de 1 MB y lo repite.
     *
     * @return \Generator<int, string>
     */
    public static function noise(int $bytes, string $tag = 'x'): \Generator
    {
        mt_srand(7);
        $units = [];
        for ($k = 0; $k < 4; $k++) {
            $units[] = "<{$tag} n=\"{$k}\"/>";
        }
        $block = '';
        while (strlen($block) < self::CHUNK) {
            $block .= $units[mt_rand(0, 3)];
        }
        for ($left = $bytes; $left > 0; $left -= strlen($block)) {
            if ($left >= strlen($block)) {
                yield $block;

                continue;
            }
            $piece = substr($block, 0, $left);   // el último tramo termina en un nodo completo para que el XML siga siendo válido
            $end = strrpos($piece, '/>');
            yield $end === false ? '' : substr($piece, 0, $end + 2);
        }
    }

    /**
     * Hoja por tramos: filas (`['rows' => [n => celdas]]`) y relleno honesto (`['noise' => bytes]`) en el orden dado, dentro de
     * `<sheetData>`. `$beforeSheetData` añade ese relleno ANTES de `<sheetData>` (donde OpenSpout lee el encabezado de la hoja).
     *
     * @param  list<array{rows?: array<int, list<string|int|float|null>>, noise?: int}>  $parts
     * @return array{chunks: \Generator<int, string>}
     */
    public static function sheetParts(array $parts, int $beforeSheetData = 0): array
    {
        $chunks = (function () use ($parts, $beforeSheetData) {
            yield '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
            if ($beforeSheetData > 0) {
                yield from self::noise($beforeSheetData, 'ext');
            }
            yield '<sheetData>';
            foreach ($parts as $part) {
                if (isset($part['noise'])) {
                    yield from self::noise((int) $part['noise']);
                }
                foreach ($part['rows'] ?? [] as $number => $cells) {
                    yield self::rowXml((int) $number, $cells);
                }
            }
            yield '</sheetData></worksheet>';
        })();

        return ['chunks' => $chunks];
    }

    /** @param array<int, list<string|int|float|null>> $rows fila (1-based) => celdas */
    public static function sheetXml(array $rows): string
    {
        $body = '';
        foreach ($rows as $number => $cells) {
            $body .= self::rowXml($number, $cells);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$body.'</sheetData></worksheet>';
    }

    /** @param list<string|int|float|null> $cells */
    public static function rowXml(int $number, array $cells): string
    {
        $xml = "<row r=\"{$number}\">";
        foreach ($cells as $i => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $ref = self::column($i).$number;
            $xml .= is_string($value)
                ? "<c r=\"{$ref}\" t=\"inlineStr\"><is><t>".htmlspecialchars($value, ENT_XML1).'</t></is></c>'
                : "<c r=\"{$ref}\"><v>{$value}</v></c>";
        }

        return $xml.'</row>';
    }

    public static function column(int $index): string
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /** @return list<array{string, mixed}> */
    private static function pairs(array $entries): array
    {
        if (array_is_list($entries)) {
            return array_map(fn ($pair) => [(string) $pair[0], $pair[1]], $entries);
        }
        $pairs = [];
        foreach ($entries as $name => $spec) {
            $pairs[] = [(string) $name, $spec];
        }

        return $pairs;
    }

    /** @return array{string, int, int, int} [datos, tamaño original, crc32, método] */
    private static function compress(mixed $spec): array
    {
        if (is_array($spec) && isset($spec['stored'])) {
            return [(string) $spec['stored'], strlen((string) $spec['stored']), crc32((string) $spec['stored']), 0];
        }
        $chunks = match (true) {
            is_string($spec) => [$spec],
            isset($spec['content']) => [(string) $spec['content']],
            isset($spec['repeat']) => self::repeated((string) $spec['repeat'], (int) $spec['bytes']),
            isset($spec['random']) => [random_bytes((int) $spec['random'])],
            isset($spec['chunks']) => $spec['chunks'],
            default => throw new \InvalidArgumentException('Entrada de ZIP no reconocida.'),
        };
        $context = deflate_init(ZLIB_ENCODING_RAW, ['level' => 1]);
        $hash = hash_init('crc32b');
        $data = '';
        $size = 0;
        foreach ($chunks as $chunk) {
            $data .= deflate_add($context, $chunk, ZLIB_NO_FLUSH);
            hash_update($hash, $chunk);
            $size += strlen($chunk);
        }
        $data .= deflate_add($context, '', ZLIB_FINISH);

        return [$data, $size, (int) hexdec(hash_final($hash)), 8];
    }

    /** @return \Generator<int, string> */
    private static function repeated(string $pattern, int $bytes): \Generator
    {
        $block = str_repeat($pattern, intdiv(self::CHUNK, max(1, strlen($pattern))));
        for ($left = $bytes; $left > 0; $left -= strlen($block)) {
            yield $left >= strlen($block) ? $block : substr($block, 0, $left);
        }
    }

    private static function centralRecord(string $name, int $method, int $crc, int $packed, int $size, int $offset): string
    {
        return pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, $method, 0, 0x21, $crc, $packed, $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
    }

    /** @param resource $out */
    private static function finish($out, string $central, int $count, int $offset): void
    {
        fwrite($out, $central.pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, strlen($central), $offset, 0));
        fclose($out);
    }
}
