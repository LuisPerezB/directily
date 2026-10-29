<?php

declare(strict_types=1);

namespace App\Services\DataCore\Sync;

use App\Models\DataSource;
use App\Models\SourceMapping;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Connectors\Sql\SqlConnectorInterface;
use App\Services\Connectors\Sql\SqlQueryGuard;
use App\Services\Connectors\Sql\SqlQueryRejected;
use App\Services\Connectors\Sql\ValidatedQuery;
use App\Services\Imports\Fields\ImportFieldRegistry;
use App\Services\Imports\ImportEntity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Consultas autorizadas por fuente SQL (ADR-V2-13). Una versión nueva nace SIN aprobar; solo la versión aprobada
 * y activa de cada entidad se sincroniza. Cambiar consulta, mapeo u opciones siempre crea otra versión.
 */
final class SourceMappingService
{
    /** Entidades con importador canónico (los clientes se crean desde las ventas; no hay entidad «customers»). */
    public const ENTITIES = ['sales', 'products', 'inventory', 'commitments', 'branches', 'suppliers', 'lead_times', 'promotions', 'calendar_events', 'unit_conversions', 'warehouses'];

    /** Campo de fecha del evento por entidad (cobertura de la fuente). */
    public const EVENT_DATE_FIELD = ['sales' => 'issued_at', 'commitments' => 'issued_at'];

    private const OPTION_KEYS = ['page_size', 'overlap_minutes', 'max_rows', 'source_timezone', 'value_maps', 'coverage', 'import'];

