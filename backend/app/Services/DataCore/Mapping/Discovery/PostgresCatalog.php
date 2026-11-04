<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Discovery;

/** Catálogo de PostgreSQL (pg_catalog + information_schema). Transacción de solo lectura ya abierta por el dialecto del conector. */
final class PostgresCatalog implements CatalogDialect
{
    private const SYSTEM_SCHEMAS = "n.nspname !~ '^pg_' AND n.nspname <> 'information_schema'";

    public function dialect(): string
    {
        return 'pgsql';
    }

    public function sessionStatements(): array
    {
        return [];
    }

    public function tablesQuery(): string
    {
        return <<<'SQL'
SELECT n.nspname AS schema_name, c.relname AS table_name,
       CASE WHEN c.relkind IN ('r', 'p') THEN 'table' ELSE 'view' END AS kind,
       CASE WHEN c.reltuples < 0 THEN NULL ELSE c.reltuples::bigint END AS estimated_rows
FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
WHERE c.relkind IN ('r', 'p', 'v', 'm') AND n.nspname !~ '^pg_' AND n.nspname <> 'information_schema'
  AND pg_catalog.has_table_privilege(c.oid, 'SELECT')
ORDER BY n.nspname, c.relname
SQL;
    }

    public function columnsQuery(): string
    {
        return <<<'SQL'
SELECT c.table_schema AS schema_name, c.table_name AS table_name, c.column_name AS column_name,
       CASE WHEN c.data_type IN ('ARRAY', 'USER-DEFINED') THEN c.udt_name ELSE c.data_type END AS data_type,
       NULL AS column_type, c.is_nullable AS is_nullable, c.ordinal_position AS ordinal_position, c.character_maximum_length AS max_length
FROM information_schema.columns c
WHERE c.table_schema !~ '^pg_' AND c.table_schema <> 'information_schema'
ORDER BY c.table_schema, c.table_name, c.ordinal_position
SQL;
    }

    public function primaryKeysQuery(): string
    {
        return <<<'SQL'
SELECT n.nspname AS schema_name, cl.relname AS table_name, a.attname AS column_name, k.ord AS position
FROM pg_catalog.pg_constraint con
JOIN pg_catalog.pg_class cl ON cl.oid = con.conrelid
JOIN pg_catalog.pg_namespace n ON n.oid = cl.relnamespace
CROSS JOIN LATERAL unnest(con.conkey) WITH ORDINALITY AS k(attnum, ord)
JOIN pg_catalog.pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum
WHERE con.contype = 'p' AND n.nspname !~ '^pg_' AND n.nspname <> 'information_schema'
ORDER BY n.nspname, cl.relname, k.ord
SQL;
    }

    public function foreignKeysQuery(): string
    {
        return <<<'SQL'
SELECT con.conname AS constraint_name, n.nspname AS schema_name, cl.relname AS table_name, a.attname AS column_name,
       fn.nspname AS ref_schema, fcl.relname AS ref_table, fa.attname AS ref_column, k.ord AS position
FROM pg_catalog.pg_constraint con
JOIN pg_catalog.pg_class cl ON cl.oid = con.conrelid
JOIN pg_catalog.pg_namespace n ON n.oid = cl.relnamespace
JOIN pg_catalog.pg_class fcl ON fcl.oid = con.confrelid
JOIN pg_catalog.pg_namespace fn ON fn.oid = fcl.relnamespace
CROSS JOIN LATERAL unnest(con.conkey, con.confkey) WITH ORDINALITY AS k(attnum, fattnum, ord)
JOIN pg_catalog.pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum
JOIN pg_catalog.pg_attribute fa ON fa.attrelid = con.confrelid AND fa.attnum = k.fattnum
WHERE con.contype = 'f' AND n.nspname !~ '^pg_' AND n.nspname <> 'information_schema'
ORDER BY n.nspname, cl.relname, con.conname, k.ord
SQL;
    }

    public function queries(): array
    {
        return ['tables' => $this->tablesQuery(), 'columns' => $this->columnsQuery(), 'primary_keys' => $this->primaryKeysQuery(), 'foreign_keys' => $this->foreignKeysQuery()];
    }

    public function identifierQuotes(): array
    {
        return ['"', '"'];
    }
}
