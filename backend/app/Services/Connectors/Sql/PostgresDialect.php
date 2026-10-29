<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

use Generator;
use PDO;

/**
 * PostgreSQL. Transacción REPEATABLE READ + READ ONLY (instantánea coherente y sin escritura posible),
 * statement_timeout y lectura por cursor del servidor (páginas de N filas, sin cargar todo en memoria).
 */
final class PostgresDialect extends AbstractSqlDialect
{
    private const CURSOR = 'predictily_cursor';

    public function name(): string
    {
        return 'pgsql';
    }

    public function defaultPort(): int
    {
        return 5432;
    }

    public function connect(SqlConnectionSettings $settings, string $username, string $password): PDO
    {
        $this->requireDriver();
        $this->assertSafeDsnPart('host', $settings->host);
        $this->assertSafeDsnPart('database', $settings->database);
        if (! in_array($settings->sslMode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            throw new \InvalidArgumentException('sslmode no válido.');
        }
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=%d;application_name=predictily-sync',
            $settings->host, $settings->port, $settings->database, $settings->sslMode, $settings->connectTimeout);

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
        ]);
    }

    public function begin(PDO $pdo, SqlConnectionSettings $settings): void
    {
        $this->assertTimezone($settings->sourceTimezone);
        $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $pdo->exec('SET LOCAL statement_timeout = '.($settings->statementTimeout * 1000));
        $pdo->exec('SET LOCAL lock_timeout = 5000');
        $pdo->exec('SET LOCAL idle_in_transaction_session_timeout = '.(($settings->statementTimeout + 60) * 1000));
        $pdo->exec('SET LOCAL TIME ZONE '.$pdo->quote($settings->sourceTimezone));
        $pdo->exec("SET LOCAL client_encoding = 'UTF8'");
    }

    public function end(PDO $pdo): void
    {
        try {
            $pdo->exec('ROLLBACK');
        } catch (\Throwable) {
            // sin transacción activa
        }
    }

    public function stream(PDO $pdo, string $sql, int $pageSize): Generator
    {
        $pageSize = max(1, $pageSize);
        $pdo->exec('DECLARE '.self::CURSOR.' NO SCROLL CURSOR FOR '.$sql);
        try {
            while (true) {
                $rows = $pdo->query('FETCH FORWARD '.$pageSize.' FROM '.self::CURSOR)->fetchAll(PDO::FETCH_ASSOC);
                if ($rows === []) {
                    return;
                }
                yield $rows;
                if (count($rows) < $pageSize) {
                    return;
                }
            }
        } finally {
            try {
                $pdo->exec('CLOSE '.self::CURSOR);
            } catch (\Throwable) {
                // transacción abortada por un error previo
            }
        }
    }

    public function preview(PDO $pdo, string $sql, int $limit): array
    {
        foreach ($this->stream($pdo, $sql, $limit) as $rows) {
            return $rows;
        }

        return [];
    }

    public function writePrivilegeWarnings(PDO $pdo): array
    {
        $row = $pdo->query(
            "SELECT r.rolsuper AS superuser, has_database_privilege(current_user, current_database(), 'CREATE') AS can_create, "
            ."EXISTS (SELECT 1 FROM information_schema.table_privileges p WHERE p.grantee = current_user AND p.privilege_type IN ('INSERT', 'UPDATE', 'DELETE') "
            ."AND p.table_schema NOT IN ('pg_catalog', 'information_schema')) AS can_write "
            .'FROM pg_roles r WHERE r.rolname = current_user'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $warnings = [];
        if (! empty($row['superuser'])) {
            $warnings[] = 'La cuenta es superusuario de PostgreSQL. Use una cuenta de solo lectura (GRANT SELECT).';
        } elseif (! empty($row['can_write']) || ! empty($row['can_create'])) {
            $warnings[] = 'La cuenta tiene privilegios de escritura/creación en la base de datos. Predictily solo lee (transacción READ ONLY), pero se recomienda una cuenta con GRANT SELECT únicamente.';
        }

        return $warnings;
    }

    protected function versionSql(): string
    {
        return 'SHOW server_version';
    }
}
