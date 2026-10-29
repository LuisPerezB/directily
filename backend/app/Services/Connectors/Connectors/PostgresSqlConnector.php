<?php

declare(strict_types=1);

namespace App\Services\Connectors\Connectors;

use App\Services\Connectors\Sql\PostgresDialect;
use App\Services\Connectors\Sql\SqlDialect;

/** PostgreSQL de solo lectura. Probado de punta a punta contra una base de origen de pruebas (E2-25). */
final class PostgresSqlConnector extends AbstractSqlConnector
{
    public function key(): string
    {
        return 'sql_postgresql';
    }

    public function name(): string
    {
        return 'Base de datos PostgreSQL (solo lectura)';
    }

    public function description(): string
    {
        return 'Extrae consultas/vistas SELECT autorizadas del ERP y las importa con el mismo flujo que un archivo (validación, idempotencia, procedencia). Transacción READ ONLY. Probado de punta a punta con una base PostgreSQL de pruebas; aún no con el ERP real de un cliente.';
    }

    public function status(): string
    {
        return 'requires_credentials';
    }

    public function verification(): string
    {
        return 'tested_with_test_database';
    }

    public function dialect(): SqlDialect
    {
        return new PostgresDialect;
    }

    protected function readOnlyEnforcement(): string
    {
        return 'transaction_read_only';
    }
}
