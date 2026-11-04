<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Templates;

use App\Services\Connectors\Sql\SqlQueryGuard;
use App\Services\Connectors\Sql\SqlQueryRejected;
use App\Services\DataCore\Mapping\Discovery\IdentifierQuoter;
use InvalidArgumentException;

/**
 * Genera la consulta SELECT de una plantilla aprobada. La consulta se construye SOLO con: palabras clave fijas, identificadores del catálogo del origen
 * entrecomillados (IdentifierQuoter) y los nombres canónicos de los campos como alias; ningún texto libre de una persona entra en ella. Aun así, la consulta
 * resultante pasa por SqlQueryGuard (la misma política de solo lectura de las consultas escritas a mano) antes de aprobarse y de ejecutarse.
 *
 * Cada columna se proyecta con el nombre del campo canónico como alias (`t0."cantidad" AS "quantity"`), de modo que el `field_map` del SourceMapping es
 * la identidad (campo → campo): no depende de nombres de columnas del origen que pueden repetirse entre tablas o tener caracteres raros. La marca de agua
 * (`:since`) se aplica sobre la columna de modificación declarada en la estructura. Sin ORDER BY (SQL Server no lo admite en la vista previa).
 */
final class SourceQueryBuilder
{
    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $fieldMap
     * @return array{sql: string, field_map: array<string, mixed>, columns: list<string>}
     *
     * @throws InvalidArgumentException estructura o identificadores no válidos
     */
    public function build(array $spec, array $fieldMap): array
    {
        $errors = SourceSpec::validate($spec, $fieldMap);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
        $dialect = (string) $spec['dialect'];
        $select = [];
        $mapped = [];
        foreach ($fieldMap as $field => $source) {
            if (is_array($source)) {
                $mapped[$field] = ['const' => $source['const']];

                continue;
            }
            $resolved = SourceSpec::resolve($spec, (string) $source) ?? throw new InvalidArgumentException("field_map.{$field}: referencia no válida.");
            if (preg_match('/^[a-z][a-z0-9_]*$/', (string) $field) !== 1) {
                throw new InvalidArgumentException("field_map.{$field}: nombre de campo no válido.");
            }
            $select[] = $resolved['alias'].'.'.IdentifierQuoter::quote($dialect, $resolved['column']).' AS '.IdentifierQuoter::quote($dialect, (string) $field);
            $mapped[$field] = (string) $field;
        }
        if ($select === []) {
            throw new InvalidArgumentException('La plantilla no mapea ninguna columna de origen.');
        }
        $tables = $spec['tables'];
        $sql = 'SELECT '.implode(', ', $select).' FROM '.IdentifierQuoter::table($dialect, $tables[0]['schema'] ?? null, $tables[0]['name']).' '.$tables[0]['alias'];
        foreach (array_slice($tables, 1) as $table) {
            $join = $table['join'];
            $on = [];
            foreach ($join['on'] as [$fromColumn, $thisColumn]) {
                $on[] = $join['from'].'.'.IdentifierQuoter::quote($dialect, (string) $fromColumn).' = '.$table['alias'].'.'.IdentifierQuoter::quote($dialect, (string) $thisColumn);
            }
            $sql .= ($join['type'] === 'left' ? ' LEFT JOIN ' : ' INNER JOIN ').IdentifierQuoter::table($dialect, $table['schema'] ?? null, $table['name']).' '.$table['alias'].' ON '.implode(' AND ', $on);
        }
        if (is_string($spec['since'] ?? null)) {
            $since = SourceSpec::resolve($spec, $spec['since']);
            if ($since !== null && in_array($since['type'], ['date', 'datetime'], true)) {
                $sql .= ' WHERE '.$since['alias'].'.'.IdentifierQuoter::quote($dialect, $since['column']).' >= :since';
            }
        }

        return ['sql' => $sql, 'field_map' => $mapped, 'columns' => array_keys($mapped)];
    }

    /**
     * Valida la consulta con la política de solo lectura de los conectores SQL.
     *
     * @throws SqlQueryRejected
     */
    public function guard(string $sql, string $dialect): void
    {
        SqlQueryGuard::validate($sql, $dialect);
    }
}
