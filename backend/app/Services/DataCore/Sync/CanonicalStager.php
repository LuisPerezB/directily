<?php

declare(strict_types=1);

namespace App\Services\DataCore\Sync;

use App\Models\SourceMapping;
use App\Models\Tenant;
use App\Services\Connectors\Sql\SqlSourceConnection;
use App\Services\Connectors\Sql\SqlSourceException;
use App\Services\Connectors\Sql\ValidatedQuery;
use App\Services\DataCore\Quality\ValueMaps;
use App\Services\Imports\Fields\FieldDefinition;
use App\Services\Imports\Fields\ImportFieldRegistry;
use App\Services\Imports\ImportEntity;
use App\Services\Imports\Parsing\ValueParser;
use App\Services\Sales\SalesLineNormalizer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Throwable;

/**
 * Convierte las filas de la fuente en filas CANÓNICAS (campos del importador) y las deja en un CSV de preparación.
 * No escribe en la base de datos de Predictily: el CSV entra después por el MISMO pipeline de importación
 * (validación, idempotencia por clave natural + source_hash, procedencia).
 *
 * Zona horaria: los timestamps con zona se convierten a la de la empresa; los «ingenuos» se interpretan en
 * `source_timezone` (opción del mapeo o de la fuente) y se convierten a la de la empresa. Las fechas puras no se desplazan.
 *
 * W2-A (ADR-V25-24): ANTES de convertir cada fila, la fila CRUDA (solo las columnas que usa el mapeo aprobado) se escribe en un NDJSON
 * comprimido —una línea por fila de origen, así la fila `n` del CSV de preparación es la línea `n` del original— que el llamador guarda en
 * Bronze. También se observan los tipos de TODAS las columnas devueltas (huella de esquema) y se cuentan las aplicaciones de los mapeos de
 * valores (transformaciones).
 */
final class CanonicalStager
{
    public function __construct(private readonly ImportFieldRegistry $fields) {}

    /**
     * @return list<string> encabezados canónicos que se escribirán (orden del importador)
     */
    public function headers(SourceMapping $mapping): array
    {
        $definitions = $this->fields->fields(ImportEntity::from($mapping->entity));

        return array_values(array_filter(array_map(fn (FieldDefinition $f) => $f->key, $definitions), fn (string $k) => isset($mapping->field_map[$k])));
    }

    /**
     * `$notAfter` es el instante de referencia (el inicio de la sincronización; por defecto, ahora) de la cota superior de las fechas
     * de registro en la fuente que pueden mover la marca de agua: ESA cota es la misma que aplica el normalizador de ventas a
     * `source_recorded_at` (referencia + tolerancia configurable, 5 minutos por defecto; `SalesLineNormalizer::recordedAtBound`).
     * Una fecha posterior a la cota o ilegible se cuenta como anomalía y no adelanta la marca.
     *
     * @throws SqlSourceException
     */
    public function stage(SqlSourceConnection $connection, ValidatedQuery $query, SourceMapping $mapping, Tenant $tenant, string $sourceTimezone, ?CarbonInterface $since, string $path, ?CarbonInterface $notAfter = null, ?string $rawPath = null): StagingResult
    {
        $headers = $this->headers($mapping);
        $types = [];
        foreach ($this->fields->fields(ImportEntity::from($mapping->entity)) as $definition) {
            $types[$definition->key] = $definition->type;
        }
        $options = (array) $mapping->options;
        $pageSize = (int) ($options['page_size'] ?? config('connectors.sql.default_page_size', 5000));
        $maxRows = (int) ($options['max_rows'] ?? config('connectors.sql.max_rows_per_sync', 2_000_000));
        $maps = new ValueMaps((array) ($options['value_maps'] ?? []));
        $eventIndex = ($f = SourceMappingService::EVENT_DATE_FIELD[$mapping->entity] ?? null) !== null ? array_search($f, $headers, true) : false;
        $recordedIndex = array_search('source_recorded_at', $headers, true);
        $result = new StagingResult;
        $tenantTimezone = $tenant->timezone ?: 'America/Guayaquil';
        $recordedParser = new ValueParser($tenantTimezone);
        $bound = SalesLineNormalizer::recordedAtBound($notAfter);
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new SqlSourceException('No se pudo crear el archivo de preparación.');
        }
        $columnIndex = null;
        $raw = null;
        $rawKeys = [];
        $unknownTypes = [];
        if ($rawPath !== null) {
            $raw = gzopen($rawPath, 'wb6');
            if ($raw === false) {
                fclose($handle);
                throw new SqlSourceException('No se pudo crear el archivo de captura Bronze.');
            }
            $result->rawPath = $rawPath;
        }
        try {
            fputcsv($handle, $headers, ',', '"', '');
            foreach ($connection->stream($query, $since, $pageSize) as $page) {
                $result->pages++;
                foreach ($page as $row) {
                    if ($columnIndex === null) {
                        $columnIndex = $this->resolveColumns($row, $mapping);
                        $rawKeys = $this->usedColumns($mapping, $columnIndex);
                        foreach (array_keys($row) as $column) {
                            $result->columns[(string) $column] = null;
                            $unknownTypes[(string) $column] = true;
                        }
                    }
                    if (++$result->rows > $maxRows) {
                        throw new SqlSourceException("La consulta devuelve más de {$maxRows} filas; acótela con :since o aumente max_rows en el mapeo.");
                    }
                    if ($unknownTypes !== []) {
                        foreach ($unknownTypes as $column => $_) {
                            if (($type = self::typeOf($row[$column] ?? null)) !== null) {
                                $result->columns[$column] = $type;
                                unset($unknownTypes[$column]);
                            }
                        }
                    }
                    if ($raw !== null) {
                        // ANTES de transformar: la fila de origen tal como la devolvió la consulta (columnas del mapeo).
                        gzwrite($raw, $this->rawLine($row, $rawKeys));
                    }
                    $out = $this->transform($row, $headers, $types, $mapping, $columnIndex, $maps, $sourceTimezone, $tenantTimezone);
                    if ($eventIndex !== false) {
                        $this->trackEvent($result, $out[$eventIndex]);
                    }
                    if ($recordedIndex !== false && $out[$recordedIndex] !== '') {
                        $this->trackRecorded($result, $out[$recordedIndex], $recordedParser, $bound);
                    }
                    fputcsv($handle, $out, ',', '"', '');
                }
            }
        } finally {
            fclose($handle);
            if ($raw !== null) {
                gzclose($raw);
            }
        }
        $result->valueMapHits = $maps->hits();

