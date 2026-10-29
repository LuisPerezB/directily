<?php

declare(strict_types=1);

namespace App\Services\Connectors\Connectors;

use App\Services\Connectors\Sql\SqlDialect;
use App\Services\Connectors\Sql\SqlServerDialect;

/** SQL Server de solo lectura. Implementado; sin servidor ni extensión pdo_sqlsrv en este entorno: NO se declara disponible. */
final class SqlServerSqlConnector extends AbstractSqlConnector
{
    public function key(): string
    {
        return 'sql_sqlserver';
    }

    public function name(): string
    {
        return 'Base de datos SQL Server (solo lectura)';
    }

    public function description(): string
    {
        return 'Implementado (requiere la extensión PHP pdo_sqlsrv). SQL Server no tiene transacciones de solo lectura: la garantía son el validador de consultas y una cuenta db_datareader. Aún no probado contra un servidor real.';
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
        return new SqlServerDialect;
    }

    protected function readOnlyEnforcement(): string
    {
        return 'query_guard_and_account_permissions';
    }
}
