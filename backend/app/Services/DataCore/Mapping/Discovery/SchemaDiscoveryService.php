<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Discovery;

use App\Models\DataSource;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Connectors\Sql\SqlConnectorInterface;
use App\Services\Connectors\Sql\SqlSourceConnection;
use App\Services\Connectors\Sql\SqlSourceException;
use Illuminate\Validation\ValidationException;
use PDO;
use PDOException;

/**
 * Descubrimiento de esquema de SOLO LECTURA de una fuente SQL (V2.5 §4; docs/integrations/AUTO_MAPPING.md §2).
 *
 * - La conexión la abre el conector de la fuente: política SSRF (SqlHostPolicy: lista blanca, IP fijada), transacción de solo lectura (PostgreSQL,
 *   MySQL/MariaDB), `ApplicationIntent=ReadOnly` y `LOCK_TIMEOUT` (SQL Server) y tiempo máximo de sentencia.
 * - Solo ejecuta las consultas de CATÁLOGO fijas de `CatalogDialect` (constantes; ningún texto del usuario ni del origen entra en ellas). No pasan por
 *   SqlQueryGuard (que rechaza `information_schema`/`sys`/`pg_*` en consultas escritas por personas) porque no hay entrada externa que validar.
 * - Devuelve metadatos: tablas, vistas, columnas con su clase de tipo, claves primarias y foráneas y filas ESTIMADAS (estadística del catálogo, nunca
 *   COUNT(*)). Ningún valor de negocio sale de aquí.
 */
final class SchemaDiscoveryService
{
    public function __construct(private readonly ConnectorRegistry $connectors) {}

    /** @throws ValidationException la fuente no es un conector SQL de solo lectura */
    public function connectorFor(DataSource $source): SqlConnectorInterface
    {
        $connector = $this->connectors->get((string) $source->connector_key);
        if (! $connector instanceof SqlConnectorInterface) {
            throw ValidationException::withMessages(['data_source' => ['La fuente no es un conector SQL de solo lectura: el descubrimiento de esquema solo aplica a PostgreSQL, MySQL/MariaDB y SQL Server.']]);
        }

        return $connector;
    }

    /** @throws SqlSourceException */
    public function discover(DataSource $source): DiscoveredSchema
    {
        $connector = $this->connectorFor($source);
        $connection = $connector->open($source);
        try {
            return $this->readFromConnection($connection);
        } finally {
            $connection->close();
        }
    }

    /** @throws SqlSourceException */
    public function readFromConnection(SqlSourceConnection $connection): DiscoveredSchema
    {
        return $this->readCatalog($connection->pdo(), self::catalogFor($connection->dialect()->name()));
    }

    public static function catalogFor(string $dialect): CatalogDialect
    {
        return match ($dialect) {
            'pgsql' => new PostgresCatalog,
            'mysql' => new MySqlCatalog,
            'sqlsrv' => new SqlServerCatalog,
            default => throw new SqlSourceException('Dialecto SQL no soportado por el descubrimiento: '.$dialect),
        };
    }

    /**
     * Ejecuta las consultas de catálogo fijas sobre una conexión ya abierta y de solo lectura.
     *
     * @throws SqlSourceException
     */
    public function readCatalog(PDO $pdo, CatalogDialect $catalog): DiscoveredSchema
    {
        try {
            foreach ($catalog->sessionStatements() as $statement) {
                $pdo->exec($statement);
            }
            $maxTables = (int) config('mapping.discovery.max_tables', 2000);
            $maxColumns = (int) config('mapping.discovery.max_columns', 60000);
            $tables = $this->fetch($pdo, $catalog->tablesQuery(), $maxTables + 1);
            $columns = $this->fetch($pdo, $catalog->columnsQuery(), $maxColumns + 1);
            $primaryKeys = $this->fetch($pdo, $catalog->primaryKeysQuery(), $maxColumns);
            $foreignKeys = $this->fetch($pdo, $catalog->foreignKeysQuery(), $maxColumns);
        } catch (PDOException $e) {
            throw new SqlSourceException(SqlSourceConnection::safeMessage($e));
        }

        return self::build($catalog->dialect(), $tables, $columns, $primaryKeys, $foreignKeys, $maxTables, $maxColumns);
    }

