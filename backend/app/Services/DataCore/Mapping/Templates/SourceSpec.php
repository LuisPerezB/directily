<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Templates;

use App\Services\DataCore\Mapping\Discovery\DiscoveredSchema;
use App\Services\DataCore\Mapping\Discovery\IdentifierQuoter;

/**
 * Estructura de origen (`mapping_templates.source_spec`) a la que apunta `field_map`:
 *
 *   { "version": 1, "dialect": "pgsql",
 *     "tables": [ {"alias": "t0", "schema": "comercial", "name": "det_venta", "kind": "table", "columns": {"cantidad": "decimal", …}, "join": null},
 *                 {"alias": "t1", "schema": "comercial", "name": "cab_venta", "kind": "table", "columns": {…},
 *                  "join": {"from": "t0", "type": "inner", "kind": "declared", "on": [["id_venta", "id_venta"]]}} ],
 *     "since": "t1.fecha_mod" }
 *
 * `field_map` guarda «campo canónico → alias.columna» (o {"const": valor}). De aquí salen la consulta SELECT generada y la huella del esquema.
 */
final class SourceSpec
{
    public const DIALECTS = ['pgsql', 'mysql', 'sqlsrv'];

    public const MAX_TABLES = 8;

    public const JOIN_TYPES = ['inner', 'left'];

