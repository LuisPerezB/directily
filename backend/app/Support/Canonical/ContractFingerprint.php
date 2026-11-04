<?php

declare(strict_types=1);

namespace App\Support\Canonical;

use Illuminate\Support\Facades\DB;

/**
 * Huella SHA-256 de las columnas REALES de las tablas de un contrato canónico, leídas de `information_schema` (PostgreSQL).
 *
 * La huella cubre, por tabla y columna: tipo (`data_type` y `udt_name`), nulabilidad, longitud máxima, precisión y escala numéricas.
 * NO cubre el orden de las columnas, los valores por defecto ni los índices. El orden de la entrada es por bytes (`strcmp`), no por la
 * ordenación del servidor, para que la huella sea idéntica en cualquier instalación de PostgreSQL.
 */
final class ContractFingerprint
{
    /**
     * @param  list<string>  $tables
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: string}>
     */
    public static function columns(array $tables): array
    {
        if ($tables === []) {
            return [];
        }
        $rows = DB::select(
            'SELECT table_name, column_name, data_type, udt_name, is_nullable, COALESCE(character_maximum_length::text, \'\') AS len, '
            .'COALESCE(numeric_precision::text, \'\') AS prec, COALESCE(numeric_scale::text, \'\') AS scale '
            .'FROM information_schema.columns WHERE table_schema = current_schema() AND table_name IN ('.implode(',', array_fill(0, count($tables), '?')).')',
            array_values($tables),
        );
        $out = array_map(fn ($r) => [$r->table_name, $r->column_name, $r->data_type, $r->udt_name, $r->is_nullable, $r->len, $r->prec, $r->scale], $rows);
        usort($out, fn ($a, $b) => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));

        return $out;
    }

    /** @param list<string> $tables */
    public static function hash(array $tables): string
    {
        return hash('sha256', implode("\n", array_map(fn ($r) => implode('|', $r), self::columns($tables))));
    }

    /**
     * Nombres de columna reales por tabla, en su orden ordinal.
     *
     * @param  list<string>  $tables
     * @return array<string, list<string>>
     */
    public static function columnNames(array $tables): array
    {
        $names = [];
        foreach ($tables as $table) {
            $names[$table] = array_map(fn ($r) => $r->column_name, DB::select(
                'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position',
                [$table],
            ));
        }

        return $names;
    }
}
