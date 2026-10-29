<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

use DateTimeInterface;
use InvalidArgumentException;
use PDO;
use RuntimeException;

abstract class AbstractSqlDialect implements SqlDialect
{
    public function driverAvailable(): bool
    {
        return extension_loaded('pdo_'.$this->name()) && in_array($this->name(), PDO::getAvailableDrivers(), true);
    }

    public function configKeys(): array
    {
        return ['host', 'port', 'database', 'sslmode', 'source_timezone', 'connect_timeout_seconds', 'statement_timeout_seconds'];
    }

    public function end(PDO $pdo): void
    {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (\Throwable) {
            // La conexión se cierra a continuación; la transacción de solo lectura no deja efectos.
        }
    }

    public function sinceLiteral(DateTimeInterface $localTime): string
    {
        return "'".$localTime->format('Y-m-d H:i:s')."'";
    }

    public function serverVersion(PDO $pdo): string
    {
        return (string) $pdo->query($this->versionSql())->fetchColumn();
    }

    abstract protected function versionSql(): string;

    /** Las partes del DSN no pueden contener separadores: se validan antes de armar la cadena. */
    protected function assertSafeDsnPart(string $label, string $value, string $pattern = '/^[A-Za-z0-9._:\-]+$/'): void
    {
        if ($value === '' || preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException("Valor no válido para «{$label}» en la configuración de la fuente.");
        }
    }

    protected function assertTimezone(string $timezone): void
    {
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('Zona horaria de la fuente no válida.');
        }
    }

    /** @return list<array<string, mixed>> */
    protected function fetchPage(\PDOStatement $statement, int $size): array
    {
        $rows = [];
        while (count($rows) < $size && ($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    protected function requireDriver(): void
    {
        if (! $this->driverAvailable()) {
            throw new RuntimeException('Falta la extensión PHP pdo_'.$this->name().' en este servidor.');
        }
    }
}
