<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Discovery;

/**
 * Catálogo de SQL Server. Parte de infra/scripts/pilot/sql_source_discovery.php. SQL Server no ofrece transacciones de solo lectura: la conexión ya
 * usa `ApplicationIntent=ReadOnly` y `LOCK_TIMEOUT 5000`; el descubrimiento además lee en READ UNCOMMITTED para no bloquear a los escritores del ERP
 * (solo metadatos del catálogo: no hay lecturas sucias de datos de negocio). Estado: implementado, sin servidor de prueba (implemented_untested).
 */
final class SqlServerCatalog implements CatalogDialect
{
    public function dialect(): string
    {
        return 'sqlsrv';
    }

    public function sessionStatements(): array
    {
        return ['SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED'];
    }

    public function tablesQuery(): string
    {
        return <<<'SQL'
SELECT s.name AS schema_name, t.name AS table_name, 'table' AS kind, SUM(p.rows) AS estimated_rows
FROM sys.tables t
JOIN sys.schemas s ON s.schema_id = t.schema_id
JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0, 1)
WHERE t.is_ms_shipped = 0 AND s.name NOT IN ('sys', 'INFORMATION_SCHEMA')
GROUP BY s.name, t.name
UNION ALL
SELECT s.name AS schema_name, v.name AS table_name, 'view' AS kind, NULL AS estimated_rows
FROM sys.views v
JOIN sys.schemas s ON s.schema_id = v.schema_id
WHERE v.is_ms_shipped = 0 AND s.name NOT IN ('sys', 'INFORMATION_SCHEMA')
SQL;
    }

    public function columnsQuery(): string
    {
        return <<<'SQL'
SELECT c.TABLE_SCHEMA AS schema_name, c.TABLE_NAME AS table_name, c.COLUMN_NAME AS column_name, c.DATA_TYPE AS data_type, NULL AS column_type,
       c.IS_NULLABLE AS is_nullable, c.ORDINAL_POSITION AS ordinal_position, c.CHARACTER_MAXIMUM_LENGTH AS max_length
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE c.TABLE_SCHEMA NOT IN ('sys', 'INFORMATION_SCHEMA')
ORDER BY c.TABLE_SCHEMA, c.TABLE_NAME, c.ORDINAL_POSITION
SQL;
    }

    public function primaryKeysQuery(): string
    {
        return <<<'SQL'
SELECT tc.TABLE_SCHEMA AS schema_name, tc.TABLE_NAME AS table_name, kcu.COLUMN_NAME AS column_name, kcu.ORDINAL_POSITION AS position
FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu ON kcu.CONSTRAINT_NAME = tc.CONSTRAINT_NAME AND kcu.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND kcu.TABLE_NAME = tc.TABLE_NAME
WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY' AND tc.TABLE_SCHEMA NOT IN ('sys', 'INFORMATION_SCHEMA')
ORDER BY tc.TABLE_SCHEMA, tc.TABLE_NAME, kcu.ORDINAL_POSITION
SQL;
    }

    public function foreignKeysQuery(): string
    {
        return <<<'SQL'
SELECT fk.name AS constraint_name, OBJECT_SCHEMA_NAME(fk.parent_object_id) AS schema_name, OBJECT_NAME(fk.parent_object_id) AS table_name,
       COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS column_name,
       OBJECT_SCHEMA_NAME(fk.referenced_object_id) AS ref_schema, OBJECT_NAME(fk.referenced_object_id) AS ref_table,
       COL_NAME(fkc.referenced_object_id, fkc.referenced_column_id) AS ref_column, fkc.constraint_column_id AS position
FROM sys.foreign_keys fk
JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
WHERE fk.is_ms_shipped = 0
ORDER BY schema_name, table_name, fk.name, fkc.constraint_column_id
SQL;
    }

    public function queries(): array
    {
        return ['tables' => $this->tablesQuery(), 'columns' => $this->columnsQuery(), 'primary_keys' => $this->primaryKeysQuery(), 'foreign_keys' => $this->foreignKeysQuery()];
    }

    public function identifierQuotes(): array
    {
        return ['[', ']'];
    }
}
