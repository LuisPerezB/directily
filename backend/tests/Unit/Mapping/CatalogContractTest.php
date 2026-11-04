<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Services\Connectors\ConnectorRegistry;
use App\Services\Connectors\Sql\SqlQueryGuard;
use App\Services\Connectors\Sql\SqlQueryRejected;
use App\Services\DataCore\Mapping\Discovery\CatalogDialect;
use App\Services\DataCore\Mapping\Discovery\MySqlCatalog;
use App\Services\DataCore\Mapping\Discovery\PostgresCatalog;
use App\Services\DataCore\Mapping\Discovery\SchemaDiscoveryService;
use App\Services\DataCore\Mapping\Discovery\SqlServerCatalog;
use App\Services\DataCore\Mapping\Discovery\TypeClass;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/** Las consultas de catálogo son FIJAS, de solo lectura y están aisladas de SqlQueryGuard; SQL Server lee en READ UNCOMMITTED. */
final class CatalogContractTest extends TestCase
{
    /** @return array<string, array{0: CatalogDialect}> */
    public static function catalogs(): array
    {
        return ['pgsql' => [new PostgresCatalog], 'mysql' => [new MySqlCatalog], 'sqlsrv' => [new SqlServerCatalog]];
    }

    #[DataProvider('catalogs')]
    public function test_catalog_queries_are_fixed_select_only_and_take_no_input(CatalogDialect $catalog): void
    {
        foreach (['tablesQuery', 'columnsQuery', 'primaryKeysQuery', 'foreignKeysQuery'] as $method) {
            $this->assertSame(0, (new ReflectionMethod($catalog, $method))->getNumberOfParameters(), "{$method} no recibe parámetros: no hay texto de usuario que concatenar");
        }
        $this->assertCount(4, $catalog->queries());
        foreach ($catalog->queries() as $name => $sql) {
            $this->assertMatchesRegularExpression('/^\s*SELECT\b/i', $sql, $name);
            $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|CREATE|TRUNCATE|GRANT|EXEC|EXECUTE|INTO)\b/i', $sql, "{$name} no escribe");
            $this->assertStringNotContainsString(';', $sql, $name);
            $this->assertStringNotContainsString('$', $sql, $name);
            $this->assertStringNotContainsString(':', str_replace('::', '', $sql), "{$name}: sin parámetros");
            $this->assertSame($sql, $catalog->queries()[$name], 'determinista');
        }
        // Solo metadatos: ninguna consulta de catálogo lee tablas de negocio (todas parten del catálogo del motor).
        foreach ($catalog->queries() as $name => $sql) {
            $this->assertMatchesRegularExpression('/\b(pg_catalog|information_schema|INFORMATION_SCHEMA|sys\.)/', $sql, $name);
        }
    }

    #[DataProvider('catalogs')]
    public function test_catalog_queries_are_isolated_from_the_user_query_guard(CatalogDialect $catalog): void
    {
        // El guard de consultas escritas por personas rechaza el catálogo del sistema: por eso el descubrimiento NO pasa por él (y por eso sus consultas no pueden venir de un usuario).
        foreach ($catalog->queries() as $name => $sql) {
            try {
                SqlQueryGuard::validate($sql, $catalog->dialect());
                $this->fail("{$catalog->dialect()}/{$name}: el guard debía rechazar el catálogo de sistema");
            } catch (SqlQueryRejected $e) {
                $this->assertContains($e->reason, ['system_object', 'parameter', 'invalid_character', 'forbidden_keyword'], "{$catalog->dialect()}/{$name}: {$e->reason}");
            }
        }
    }

    public function test_sql_server_reads_catalog_in_read_uncommitted_before_any_catalog_query(): void
    {
        $pdo = new RecordingPdo;
        (new SchemaDiscoveryService(new ConnectorRegistry))->readCatalog($pdo, new SqlServerCatalog);

        $this->assertSame('SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED', $pdo->log[0]);
        $this->assertCount(5, $pdo->log, 'la sentencia de sesión y las 4 consultas de catálogo, nada más');
        $this->assertSame(array_values((new SqlServerCatalog)->queries()), array_slice($pdo->log, 1));
    }

    public function test_postgres_and_mysql_need_no_session_statements_because_the_connector_already_opened_a_read_only_transaction(): void
    {
        foreach ([new PostgresCatalog, new MySqlCatalog] as $catalog) {
            $this->assertSame([], $catalog->sessionStatements());
            $pdo = new RecordingPdo;
            (new SchemaDiscoveryService(new ConnectorRegistry))->readCatalog($pdo, $catalog);
            $this->assertSame(array_values($catalog->queries()), $pdo->log);
        }
    }

    public function test_mysql_and_sql_server_fixture_catalogs_are_classified_with_native_types(): void
    {
        $mysql = SchemaDiscoveryService::build('mysql',
            [['schema_name' => null, 'table_name' => 'factura', 'kind' => 'table', 'estimated_rows' => '1200']],
            [
                ['schema_name' => null, 'table_name' => 'factura', 'column_name' => 'id', 'data_type' => 'int', 'column_type' => 'int(11)', 'is_nullable' => 'NO', 'ordinal_position' => 1, 'max_length' => null],
                ['schema_name' => null, 'table_name' => 'factura', 'column_name' => 'anulada', 'data_type' => 'tinyint', 'column_type' => 'tinyint(1)', 'is_nullable' => 'YES', 'ordinal_position' => 2, 'max_length' => null],
                ['schema_name' => null, 'table_name' => 'factura', 'column_name' => 'total', 'data_type' => 'decimal', 'column_type' => 'decimal(14,2)', 'is_nullable' => 'YES', 'ordinal_position' => 3, 'max_length' => null],
                ['schema_name' => null, 'table_name' => 'factura', 'column_name' => 'creado', 'data_type' => 'datetime', 'column_type' => 'datetime', 'is_nullable' => 'YES', 'ordinal_position' => 4, 'max_length' => null],
                ['schema_name' => null, 'table_name' => 'factura', 'column_name' => 'tipo', 'data_type' => 'enum', 'column_type' => "enum('F','N')", 'is_nullable' => 'YES', 'ordinal_position' => 5, 'max_length' => null],
            ], [['schema_name' => null, 'table_name' => 'factura', 'column_name' => 'id', 'position' => 1]], []);
        $types = array_column(array_map(fn ($c) => ['n' => $c->name, 't' => $c->typeClass->value], $mysql->table(null, 'factura')->columns), 't', 'n');
        $this->assertSame(['id' => 'integer', 'anulada' => 'boolean', 'total' => 'decimal', 'creado' => 'datetime', 'tipo' => 'text'], $types);
        $this->assertSame(1200, $mysql->table(null, 'factura')->estimatedRows);
        $this->assertSame(['id'], $mysql->table(null, 'factura')->primaryKey);

        $sqlsrv = SchemaDiscoveryService::build('sqlsrv',
            [['schema_name' => 'dbo', 'table_name' => 'Factura', 'kind' => 'table', 'estimated_rows' => 50], ['schema_name' => 'dbo', 'table_name' => 'vw_ventas', 'kind' => 'view', 'estimated_rows' => null]],
            [
                ['schema_name' => 'dbo', 'table_name' => 'Factura', 'column_name' => 'Id', 'data_type' => 'bigint', 'column_type' => null, 'is_nullable' => 'NO', 'ordinal_position' => 1, 'max_length' => null],
                ['schema_name' => 'dbo', 'table_name' => 'Factura', 'column_name' => 'Precio', 'data_type' => 'money', 'column_type' => null, 'is_nullable' => 'YES', 'ordinal_position' => 2, 'max_length' => null],
                ['schema_name' => 'dbo', 'table_name' => 'Factura', 'column_name' => 'Fecha', 'data_type' => 'datetime2', 'column_type' => null, 'is_nullable' => 'YES', 'ordinal_position' => 3, 'max_length' => null],
                ['schema_name' => 'dbo', 'table_name' => 'Factura', 'column_name' => 'Nombre', 'data_type' => 'nvarchar', 'column_type' => null, 'is_nullable' => 'YES', 'ordinal_position' => 4, 'max_length' => 120],
                ['schema_name' => 'dbo', 'table_name' => 'Factura', 'column_name' => 'Activo', 'data_type' => 'bit', 'column_type' => null, 'is_nullable' => 'NO', 'ordinal_position' => 5, 'max_length' => null],
                ['schema_name' => 'dbo', 'table_name' => 'Factura', 'column_name' => 'rv', 'data_type' => 'timestamp', 'column_type' => null, 'is_nullable' => 'NO', 'ordinal_position' => 6, 'max_length' => null],
            ],
            [['schema_name' => 'dbo', 'table_name' => 'Factura', 'column_name' => 'Id', 'position' => 1]],
            [['constraint_name' => 'FK_x', 'schema_name' => 'dbo', 'table_name' => 'Factura', 'column_name' => 'Id', 'ref_schema' => 'dbo', 'ref_table' => 'Otra', 'ref_column' => 'Id', 'position' => 1]]);
        $types = array_column(array_map(fn ($c) => ['n' => $c->name, 't' => $c->typeClass->value], $sqlsrv->table('dbo', 'Factura')->columns), 't', 'n');
        $this->assertSame(['Id' => 'integer', 'Precio' => 'decimal', 'Fecha' => 'datetime', 'Nombre' => 'text', 'Activo' => 'boolean', 'rv' => 'binary'], $types, 'timestamp de SQL Server es rowversion, no fecha');
        $this->assertSame('view', $sqlsrv->table('dbo', 'vw_ventas')->kind);
        $this->assertSame(['Id'], $sqlsrv->table('dbo', 'Factura')->foreignKeys[0]->columns, 'la clave foránea se conserva; el Auto Mapper solo une tablas que están en el catálogo legible');
    }

    public function test_type_classes_cover_the_native_types_of_each_engine(): void
    {
        $cases = [
            ['pgsql', 'character varying', null, TypeClass::Text], ['pgsql', 'numeric', null, TypeClass::Decimal], ['pgsql', 'timestamp with time zone', null, TypeClass::DateTime],
            ['pgsql', 'date', null, TypeClass::Date], ['pgsql', 'boolean', null, TypeClass::Boolean], ['pgsql', 'bytea', null, TypeClass::Binary], ['pgsql', 'jsonb', null, TypeClass::Other],
            ['mysql', 'tinyint', 'tinyint(1)', TypeClass::Boolean], ['mysql', 'tinyint', 'tinyint(4)', TypeClass::Integer], ['mysql', 'longtext', null, TypeClass::Text], ['mysql', 'double', null, TypeClass::Decimal],
            ['sqlsrv', 'smalldatetime', null, TypeClass::DateTime], ['sqlsrv', 'uniqueidentifier', null, TypeClass::Text], ['sqlsrv', 'smallmoney', null, TypeClass::Decimal], ['sqlsrv', 'timestamp', null, TypeClass::Binary],
        ];
        foreach ($cases as [$dialect, $type, $full, $expected]) {
            $this->assertSame($expected, TypeClass::classify($dialect, $type, $full), "{$dialect}/{$type}");
        }
    }
}

/** PDO que solo registra lo que se ejecuta (sin servidor): devuelve conjuntos vacíos. */
final class RecordingPdo extends PDO
{
    /** @var list<string> */
    public array $log = [];

    public function __construct() {}

    public function exec(string $statement): int|false
    {
        $this->log[] = $statement;

        return 0;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->log[] = $query;

        return new EmptyStatement;
    }
}

final class EmptyStatement extends PDOStatement
{
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return false;
    }

    public function closeCursor(): bool
    {
        return true;
    }
}
