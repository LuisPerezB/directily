<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

/**
 * El destino de una conexión SQL no cumple la política de red (SSRF). El mensaje es deliberadamente genérico —se devuelve a
 * la empresa en la prueba de conexión y en `last_error`— para no convertir la política en un oráculo de la red interna;
 * el motivo exacto (`reason`) queda solo en el registro del servidor y en las pruebas.
 */
final class SqlHostRejected extends SqlSourceException
{
    public const INVALID_SYNTAX = 'invalid_syntax';

    public const NOT_LISTED = 'host_not_listed';

    public const UNRESOLVABLE = 'unresolvable';

    public const BLOCKED = 'blocked_address';

    public const LOOPBACK = 'loopback_address';

    public const PRIVATE = 'private_address';

    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
