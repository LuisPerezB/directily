<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

use RuntimeException;

/** La consulta autorizada no cumple la política de solo lectura (una sola sentencia SELECT, sin escritura). */
final class SqlQueryRejected extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'not_read_only')
    {
        parent::__construct($message);
    }
}
