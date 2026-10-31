<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

/**
 * Destino ya autorizado por la política: nombre normalizado (para TLS y registro) y la IP FIJADA a la que se conecta.
 * Todas las direcciones que devolvió la resolución fueron validadas; `address` es la elegida (IPv4 si existe).
 */
final readonly class SqlResolvedTarget
{
    public function __construct(
        /** Nombre RFC 1123 en minúsculas o IP literal canónica (sin corchetes). */
        public string $host,
        /** Literal IP canónico al que se conecta. */
        public string $address,
        /** El host indicado ya era una IP literal (no hubo resolución). */
        public bool $literal,
    ) {}

    public function isIPv6(): bool
    {
        return str_contains($this->address, ':');
    }
}
