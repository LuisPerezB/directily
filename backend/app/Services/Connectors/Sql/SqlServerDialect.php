<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

use DateTimeInterface;
use Generator;
use PDO;

/**
 * SQL Server (extensión pdo_sqlsrv). SQL Server no ofrece transacciones de solo lectura: la garantía descansa en
 * SqlQueryGuard y en una cuenta limitada a db_datareader (la prueba de conexión avisa si la cuenta puede escribir).
 * Estado: implementado, SIN servidor ni extensión en este entorno (ver docs/architecture/v2/P3-datos.md).
 */
final class SqlServerDialect extends AbstractSqlDialect
{
    public function name(): string
    {
        return 'sqlsrv';
    }

    public function defaultPort(): int
    {
        return 1433;
    }

    public function configKeys(): array
    {
        return [...parent::configKeys(), 'encrypt', 'trust_server_certificate'];
    }

    public function connect(SqlConnectionSettings $settings, string $username, string $password): PDO
    {
        $this->requireDriver();
        $this->assertSafeDsnPart('host', $settings->host);
        $this->assertSafeDsnPart('database', $settings->database);
        $dsn = sprintf('sqlsrv:Server=%s,%d;Database=%s;LoginTimeout=%d;Encrypt=%d;TrustServerCertificate=%d;ApplicationIntent=ReadOnly;CharacterSet=UTF-8',
            $settings->host, $settings->port, $settings->database, $settings->connectTimeout, $settings->encrypt ? 1 : 0, $settings->trustServerCertificate ? 1 : 0);
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        if (defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
            $options[constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')] = $settings->statementTimeout;
        }

        return new PDO($dsn, $username, $password, $options);
    }

    public function begin(PDO $pdo, SqlConnectionSettings $settings): void
    {
        $pdo->exec('SET LOCK_TIMEOUT 5000');
        $pdo->exec('SET NOCOUNT ON');
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }

    public function stream(PDO $pdo, string $sql, int $pageSize): Generator
    {
        $pageSize = max(1, $pageSize);
        $statement = $pdo->prepare($sql, [PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY]);
        $statement->execute();
        try {
            while (($rows = $this->fetchPage($statement, $pageSize)) !== []) {
                yield $rows;
                if (count($rows) < $pageSize) {
                    return;
                }
            }
        } finally {
            $statement->closeCursor();
        }
    }

    public function preview(PDO $pdo, string $sql, int $limit): array
    {
        // Un derived table no admite ORDER BY ni WITH en SQL Server: para vista previa use una vista o una consulta sin ellos.
        $statement = $pdo->query('SELECT TOP ('.max(1, $limit).') * FROM ('.$sql.') AS predictily_preview');
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return $rows;
    }

    public function sinceLiteral(DateTimeInterface $localTime): string
    {
        return "CONVERT(datetime2, '".$localTime->format('Y-m-d H:i:s')."', 120)";
    }

    public function writePrivilegeWarnings(PDO $pdo): array
    {
        $can = $pdo->query("SELECT CASE WHEN HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'INSERT') = 1 OR HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'UPDATE') = 1 OR IS_MEMBER('db_owner') = 1 THEN 1 ELSE 0 END")->fetchColumn();

        return (int) $can === 1
            ? ['La cuenta puede escribir en la base de datos. SQL Server no ofrece transacciones de solo lectura: use una cuenta de solo lectura (db_datareader).']
            : [];
    }

    protected function versionSql(): string
    {
        return 'SELECT @@VERSION';
    }
}