    /**
     * Arma el esquema a partir de las filas del catálogo (la misma entrada que devuelven las consultas de cada motor; las pruebas de SQL Server y MySQL
     * usan fixtures con esta forma).
     *
     * @param  list<array<string, mixed>>  $tables
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $primaryKeys
     * @param  list<array<string, mixed>>  $foreignKeys
     */
    public static function build(string $dialect, array $tables, array $columns, array $primaryKeys, array $foreignKeys, int $maxTables = 2000, int $maxColumns = 60000): DiscoveredSchema
    {
        $truncated = count($tables) > $maxTables || count($columns) > $maxColumns;
        $tables = array_slice(array_map(fn (array $r) => array_change_key_case($r, CASE_LOWER), $tables), 0, $maxTables);
        $columns = array_slice(array_map(fn (array $r) => array_change_key_case($r, CASE_LOWER), $columns), 0, $maxColumns);

        $built = [];
        foreach ($tables as $row) {
            $table = new DiscoveredTable(self::nullIfEmpty($row['schema_name'] ?? null), (string) $row['table_name'], (string) ($row['kind'] ?? 'table') === 'view' ? 'view' : 'table',
                isset($row['estimated_rows']) && is_numeric($row['estimated_rows']) ? max(0, (int) $row['estimated_rows']) : null);
            $built[$table->key()] = $table;
        }
        foreach ($columns as $row) {
            $key = DiscoveredTable::keyOf(self::nullIfEmpty($row['schema_name'] ?? null), (string) $row['table_name']);
            if (! isset($built[$key])) {
                continue;
            }
            $dataType = (string) ($row['data_type'] ?? '');
            $built[$key]->columns[] = new DiscoveredColumn(
                (string) $row['column_name'], $dataType, TypeClass::classify($dialect, $dataType, isset($row['column_type']) ? (string) $row['column_type'] : null),
                strtoupper((string) ($row['is_nullable'] ?? 'YES')) !== 'NO', (int) ($row['ordinal_position'] ?? 0),
                isset($row['max_length']) && is_numeric($row['max_length']) ? (int) $row['max_length'] : null,
            );
        }
        $pk = [];
        foreach (array_map(fn (array $r) => array_change_key_case($r, CASE_LOWER), $primaryKeys) as $row) {
            $key = DiscoveredTable::keyOf(self::nullIfEmpty($row['schema_name'] ?? null), (string) $row['table_name']);
            if (isset($built[$key])) {
                $pk[$key][(int) ($row['position'] ?? 0)] = (string) $row['column_name'];
            }
        }
        foreach ($pk as $key => $positions) {
            ksort($positions);
            $built[$key]->primaryKey = array_values($positions);
        }
        $fk = [];
        foreach (array_map(fn (array $r) => array_change_key_case($r, CASE_LOWER), $foreignKeys) as $row) {
            $key = DiscoveredTable::keyOf(self::nullIfEmpty($row['schema_name'] ?? null), (string) $row['table_name']);
            if (! isset($built[$key]) || ($row['ref_table'] ?? null) === null || ($row['ref_column'] ?? null) === null) {
                continue;
            }
            $fk[$key][(string) $row['constraint_name']]['ref_schema'] = self::nullIfEmpty($row['ref_schema'] ?? null);
            $fk[$key][(string) $row['constraint_name']]['ref_table'] = (string) $row['ref_table'];
            $fk[$key][(string) $row['constraint_name']]['pairs'][(int) ($row['position'] ?? 0)] = [(string) $row['column_name'], (string) $row['ref_column']];
        }
        foreach ($fk as $key => $constraints) {
            foreach ($constraints as $name => $constraint) {
                ksort($constraint['pairs']);
                $built[$key]->foreignKeys[] = new ForeignKey($name, array_column($constraint['pairs'], 0), $constraint['ref_schema'], $constraint['ref_table'], array_column($constraint['pairs'], 1));
            }
        }

        return new DiscoveredSchema($dialect, array_values($built), $truncated);
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws PDOException
     */
    private function fetch(PDO $pdo, string $sql, int $limit): array
    {
        $statement = $pdo->query($sql);
        $rows = [];
        while (count($rows) < $limit && ($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $row;
        }
        $statement->closeCursor();

        return $rows;
    }

    private static function nullIfEmpty(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