    private const IMPORT_OPTION_KEYS = ['skip_invalid_rows', 'default_branch_code', 'create_missing_products', 'create_missing_branches', 'create_missing_categories'];

    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly ImportFieldRegistry $fields,
        private readonly AuditLogger $audit,
    ) {}

    public function connectorFor(DataSource $source): SqlConnectorInterface
    {
        $connector = $this->connectors->get((string) $source->connector_key);
        if (! $connector instanceof SqlConnectorInterface) {
            throw ValidationException::withMessages(['data_source' => ['La fuente no es un conector SQL de solo lectura.']]);
        }

        return $connector;
    }

    /**
     * @param  array{entity: string, query: string, field_map: array<string, mixed>, options?: array<string, mixed>}  $data
     *
     * @throws ValidationException
     */
    public function create(DataSource $source, array $data, ?User $user): SourceMapping
    {
        $connector = $this->connectorFor($source);
        [$entity, $fieldMap, $options] = $this->normalize($data);
        $this->guard((string) $data['query'], $connector);

        return DB::transaction(function () use ($source, $entity, $data, $fieldMap, $options, $user): SourceMapping {
            // Serializa versiones concurrentes de la misma entidad.
            DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [7433, $source->id]);
            $version = (int) SourceMapping::query()->where('data_source_id', $source->id)->where('entity', $entity)->max('version') + 1;
            $mapping = new SourceMapping;
            $mapping->forceFill([
                'data_source_id' => $source->id,
                'entity' => $entity,
                'query' => trim((string) $data['query']),
                'field_map' => $fieldMap,
                'options' => $options,
                'version' => $version,
                'is_active' => false,
            ])->save();
            $this->audit->log('datasource.mapping_created', $mapping, ['data_source_id' => $source->id, 'entity' => $entity, 'version' => $version], userId: $user?->id);

            return $mapping;
        });
    }

    public function approve(SourceMapping $mapping, User $user): SourceMapping
    {
        // Revalida: la aprobación autoriza el texto exacto que se ejecutará.
        $source = DataSource::query()->findOrFail($mapping->data_source_id);
        $this->guard($mapping->query, $this->connectorFor($source));

        return DB::transaction(function () use ($mapping, $user): SourceMapping {
            DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [7433, $mapping->data_source_id]);
            SourceMapping::query()->where('data_source_id', $mapping->data_source_id)->where('entity', $mapping->entity)
                ->where('id', '!=', $mapping->id)->update(['is_active' => false]);
            $mapping->forceFill(['is_active' => true, 'approved_by' => $user->id, 'approved_at' => now()])->save();
            $this->audit->log('datasource.mapping_approved', $mapping, ['data_source_id' => $mapping->data_source_id, 'entity' => $mapping->entity, 'version' => $mapping->version], userId: $user->id);

            return $mapping;
        });
    }

    public function deactivate(SourceMapping $mapping): SourceMapping
    {
        $mapping->forceFill(['is_active' => false])->save();
        $this->audit->log('datasource.mapping_deactivated', $mapping, ['data_source_id' => $mapping->data_source_id, 'entity' => $mapping->entity]);

        return $mapping;
    }

    /** Versión aprobada y activa de la entidad (la única que sincroniza). */
    public function activeFor(DataSource $source, string $entity): ?SourceMapping
    {
        return SourceMapping::query()->where('data_source_id', $source->id)->where('entity', $entity)
            ->where('is_active', true)->whereNotNull('approved_at')->orderByDesc('version')->first();
    }

    /** @throws ValidationException */
    public function guard(string $query, SqlConnectorInterface $connector): ValidatedQuery
    {
        try {
            return SqlQueryGuard::validate($query, $connector->dialect()->name());
        } catch (SqlQueryRejected $e) {
            throw ValidationException::withMessages(['query' => [$e->getMessage()]])->response(response()->json([
                'message' => 'La consulta no cumple la política de solo lectura: '.$e->getMessage(),
                'code' => 'sql_query_rejected',
                'reason' => $e->reason,
                'errors' => ['query' => [$e->getMessage()]],
            ], 422));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function normalize(array $data): array
    {
        $errors = [];
        $entity = (string) ($data['entity'] ?? '');
        if (! in_array($entity, self::ENTITIES, true)) {
            $errors['entity'][] = $entity === 'customers' || $entity === 'costs'
                ? 'La entidad «'.$entity.'» no tiene importador propio: los clientes se crean a partir de las ventas y los costos de los productos.'
                : 'Entidad no soportada. Use una de: '.implode(', ', self::ENTITIES).'.';
            throw ValidationException::withMessages($errors);
        }
        $definitions = $this->fields->keyed(ImportEntity::from($entity));
        $fieldMap = [];
        foreach ((array) ($data['field_map'] ?? []) as $field => $source) {
            if (! isset($definitions[$field])) {
                $errors["field_map.{$field}"][] = "Campo canónico desconocido: {$field}.";

                continue;
            }
            if (is_string($source) && trim($source) !== '') {
                $fieldMap[$field] = trim($source);
            } elseif (is_array($source) && array_key_exists('const', $source) && (is_scalar($source['const']) || $source['const'] === null)) {
                $fieldMap[$field] = ['const' => $source['const']];
            } else {
                $errors["field_map.{$field}"][] = 'Indique la columna de origen (texto) o {"const": valor}.';
            }
        }
        foreach ($definitions as $key => $definition) {
            if ($definition->required && ! isset($fieldMap[$key])) {
                $errors["field_map.{$key}"][] = "El campo obligatorio «{$definition->label}» no está mapeado.";
            }
        }
        $options = $this->normalizeOptions((array) ($data['options'] ?? []), array_keys($definitions), $errors);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$entity, $fieldMap, $options];
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $fieldKeys
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>
     */
    private function normalizeOptions(array $options, array $fieldKeys, array &$errors): array
    {
        foreach (array_diff(array_keys($options), self::OPTION_KEYS) as $key) {
            $errors["options.{$key}"][] = "Opción no reconocida: {$key}.";
        }
        $clean = [];
        $ranges = ['page_size' => [100, (int) config('connectors.sql.max_page_size', 20_000)], 'overlap_minutes' => [0, 10_080], 'max_rows' => [1, 10_000_000]];
        foreach ($ranges as $key => [$min, $max]) {
            if (array_key_exists($key, $options)) {
                if (! is_numeric($options[$key]) || (int) $options[$key] < $min || (int) $options[$key] > $max) {
                    $errors["options.{$key}"][] = "Debe estar entre {$min} y {$max}.";
                } else {
                    $clean[$key] = (int) $options[$key];
                }
            }
        }
        if (isset($options['source_timezone'])) {
            if (! in_array($options['source_timezone'], timezone_identifiers_list(), true)) {
                $errors['options.source_timezone'][] = 'Zona horaria no válida.';
            } else {
                $clean['source_timezone'] = $options['source_timezone'];
            }
        }
        if (isset($options['value_maps'])) {
            foreach ((array) $options['value_maps'] as $field => $map) {
                if (! in_array($field, $fieldKeys, true) || ! is_array($map)) {
                    $errors["options.value_maps.{$field}"][] = 'Debe ser un diccionario valor_origen => valor_canónico de un campo del importador.';

                    continue;
                }
                $clean['value_maps'][$field] = array_map(fn ($v) => $v === null ? null : (string) $v, $map);
            }
        }
        if (isset($options['coverage'])) {
            $coverage = (array) $options['coverage'];
            $lag = $coverage['lag_days'] ?? 1;
            if (! is_numeric($lag) || (int) $lag < 0 || (int) $lag > 30) {
                $errors['options.coverage.lag_days'][] = 'Debe estar entre 0 y 30.';
            }
            $clean['coverage'] = ['enabled' => (bool) ($coverage['enabled'] ?? true), 'lag_days' => (int) $lag];
        }
        if (isset($options['import'])) {
            $import = (array) $options['import'];
            foreach (array_diff(array_keys($import), self::IMPORT_OPTION_KEYS) as $key) {
                $errors["options.import.{$key}"][] = "Opción de importación no reconocida: {$key}.";
            }
            $clean['import'] = array_intersect_key($import, array_flip(self::IMPORT_OPTION_KEYS));
        }

        return $clean;
    }
}