    /**
     * Valida la forma de la estructura y que cada referencia del mapa de campos apunte a una columna declarada.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $fieldMap
     * @return list<string> errores (vacío = válida)
     */
    public static function validate(array $spec, array $fieldMap): array
    {
        $errors = [];
        if (! in_array($spec['dialect'] ?? null, self::DIALECTS, true)) {
            $errors[] = 'source_spec.dialect debe ser pgsql, mysql o sqlsrv.';

            return $errors;
        }
        $tables = $spec['tables'] ?? null;
        if (! is_array($tables) || $tables === [] || count($tables) > self::MAX_TABLES || ! array_is_list($tables)) {
            return ['source_spec.tables debe ser una lista de 1 a '.self::MAX_TABLES.' tablas.'];
        }
        $aliases = [];
        foreach ($tables as $i => $table) {
            $path = "source_spec.tables.{$i}";
            if (! is_array($table) || ! is_string($table['alias'] ?? null) || preg_match('/^t[0-9]{1,2}$/', $table['alias']) !== 1 || isset($aliases[$table['alias']])) {
                $errors[] = "{$path}.alias debe ser único con la forma t0, t1…";

                continue;
            }
            $aliases[$table['alias']] = $i;
            if (! is_string($table['name'] ?? null) || ! IdentifierQuoter::isSafe($table['name']) || (isset($table['schema']) && (! is_string($table['schema']) || ! IdentifierQuoter::isSafe($table['schema'])))) {
                $errors[] = "{$path}: nombre de tabla o esquema no válido.";
            }
            if (! is_array($table['columns'] ?? null) || $table['columns'] === []) {
                $errors[] = "{$path}.columns debe declarar las columnas de la tabla (nombre → clase de tipo).";
            } else {
                foreach ($table['columns'] as $name => $type) {
                    if (! IdentifierQuoter::isSafe((string) $name) || ! in_array($type, ['text', 'integer', 'decimal', 'date', 'datetime', 'boolean', 'binary', 'other'], true)) {
                        $errors[] = "{$path}.columns: columna o clase de tipo no válida.";
                        break;
                    }
                }
            }
            $join = $table['join'] ?? null;
            if ($i === 0) {
                if ($join !== null) {
                    $errors[] = 'La primera tabla es la principal y no lleva unión.';
                }
            } elseif (! is_array($join) || ! isset($aliases[$join['from'] ?? '']) || ($aliases[$join['from']] ?? PHP_INT_MAX) >= $i || ! in_array($join['type'] ?? null, self::JOIN_TYPES, true)
                || ! is_array($join['on'] ?? null) || $join['on'] === []) {
                $errors[] = "{$path}.join debe referirse a una tabla anterior, con type inner|left y condiciones on.";
            } else {
                $fromColumns = $tables[$aliases[$join['from']]]['columns'] ?? [];
                foreach ($join['on'] as $pair) {
                    if (! is_array($pair) || count($pair) !== 2 || ! isset($fromColumns[$pair[0] ?? '']) || ! isset(($table['columns'] ?? [])[$pair[1] ?? ''])) {
                        $errors[] = "{$path}.join.on: cada condición debe unir una columna de la tabla de origen con una de esta tabla.";
                        break;
                    }
                }
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        foreach ($fieldMap as $field => $source) {
            if (is_array($source)) {
                if (! array_key_exists('const', $source) || ! is_scalar($source['const']) || $source['const'] === null || (is_string($source['const']) && trim($source['const']) === '')) {
                    $errors[] = "field_map.{$field}: use «alias.columna» o {\"const\": valor}.";
                }

                continue;
            }
            if (! is_string($source) || self::resolve($spec, $source) === null) {
                $errors[] = "field_map.{$field}: la referencia no apunta a una columna de las tablas de la estructura.";
            }
        }
        if (isset($spec['since']) && (! is_string($spec['since']) || self::resolve($spec, $spec['since']) === null)) {
            $errors[] = 'source_spec.since debe apuntar a una columna de la estructura.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{alias: string, table: array<string, mixed>, column: string, type: string}|null
     */
    public static function resolve(array $spec, string $ref): ?array
    {
        $parts = explode('.', $ref, 2);
        if (count($parts) !== 2) {
            return null;
        }
        foreach ($spec['tables'] ?? [] as $table) {
            if (($table['alias'] ?? null) === $parts[0] && isset($table['columns'][$parts[1]])) {
                return ['alias' => $parts[0], 'table' => $table, 'column' => $parts[1], 'type' => (string) $table['columns'][$parts[1]]];
            }
        }

        return null;
    }

    /**
     * Columnas que la plantilla usa de verdad (campos mapeados, uniones y marca de agua): lo que su huella protege.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $fieldMap
     * @return array<string, array{table: string, schema: ?string, name: string, column: string, type: string}> clave estable → columna usada
     */
    public static function referenced(array $spec, array $fieldMap): array
    {
        $byAlias = [];
        foreach ($spec['tables'] ?? [] as $table) {
            $byAlias[$table['alias']] = $table;
        }
        $used = [];
        $mark = function (string $alias, string $column) use (&$used, $byAlias): void {
            $table = $byAlias[$alias] ?? null;
            if ($table !== null && isset($table['columns'][$column])) {
                $used[self::qualified($table)."\0".$column] = ['table' => self::qualified($table), 'schema' => $table['schema'] ?? null, 'name' => $table['name'], 'column' => (string) $column, 'type' => (string) $table['columns'][$column]];
            }
        };
        foreach ($fieldMap as $source) {
            if (is_string($source) && ($resolved = self::resolve($spec, $source)) !== null) {
                $mark($resolved['alias'], $resolved['column']);
            }
        }
        foreach ($spec['tables'] ?? [] as $table) {
            foreach (($table['join']['on'] ?? []) as [$fromColumn, $thisColumn]) {
                $mark($table['join']['from'], (string) $fromColumn);
                $mark($table['alias'], (string) $thisColumn);
            }
        }
        if (is_string($spec['since'] ?? null) && ($resolved = self::resolve($spec, $spec['since'])) !== null) {
            $mark($resolved['alias'], $resolved['column']);
        }
        ksort($used);

        return $used;
    }

    /** «esquema.tabla» (sin esquema en MySQL). @param array<string, mixed> $table */
    public static function qualified(array $table): string
    {
        return (($table['schema'] ?? null) === null || $table['schema'] === '' ? '' : $table['schema'].'.').$table['name'];
    }

    /**
     * Huella del esquema al que aplica la plantilla: SHA-256 del dialecto y de las columnas que usa con su clase de tipo. Cambiar el nombre o la clase de
     * una columna usada, o quitarla, cambia la huella; agregar columnas nuevas a las tablas NO la cambia (no rompe nada de lo aprobado).
     *
     * @param  array<string, array{table: string, column: string, type: string}>  $referenced
     */
    public static function fingerprint(string $dialect, array $referenced): string
    {
        $lines = [];
        foreach ($referenced as $entry) {
            $lines[] = json_encode([$entry['table'], $entry['column'], $entry['type']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        sort($lines);

        return hash('sha256', $dialect."\n".implode("\n", $lines));
    }

    /**
     * Compara las columnas que la plantilla usa con el esquema descubierto de una fuente. Mismo formato que la deriva de W2 (SchemaDrift):
     * agregadas, quitadas y con tipo cambiado.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $fieldMap
     * @return array{matches: bool, expected_fingerprint: string, actual_fingerprint: string, dialect_mismatch: bool, added: list<string>, removed: list<string>, retyped: list<string>, tables_removed: list<string>}
     */
    public static function diff(array $spec, array $fieldMap, DiscoveredSchema $schema): array
    {
        $expected = self::referenced($spec, $fieldMap);
        $actual = [];
        $removed = [];
        $retyped = [];
        $tablesRemoved = [];
        foreach ($expected as $key => $entry) {
            $found = $schema->table($entry['schema'], $entry['name']);
            if ($found === null) {
                $removed[] = $entry['table'].'.'.$entry['column'];
                $tablesRemoved[$entry['table']] = $entry['table'];

                continue;
            }
            $discovered = $found->column($entry['column']);
            if ($discovered === null) {
                $removed[] = $entry['table'].'.'.$entry['column'];

                continue;
            }
            $actual[$key] = ['table' => $entry['table'], 'column' => $entry['column'], 'type' => $discovered->typeClass->value];
            if ($discovered->typeClass->value !== $entry['type']) {
                $retyped[] = $entry['table'].'.'.$entry['column'].': '.$entry['type'].'→'.$discovered->typeClass->value;
            }
        }
        // Columnas nuevas en las tablas usadas (informativo: no rompen la plantilla).
        $added = [];
        foreach ($spec['tables'] ?? [] as $table) {
            foreach ($schema->table($table['schema'] ?? null, $table['name'])?->columns ?? [] as $column) {
                if (! isset($table['columns'][$column->name])) {
                    $added[] = self::qualified($table).'.'.$column->name;
                }
            }
        }
        sort($removed);
        sort($retyped);
        sort($added);
        $dialectMismatch = ($spec['dialect'] ?? null) !== $schema->dialect;

        return [
            'matches' => $removed === [] && $retyped === [] && ! $dialectMismatch,
            'expected_fingerprint' => self::fingerprint((string) ($spec['dialect'] ?? ''), $expected),
            'actual_fingerprint' => self::fingerprint($schema->dialect, $actual),
            'dialect_mismatch' => $dialectMismatch,
            'added' => array_slice($added, 0, 200),
            'removed' => $removed,
            'retyped' => $retyped,
            'tables_removed' => array_values($tablesRemoved),
        ];
    }
}
