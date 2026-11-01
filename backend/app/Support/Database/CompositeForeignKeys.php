<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * FKs compuestas (tenant_id, x_id) con ON DELETE SET NULL.
 *
 * En PostgreSQL, `ON DELETE SET NULL` sin lista de columnas anula TODAS las columnas de la FK, también `tenant_id`, que es
 * NOT NULL: borrar en firme la fila referenciada (p. ej. una fuente de datos referenciada por un objeto Bronze) falla con
 * «null value in column tenant_id» en lugar de anular solo la referencia. PostgreSQL 15+ admite `ON DELETE SET NULL (x_id)`:
 * este ayudante reescribe cada FK compuesta de ese tipo para que anule solo las columnas que no son NOT NULL.
 *
 * Las migraciones que crean FKs compuestas con `->nullOnDelete()` llaman a `setNullOnlyReferences()` al final de `up()`.
 */
final class CompositeForeignKeys
{
    /**
     * Reescribe las FKs compuestas ON DELETE SET NULL que incluyen una columna NOT NULL. Devuelve las FKs cambiadas.
     *
     * @return list<array{table: string, constraint: string, set_null: list<string>}>
     */
    public static function setNullOnlyReferences(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }
        $changed = [];
        foreach (self::compositeSetNullForeignKeys() as $fk) {
            $nullable = array_values(array_filter($fk['columns'], fn (string $c): bool => ! in_array($c, $fk['not_null'], true)));
            if ($nullable === [] || count($nullable) === count($fk['columns'])) {
                continue;   // nada que anular, o ninguna columna NOT NULL (SET NULL de todas es válido)
            }
            $definition = preg_replace('/ON DELETE SET NULL(?!\s*\()/', 'ON DELETE SET NULL ('.implode(', ', array_map(self::quote(...), $nullable)).')', $fk['definition'], 1);
            if ($definition === null || $definition === $fk['definition']) {
                continue;
            }
            DB::statement(sprintf('ALTER TABLE %s DROP CONSTRAINT %s, ADD CONSTRAINT %s %s',
                self::quote($fk['table']), self::quote($fk['constraint']), self::quote($fk['constraint']), $definition));
            $changed[] = ['table' => $fk['table'], 'constraint' => $fk['constraint'], 'set_null' => $nullable];
        }

        return $changed;
    }

    /** Vuelve a `ON DELETE SET NULL` sin lista de columnas (reversión de la migración). */
    public static function restorePlainSetNull(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::compositeSetNullForeignKeys() as $fk) {
            $definition = preg_replace('/ON DELETE SET NULL\s*\([^)]*\)/', 'ON DELETE SET NULL', $fk['definition'], 1);
            if ($definition === null || $definition === $fk['definition']) {
                continue;
            }
            DB::statement(sprintf('ALTER TABLE %s DROP CONSTRAINT %s, ADD CONSTRAINT %s %s',
                self::quote($fk['table']), self::quote($fk['constraint']), self::quote($fk['constraint']), $definition));
        }
    }

    /**
     * FKs compuestas con ON DELETE SET NULL del esquema público, con sus columnas y cuáles son NOT NULL.
     *
     * @return list<array{table: string, constraint: string, definition: string, columns: list<string>, not_null: list<string>}>
     */
    public static function compositeSetNullForeignKeys(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT cl.relname AS table_name, c.conname AS constraint_name, pg_get_constraintdef(c.oid) AS definition,
                   array_to_json(ARRAY(SELECT a.attname FROM unnest(c.conkey) WITH ORDINALITY k(attnum, ord)
                                       JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum ORDER BY k.ord)) AS columns,
                   array_to_json(ARRAY(SELECT a.attname FROM unnest(c.conkey) k(attnum)
                                       JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum WHERE a.attnotnull)) AS not_null
            FROM pg_constraint c
            JOIN pg_class cl ON cl.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = cl.relnamespace
            WHERE c.contype = 'f' AND c.confdeltype = 'n' AND array_length(c.conkey, 1) > 1 AND n.nspname = 'public'
            ORDER BY cl.relname, c.conname
            SQL);

        return array_map(fn (object $r): array => [
            'table' => (string) $r->table_name,
            'constraint' => (string) $r->constraint_name,
            'definition' => (string) $r->definition,
            'columns' => array_values((array) json_decode((string) $r->columns, true)),
            'not_null' => array_values((array) json_decode((string) $r->not_null, true)),
        ], $rows);
    }

    private static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
