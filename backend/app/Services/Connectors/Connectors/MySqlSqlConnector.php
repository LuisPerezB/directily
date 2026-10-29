<?php

declare(strict_types=1);

namespace App\Services\Connectors\Connectors;

use App\Services\Connectors\Sql\MySqlDialect;
use App\Services\Connectors\Sql\SqlDialect;

/** MySQL / MariaDB de solo lectura. Implementado; sin servidor de pruebas en este entorno: NO se declara disponible. */
final class MySqlSqlConnector extends AbstractSqlConnector
{
    public function key(): string
    {
        return 'sql_mysql';
    }

    public function name(): string
    {
        return 'Base de datos MySQL / MariaDB (solo lectura)';
    }

    public function description(): string
    {
        return 'Implementado con transacción READ ONLY y lectura en streaming. Aún no probado contra un servidor real (las pruebas de contrato se omiten si falta servidor); úselo con cautela y valide con su ERP antes de depender de él.';
    }

    public function status(): string
    {
        return 'implemented_untested';
    }

    public function verification(): string
    {
        return 'implemented_untested';
    }

    public function dialect(): SqlDialect
    {
        return new MySqlDialect;
    }

    protected function readOnlyEnforcement(): string
    {
        return 'transaction_read_only';
    }
}
