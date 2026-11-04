<?php

declare(strict_types=1);

namespace App\Services\Imports\Reading;

use XMLReader;
use ZipArchive;

/**
 * Inspección de un ZIP (un XLSX lo es) ANTES de abrirlo para leer datos (V2.5 §15, ADR-V25-15; defecto HIGH «ZIP bomb»: un XLSX
 * de 680 KB consumía 23 s de CPU dentro de la petición de subida).
 *
 * Dos etapas. NO se confía en los tamaños del directorio central: un atacante controla esos campos.
 *
 * 1. Metadatos (milisegundos): firma y estructura del ZIP; nº de entradas; por entrada: nombre seguro, sin cifrado, sin
 *    archivos anidados; partes de control (workbook.xml y sus relaciones) leídas con tope y contadas; resolución de qué parte es
 *    cada cosa (hojas, cadenas compartidas, estilos) POR RELACIÓN, igual que el lector, no por nombre literal; tamaño
 *    descomprimido declarado por entrada, tope de cadenas compartidas y estilos, razón descomprimido/comprimido; coherencia
 *    (la suma de tamaños comprimidos no puede superar el archivo: entradas solapadas); suma y razón globales.
 * 2. Verificación real: cada parte se descomprime por stream CONTANDO los bytes realmente producidos; si superan lo declarado
 *    (o no llegan a ello) el libro se rechaza (`xlsx_inconsistent_entry`) sin pasar a OpenSpout. Como cada parte se lee hasta el
 *    final, libzip también valida su CRC (con zlib, sin coste apreciable): un CRC falsificado se rechaza igual. Descomprimir
 *    cuesta ≈ 1,5 GB/s en datos muy repetitivos: ≈ 0,1 s para el histórico real (63 MB de XML) y ≤ ≈ 1 s en el peor caso
 *    permitido (suma ≤ `max_total_bytes`). (Calcular el CRC en PHP con hash('crc32b') costaría ≈ 8 veces más que descomprimir.)
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
        'max_control_part_bytes' => 8_388_608,
        'max_compression_ratio' => 100,
        'ratio_min_bytes' => 5_242_880,
        'max_columns' => 500,
        'analysis_rows' => 25,
        'analysis_max_scanned_rows' => 20_000,
        'analysis_max_sheet_bytes' => 8_388_608,
        'analysis_timeout_seconds' => 10.0,
    ];

    private const NS_RELATIONSHIPS = ['http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'http://purl.oclc.org/ooxml/officeDocument/relationships'];

    private const REL_SHARED_STRINGS = ['http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings', 'http://purl.oclc.org/ooxml/officeDocument/relationships/sharedStrings'];

    private const REL_STYLES = ['http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles', 'http://purl.oclc.org/ooxml/officeDocument/relationships/styles'];

    private const WORKBOOK = 'xl/workbook.xml';

    private const WORKBOOK_RELS = 'xl/_rels/workbook.xml.rels';

    /** Tamaño de cada lectura al verificar (el mismo ritmo que la descompresión de libzip). */
    private const READ_CHUNK = 1_048_576;

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

        // 1. Directorio central: estructura y nombres.
        $entries = $this->readDirectory($zip, $count);
        foreach ([self::WORKBOOK, self::WORKBOOK_RELS, '[Content_Types].xml'] as $required) {
            if (! isset($entries[$required]) || $entries[$required]['dir']) {
                throw $this->invalid();   // es un ZIP, pero no un libro de Excel
            }
        }

        // 2. Partes de control (workbook.xml y sus relaciones): se leen aquí, con tope y recuento real, para saber qué es cada parte.
        $verified = [];
        $workbook = $this->readPart($zip, self::WORKBOOK, $entries[self::WORKBOOK], (int) $limits['max_control_part_bytes'], true);
        $relationships = $this->readPart($zip, self::WORKBOOK_RELS, $entries[self::WORKBOOK_RELS], (int) $limits['max_control_part_bytes'], true);
        $verified[self::WORKBOOK] = $verified[self::WORKBOOK_RELS] = true;
        $roles = $this->resolveRoles($workbook, $relationships);

        // 3. Límites sobre los tamaños declarados (cuestan milisegundos y descartan las bombas evidentes sin descomprimir nada).
        $capped = ['xl/sharedStrings.xml' => 'las cadenas de texto compartidas del libro', 'xl/styles.xml' => 'los estilos del libro'];
        if ($roles['shared_strings'] !== null) {
            $capped[$roles['shared_strings']] = 'las cadenas de texto compartidas del libro';
        }
        if ($roles['styles'] !== null) {
            $capped[$roles['styles']] = 'los estilos del libro';
        }
        $compressed = 0;
        $uncompressed = 0;
        $maxRatio = 0.0;
        $sharedStrings = 0;
        $entrySizes = [];
        foreach ($entries as $name => $entry) {
            if ($entry['dir']) {
                continue;
            }
            $size = $entry['size'];
            $packed = $entry['packed'];
            $entrySizes[$name] = $size;
            if ($size > $limits['max_entry_bytes']) {
                throw $this->tooLarge($size, (int) $limits['max_entry_bytes'], 'una parte del libro');
            }
            if (isset($capped[$name])) {
                if ($name === $roles['shared_strings'] || $name === 'xl/sharedStrings.xml') {
                    $sharedStrings = max($sharedStrings, $size);
                }
                if ($size > $limits['max_shared_strings_bytes']) {
                    throw $this->tooLarge($size, (int) $limits['max_shared_strings_bytes'], $capped[$name]);
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

        // 4. Verificación real: lo declarado ya cabe en los límites; ahora se comprueba que es verdad. El coste está acotado por
        //    la suma declarada (≤ max_total_bytes) y cada lectura se corta al pasar de lo declarado en esa parte.
        foreach ($entries as $name => $entry) {
            if ($entry['dir'] || isset($verified[$name])) {
                continue;
            }
            $this->readPart($zip, $name, $entry, 0, false);
        }

        return new ZipInspection(
            $count, $fileSize, $uncompressed, $maxRatio === INF ? PHP_FLOAT_MAX : $maxRatio, $sharedStrings,
            $entrySizes, $roles['sheets'], $roles['shared_strings'], $roles['styles'],
        );
    }

    /**
     * Estructura del directorio central: nombres seguros y únicos, sin cifrado, sin contenedores anidados.
     *
     * @return array<string, array{index: int, size: int, packed: int, crc: int, dir: bool}>
     */
    private function readDirectory(ZipArchive $zip, int $count): array
    {
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw $this->invalid();
            }
            $name = (string) $stat['name'];
            $this->assertSafeName($name, $entries);
            $dir = str_ends_with($name, '/');
            $entries[$name] = ['index' => $i, 'size' => (int) $stat['size'], 'packed' => (int) $stat['comp_size'], 'crc' => (int) $stat['crc'], 'dir' => $dir];
            if ($dir) {
                continue;
            }
            if (($stat['encryption_method'] ?? 0) !== 0) {
                throw new UnsafeSpreadsheetException('El libro de Excel está cifrado o protegido con contraseña: quite la protección y vuelva a subirlo.', UnsafeSpreadsheetException::ENCRYPTED, 422);
            }
            $this->assertNotNested($zip, $name);
        }

        return $entries;
    }

    /**
     * Descomprime una parte por stream contando los bytes REALMENTE producidos (el directorio central no es de fiar). Aborta en
     * cuanto superan lo declarado y, al terminar, exige que coincidan. Un error de lectura (datos corruptos o CRC distinto del
     * declarado: libzip lo comprueba al llegar al final) se trata igual: el contenido no es el que declara el directorio.
     *
     * @param  array{index: int, size: int, packed: int, crc: int, dir: bool}  $entry
     * @param  int  $maxKeep  tope de la parte si se devuelve su contenido (partes de control)
     * @return string el contenido si $keep; si no, cadena vacía
     */
    private function readPart(ZipArchive $zip, string $name, array $entry, int $maxKeep, bool $keep): string
    {
        $declared = $entry['size'];
        if ($keep && $declared > $maxKeep) {
            throw $this->tooLarge($declared, $maxKeep, 'una parte del libro');
        }
        $stream = $zip->getStream($name);
        if (! is_resource($stream)) {
            throw $this->invalid();
        }
        $content = '';
        $real = 0;
        try {
            while (true) {
                $chunk = @fread($stream, max(1, min(self::READ_CHUNK, $declared - $real + 1)));   // un byte más de lo declarado delata la mentira
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    throw $this->inconsistent($name, $declared);   // error de descompresión o CRC distinto del declarado
                }
                if ($chunk === '') {
                    break;
                }
                $real += strlen($chunk);
                if ($real > $declared) {
                    throw $this->inconsistent($name, $declared);
                }
                if ($keep) {
                    $content .= $chunk;
                }
            }
        } finally {
            fclose($stream);
        }
        if ($real !== $declared) {
            throw $this->inconsistent($name, $declared);
        }

        return $content;
    }

    /**
     * Qué parte es cada cosa, resuelto como lo hace OpenSpout (WorkbookRelationshipsManager y SheetManager): hojas por
     * `<sheet r:id>` de workbook.xml → Target de workbook.xml.rels (con «xl/» por delante si no lo trae); cadenas compartidas y
     * estilos por el Type de la relación (gana la última). Un nombre distinto del habitual es perfectamente válido para el
     * lector, así que los límites se aplican al destino resuelto y no al nombre literal.
     *
     * @return array{sheets: list<string>, shared_strings: ?string, styles: ?string}
     */
    private function resolveRoles(string $workbookXml, string $relationshipsXml): array
    {
        $ids = [];
        $byType = [];
        foreach ($this->elements($relationshipsXml, 'Relationship') as $element) {
            $target = $element['Target'] ?? null;
            if ($target === null) {
                continue;
            }
            if (isset($element['Id'])) {
                $ids[$element['Id']][] = $target;
            }
            if (isset($element['Type'])) {
                $byType[$element['Type']] = $target;
            }
        }

        $sheets = [];
        foreach ($this->elements($workbookXml, 'sheet') as $element) {
            foreach ($ids[$element['r:id'] ?? $element['@rid'] ?? ''] ?? [] as $target) {
                $path = str_starts_with($target, '/xl/') ? $target : '/xl/'.$target;
                $sheets[ltrim($path, '/')] = true;
            }
        }

        $resolve = function (array $types) use ($byType): ?string {
            foreach ($types as $type) {
                if (isset($byType[$type])) {
                    $target = $byType[$type];

                    return ltrim(str_contains($target, 'xl/') ? $target : 'xl/'.$target, '/');
                }
            }

            return null;
        };

        return ['sheets' => array_keys($sheets), 'shared_strings' => $resolve(self::REL_SHARED_STRINGS), 'styles' => $resolve(self::REL_STYLES)];
    }

    /**
     * Elementos de nombre local dado con sus atributos (los de relaciones, también como «@rid»). XML mal formado: archivo
     * inválido. Sin entidades externas ni red (LIBXML_NONET); la parte ya está acotada por max_control_part_bytes.
     *
     * @return list<array<string, string>>
     */
    private function elements(string $xml, string $localName): array
    {
        $previous = libxml_use_internal_errors(true);
        $reader = new XMLReader;
        $out = [];
        try {
            if (! $reader->XML($xml, null, LIBXML_NONET)) {
                throw $this->invalid();
            }
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== $localName) {
                    continue;
                }
                $attributes = [];
                while ($reader->moveToNextAttribute()) {
                    $attributes[$reader->name] = $reader->value;
                    if ($reader->localName === 'id' && in_array($reader->namespaceURI, self::NS_RELATIONSHIPS, true)) {
                        $attributes['@rid'] = $reader->value;
                    }
                }
                $out[] = $attributes;
            }
            foreach (libxml_get_errors() as $error) {
                if ($error->level >= LIBXML_ERR_ERROR) {
                    throw $this->invalid();
                }
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $out;
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

    /** @param array<string, mixed> $seen */
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

    private function inconsistent(string $part, int $declared): UnsafeSpreadsheetException
    {
        return new UnsafeSpreadsheetException(
            'El libro de Excel está dañado o fue manipulado: el contenido real de una de sus partes no coincide con lo que declara su directorio. Expórtelo de nuevo desde Excel o use CSV.',
            UnsafeSpreadsheetException::INCONSISTENT_ENTRY, 422, ['part' => preg_replace('/[^\x20-\x7E]/', '?', mb_substr($part, 0, 80)), 'declared_bytes' => $declared],
        );
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