        return $result;
    }

    /**
     * Línea NDJSON de una fila de origen: solo las columnas del mapeo, tal como las devolvió la consulta.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function rawLine(array $row, array $keys): string
    {
        $out = [];
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            $out[$key] = match (true) {
                // Mismo formato «ingenuo» que `format()` usa al preparar el CSV: reprocesar desde Bronze interpreta la fecha en la zona de la fuente igual que la
                // extracción original (con un desplazamiento explícito cambiaría la hora local y el reprocesamiento dejaría de ser determinista).
                $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
                is_resource($value) => '[binario]',
                is_float($value) && ! is_finite($value) => null,
                is_array($value), is_object($value) => null,
                default => $value,
            };
        }

        return (json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION) ?: '{}')."\n";
    }

    /**
     * Columnas REALES de la consulta que usa el mapeo (orden estable).
     *
     * @param  array<string, string>  $columnIndex
     * @return list<string>
     */
    private function usedColumns(SourceMapping $mapping, array $columnIndex): array
    {
        $keys = [];
        foreach ((array) $mapping->field_map as $source) {
            if (is_string($source) && isset($columnIndex[strtolower($source)])) {
                $keys[$columnIndex[strtolower($source)]] = true;
            }
        }

        return array_keys($keys);
    }

    /** Tipo observado de un valor del controlador (entero, decimal, texto, fecha, booleano); null mientras solo se vea NULL. */
    private static function typeOf(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            $value instanceof DateTimeInterface => 'datetime',
            is_resource($value) => 'binary',
            default => 'string',
        };
    }

    /**
     * Reprocesamiento desde Bronze (ADR-V25-24): convierte filas CRUDAS ya capturadas (NDJSON del objeto Bronze) al mismo CSV canónico con
     * la MISMA transformación que la extracción (mapeo, mapeos de valores, zonas horarias), sin tocar la fuente. Determinista.
     *
     * @param  iterable<array<string, mixed>>  $rawRows
     * @return int filas escritas
     *
     * @throws SqlSourceException
     */
    public function stageRows(iterable $rawRows, SourceMapping $mapping, Tenant $tenant, string $sourceTimezone, string $path): int
    {
        $headers = $this->headers($mapping);
        $types = [];
        foreach ($this->fields->fields(ImportEntity::from($mapping->entity)) as $definition) {
            $types[$definition->key] = $definition->type;
        }
        $maps = new ValueMaps((array) (((array) $mapping->options)['value_maps'] ?? []));
        $tenantTimezone = $tenant->timezone ?: 'America/Guayaquil';
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new SqlSourceException('No se pudo crear el archivo de preparación.');
        }
        $columnIndex = null;
        $rows = 0;
        try {
            fputcsv($handle, $headers, ',', '"', '');
            foreach ($rawRows as $row) {
                $columnIndex ??= $this->resolveColumns($row, $mapping);
                fputcsv($handle, $this->transform($row, $headers, $types, $mapping, $columnIndex, $maps, $sourceTimezone, $tenantTimezone), ',', '"', '');
                $rows++;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * Vista previa: las primeras filas ya convertidas a campos canónicos (no devuelve las columnas crudas de la fuente).
     *
     * @return array{headers: list<string>, rows: list<list<string>>}
     *
     * @throws SqlSourceException
     */
    public function preview(SqlSourceConnection $connection, ValidatedQuery $query, SourceMapping $mapping, Tenant $tenant, string $sourceTimezone, int $limit): array
    {
        $headers = $this->headers($mapping);
        $types = [];
        foreach ($this->fields->fields(ImportEntity::from($mapping->entity)) as $definition) {
            $types[$definition->key] = $definition->type;
        }
        $valueMaps = new ValueMaps((array) (((array) $mapping->options)['value_maps'] ?? []));
        $rows = [];
        $columnIndex = null;
        foreach ($connection->preview($query, null, $limit) as $row) {
            $columnIndex ??= $this->resolveColumns($row, $mapping);
            $rows[] = $this->transform($row, $headers, $types, $mapping, $columnIndex, $valueMaps, $sourceTimezone, $tenant->timezone ?: 'America/Guayaquil');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $headers
     * @param  array<string, string>  $types
     * @param  array<string, string>  $columnIndex
     * @return list<string>
     */
    private function transform(array $row, array $headers, array $types, SourceMapping $mapping, array $columnIndex, ValueMaps $maps, string $sourceTimezone, string $tenantTimezone): array
    {
        $out = [];
        foreach ($headers as $field) {
            $raw = $maps->apply($field, $this->rawValue($field, $mapping, $row, $columnIndex));
            $out[] = $this->format($raw, $types[$field] ?? 'string', $sourceTimezone, $tenantTimezone);
        }

        return $out;
    }

    /**
     * Resuelve (sin distinguir mayúsculas) las columnas del mapeo contra las columnas reales del resultado.
     *
     * @param  array<string, mixed>  $firstRow
     * @return array<string, string> columna de mapeo en minúsculas => clave real
     *
     * @throws SqlSourceException
     */
    private function resolveColumns(array $firstRow, SourceMapping $mapping): array
    {
        $available = [];
        foreach (array_keys($firstRow) as $key) {
            $available[strtolower((string) $key)] = (string) $key;
        }
        $missing = [];
        foreach ((array) $mapping->field_map as $field => $source) {
            if (is_string($source) && ! isset($available[strtolower($source)])) {
                $missing[] = "{$field} → {$source}";
            }
        }
        if ($missing !== []) {
            throw new SourceColumnsMissingException('El mapeo referencia columnas que la consulta no devuelve: '.implode(', ', $missing).'.');
        }

        return $available;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $columnIndex
     */
    private function rawValue(string $field, SourceMapping $mapping, array $row, array $columnIndex): mixed
    {
        $source = $mapping->field_map[$field];
        if (is_array($source)) {
            return $source['const'] ?? null;
        }

        return $row[$columnIndex[strtolower($source)]] ?? null;
    }

    private function format(mixed $value, string $type, string $sourceTz, string $tenantTz): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if (is_float($value)) {
            $text = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

            return $text === '' || $text === '-0' ? '0' : $text;
        }
        $text = trim((string) $value);
        if ($type === 'date' || $type === 'datetime') {
            return $this->formatTemporal($text, $type, $sourceTz, $tenantTz);
        }

        return str_replace(["\0", "\r"], '', $text);
    }

    private function formatTemporal(string $text, string $type, string $sourceTz, string $tenantTz): string
    {
        if ($text === '') {
            return '';
        }
        // Fecha pura (sin hora): no se desplaza de zona horaria.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            return $text;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}(:?\d{2})?)?$/', $text) !== 1) {
            return $text; // formato desconocido: lo valida el importador (invalid_date)
        }
        try {
            $hasOffset = preg_match('/(Z|[+-]\d{2}(:?\d{2})?)$/', $text) === 1;
            $moment = $hasOffset ? CarbonImmutable::parse($text) : CarbonImmutable::parse($text, $sourceTz);

            return $moment->setTimezone($tenantTz)->format($type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
        } catch (Throwable) {
            return $text;
        }
    }

    /**
     * Fecha de registro en la fuente de una fila: solo una fecha legible y no posterior a `$bound` (referencia + tolerancia) es
     * candidata a marca de agua (que luego se acota al inicio de la sincronización).
     * Una futura o ilegible es una anomalía que se cuenta (`future_recorded_at` en las estadísticas) y no la adelanta: una sola
     * fila con `modificado_en` = 2099 dejaría a todas las incrementales siguientes sin extraer nada.
     */
    private function trackRecorded(StagingResult $result, string $value, ValueParser $parser, CarbonInterface $bound): void
    {
        $parsed = $parser->dateTime($value);
        if ($parsed === null) {
            $result->unreadableRecordedAt++;

            return;
        }
        // En UTC: Eloquent guarda un Carbon con el formato de su propia zona (sin desplazamiento), así que una marca de agua en la zona
        // de la empresa se guardaría corrida por su desfase horario. La marca debe ser el instante real.
        $recorded = $parsed[0]->utc();
        if ($recorded->greaterThan($bound)) {
            $result->futureRecordedAt++;

            return;
        }
        if ($result->maxRecordedAt === null || $recorded->greaterThan($result->maxRecordedAt)) {
            $result->maxRecordedAt = $recorded;
        }
    }

    private function trackEvent(StagingResult $result, string $value): void
    {
        $date = substr($value, 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }
        $result->minEventDate = $result->minEventDate === null || $date < $result->minEventDate ? $date : $result->minEventDate;
        $result->maxEventDate = $result->maxEventDate === null || $date > $result->maxEventDate ? $date : $result->maxEventDate;
    }
}
