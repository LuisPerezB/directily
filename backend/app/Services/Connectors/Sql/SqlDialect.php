<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

use DateTimeInterface;
use Generator;
use PDO;

/**
 * Diferencias entre motores. Todo acceso es de solo lectura: el dialecto abre una transacción de solo lectura
 * cuando el motor la soporta y aplica el tiempo máximo de sentencia.
 */
interface SqlDialect
{
    /** pgsql | mysql | sqlsrv (coincide con la extensión PDO) */
    public function name(): string;

    public function defaultPort(): int;

    public function driverAvailable(): bool;

    /** @return list<string> claves aceptadas en data_sources.config */
    public function configKeys(): array;

    public function connect(SqlConnectionSettings $settings, string $username, string $password): PDO;

    /** Abre la transacción de solo lectura y aplica límites (tiempo de sentencia, zona horaria de la fuente). */
    public function begin(PDO $pdo, SqlConnectionSettings $settings): void;

    public function end(PDO $pdo): void;

    /**
     * Lee el resultado de la consulta por páginas (memoria acotada).
     *
     * @return Generator<int, list<array<string, mixed>>>
     */
    public function stream(PDO $pdo, string $sql, int $pageSize): Generator;

    /** Primeras $limit filas (vista previa) sin leer el resto. @return list<array<string, mixed>> */
    public function preview(PDO $pdo, string $sql, int $limit): array;

    /** Literal SQL de fecha-hora local de la fuente para :since (formato fijo generado por Predictily). */
    public function sinceLiteral(DateTimeInterface $localTime): string;

    public function serverVersion(PDO $pdo): string;

    /**
     * Advertencias sobre permisos de escritura de la cuenta (la política real es una cuenta de solo lectura).
     *
     * @return list<string>
     */
    public function writePrivilegeWarnings(PDO $pdo): array;
}
