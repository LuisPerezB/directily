<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

use DateTimeImmutable;
use Generator;
use PDO;

/**
 * MySQL / MariaDB. Transacción START TRANSACTION READ ONLY, tiempo máximo de sentencia
 * (max_execution_time en MySQL; max_statement_time en MariaDB) y lectura sin búfer (streaming).
 * Estado: implementado, SIN servidor de prueba en este entorno (ver docs/architecture/v2/P3-datos.md).
 */
final class MySqlDialect extends AbstractSqlDialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function defaultPort(): int
    {
        return 3306;
    }

    public function configKeys(): array
    {
        return [...parent::configKeys(), 'charset'];
    }

    public function connect(SqlConnectionSettings $settings, string $username, string $password): PDO
    {
        $this->requireDriver();
        $this->assertSafeDsnPart('host', $settings->host);
        $this->assertSafeDsnPart('database', $settings->database);
        $this->assertSafeDsnPart('charset', $settings->charset, '/^[a-z0-9_]+$/');
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_TIMEOUT => $settings->connectTimeout,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ];
        if (in_array($settings->sslMode, ['require', 'verify-ca', 'verify-full'], true) && defined('PDO::MYSQL_ATTR_SSL_CIPHER')) {
            $options[PDO::MYSQL_ATTR_SSL_CIPHER] = 'DEFAULT';
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $settings->host, $settings->port, $settings->database, $settings->charset);

        return new PDO($dsn, $username, $password, $options);
    }

    public function begin(PDO $pdo, SqlConnectionSettings $settings): void
    {
        $this->assertTimezone($settings->sourceTimezone);
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->exec('START TRANSACTION READ ONLY');
        if (stripos($this->serverVersion($pdo), 'mariadb') !== false) {
            $pdo->exec('SET SESSION max_statement_time = '.$settings->statementTimeout);
        } else {
            $pdo->exec('SET SESSION max_execution_time = '.($settings->statementTimeout * 1000));
        }
        $offset = (new DateTimeImmutable('now', new \DateTimeZone($settings->sourceTimezone)))->format('P');
        $pdo->exec('SET SESSION time_zone = '.$pdo->quote($offset));
    }

    public function stream(PDO $pdo, string $sql, int $pageSize): Generator
    {
        $pageSize = max(1, $pageSize);
        $statement = $pdo->query($sql);
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
        $statement = $pdo->query('SELECT * FROM ('.$sql.') AS predictily_preview LIMIT '.max(1, $limit));
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return $rows;
    }

    public function writePrivilegeWarnings(PDO $pdo): array
    {
        $grants = [];
        foreach ($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_NUM) as $row) {
            $grants[] = strtoupper((string) $row[0]);
        }
        foreach ($grants as $grant) {
            if (preg_match('/\b(ALL PRIVILEGES|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER)\b/', $grant) === 1) {
                return ['La cuenta tiene privilegios de escritura/DDL. Predictily solo lee (transacción READ ONLY), pero se recomienda una cuenta con GRANT SELECT únicamente.'];
            }
        }

        return [];
    }

    protected function versionSql(): string
    {
        return 'SELECT VERSION()';
    }
}
