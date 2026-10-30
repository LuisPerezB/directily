<?php

declare(strict_types=1);

namespace App\Services\DataCore\Sync;

use App\Models\SourceMapping;
use App\Models\Tenant;
use App\Services\Connectors\Sql\SqlSourceConnection;
use App\Services\Connectors\Sql\SqlSourceException;
use App\Services\Connectors\Sql\ValidatedQuery;
use App\Services\Imports\Fields\FieldDefinition;
use App\Services\Imports\Fields\ImportFieldRegistry;
use App\Services\Imports\ImportEntity;
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
     * @throws SqlSourceException
     */
    public function stage(SqlSourceConnection $connection, ValidatedQuery $query, SourceMapping $mapping, Tenant $tenant, string $sourceTimezone, ?CarbonInterface $since, string $path): StagingResult
    {
        $headers = $this->headers($mapping);
        $types = [];
        foreach ($this->fields->fields(ImportEntity::from($mapping->entity)) as $definition) {
            $types[$definition->key] = $definition->type;
        }
        $options = (array) $mapping->options;
        $pageSize = (int) ($options['page_size'] ?? config('connectors.sql.default_page_size', 5000));
        $maxRows = (int) ($options['max_rows'] ?? config('connectors.sql.max_rows_per_sync', 2_000_000));
        $valueMaps = (array) ($options['value_maps'] ?? []);
        $eventIndex = ($f = SourceMappingService::EVENT_DATE_FIELD[$mapping->entity] ?? null) !== null ? array_search($f, $headers, true) : false;
        $recordedIndex = array_search('source_recorded_at', $headers, true);
        $result = new StagingResult;
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new SqlSourceException('No se pudo crear el archivo de preparación.');
        }
        $columnIndex = null;
        try {
            fputcsv($handle, $headers, ',', '"', '');
            foreach ($connection->stream($query, $since, $pageSize) as $page) {
                $result->pages++;
                foreach ($page as $row) {
                    $columnIndex ??= $this->resolveColumns($row, $mapping);
                    if (++$result->rows > $maxRows) {
                        throw new SqlSourceException("La consulta devuelve más de {$maxRows} filas; acótela con :since o aumente max_rows en el mapeo.");
                    }
                    $out = $this->transform($row, $headers, $types, $mapping, $columnIndex, $valueMaps, $sourceTimezone, $tenant->timezone ?: 'America/Guayaquil');
                    if ($eventIndex !== false) {
                        $this->trackEvent($result, $out[$eventIndex]);
                    }
                    $recorded = $recordedIndex !== false ? $this->parseOrNull($out[$recordedIndex], $tenant->timezone ?: 'America/Guayaquil') : null;
                    if ($recorded !== null && ($result->maxRecordedAt === null || $recorded->greaterThan($result->maxRecordedAt))) {
                        $result->maxRecordedAt = $recorded;
                    }
                    fputcsv($handle, $out, ',', '"', '');
                }
            }
        } finally {
            fclose($handle);
        }

        return $result;
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
        $valueMaps = (array) (((array) $mapping->options)['value_maps'] ?? []);
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
     * @param  array<string, mixed>  $valueMaps
     * @return list<string>
     */
    private function transform(array $row, array $headers, array $types, SourceMapping $mapping, array $columnIndex, array $valueMaps, string $sourceTimezone, string $tenantTimezone): array
    {
        $out = [];
        foreach ($headers as $field) {
            $raw = $this->rawValue($field, $mapping, $row, $columnIndex);
            if (isset($valueMaps[$field]) && $raw !== null && array_key_exists((string) $raw, $valueMaps[$field])) {
                $raw = $valueMaps[$field][(string) $raw];
            }
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
            throw new SqlSourceException('El mapeo referencia columnas que la consulta no devuelve: '.implode(', ', $missing).'.');
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

    private function parseOrNull(string $value, string $timezone): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, $timezone);
        } catch (Throwable) {
            return null;
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
