<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Discovery;

/** Catálogo de MySQL/MariaDB (information_schema de la base de datos de la conexión). Transacción READ ONLY ya abierta por el dialecto. */
final class MySqlCatalog implements CatalogDialect
{
    public function dialect(): string
    {
        return 'mysql';
    }

    public function sessionStatements(): array
    {
        return [];
    }

    public function tablesQuery(): string
    {
        return <<<'SQL'
SELECT NULL AS schema_name, t.TABLE_NAME AS table_name,
       CASE WHEN t.TABLE_TYPE = 'VIEW' THEN 'view' ELSE 'table' END AS kind,
       t.TABLE_ROWS AS estimated_rows
FROM information_schema.TABLES t
WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE IN ('BASE TABLE', 'VIEW')
ORDER BY t.TABLE_NAME
SQL;
    }

    public function columnsQuery(): string
    {
        return <<<'SQL'
SELECT NULL AS schema_name, c.TABLE_NAME AS table_name, c.COLUMN_NAME AS column_name, c.DATA_TYPE AS data_type, c.COLUMN_TYPE AS column_type,
       c.IS_NULLABLE AS is_nullable, c.ORDINAL_POSITION AS ordinal_position, c.CHARACTER_MAXIMUM_LENGTH AS max_length
FROM information_schema.COLUMNS c
WHERE c.TABLE_SCHEMA = DATABASE()
ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION
SQL;
    }

    public function primaryKeysQuery(): string
    {
        return <<<'SQL'
SELECT NULL AS schema_name, k.TABLE_NAME AS table_name, k.COLUMN_NAME AS column_name, k.ORDINAL_POSITION AS position
FROM information_schema.KEY_COLUMN_USAGE k
WHERE k.TABLE_SCHEMA = DATABASE() AND k.CONSTRAINT_NAME = 'PRIMARY'
ORDER BY k.TABLE_NAME, k.ORDINAL_POSITION
SQL;
    }

    public function foreignKeysQuery(): string
    {
        return <<<'SQL'
SELECT k.CONSTRAINT_NAME AS constraint_name, NULL AS schema_name, k.TABLE_NAME AS table_name, k.COLUMN_NAME AS column_name,
       NULL AS ref_schema, k.REFERENCED_TABLE_NAME AS ref_table, k.REFERENCED_COLUMN_NAME AS ref_column, k.ORDINAL_POSITION AS position
FROM information_schema.KEY_COLUMN_USAGE k
WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL AND k.REFERENCED_TABLE_SCHEMA = DATABASE()
ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION
SQL;
    }

    public function queries(): array
    {
        return ['tables' => $this->tablesQuery(), 'columns' => $this->columnsQuery(), 'primary_keys' => $this->primaryKeysQuery(), 'foreign_keys' => $this->foreignKeysQuery()];
    }

    public function identifierQuotes(): array
    {
        return ['`', '`'];
    }
}
