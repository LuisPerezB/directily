<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

/**
 * Política de destino de las conexiones salientes (mitigación de SSRF). Siempre se bloquean las direcciones de
 * metadatos de nube (169.254.0.0/16), 0.0.0.0, multicast; opcionalmente las redes privadas y loopback.
 * No cubre DNS rebinding: para un SaaS público use `allowed_hosts`.
 */
final class SqlHostPolicy
{
    /** @throws SqlSourceException */
    public static function assertAllowed(string $host): void
    {
        $allowed = (array) config('connectors.sql.allowed_hosts', []);
        if ($allowed !== [] && ! in_array(strtolower($host), array_map('strtolower', $allowed), true)) {
            throw new SqlSourceException('El host no está en la lista de destinos permitidos.');
        }
        $bare = trim($host, '[]');
        $ips = filter_var($bare, FILTER_VALIDATE_IP) !== false ? [$bare] : (gethostbynamel($bare) ?: []);
        foreach ($ips as $ip) {
            if (self::isAlwaysBlocked($ip)) {
                throw new SqlSourceException('El host apunta a una dirección no permitida (metadatos de nube, no especificada o multicast).');
            }
            if ((bool) config('connectors.sql.deny_private_networks', false) && self::isPrivate($ip)) {
                throw new SqlSourceException('Las conexiones a redes privadas o loopback están deshabilitadas en este entorno.');
            }
        }
    }

    private static function isAlwaysBlocked(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            return $long === 0 || ($long & 0xFFFF0000) === 0xA9FE0000 || ($long & 0xF0000000) === 0xE0000000;
        }
        $packed = inet_pton($ip);

        return $packed !== false && ($ip === '::' || str_starts_with(strtolower($ip), 'fe80:') || ord($packed[0]) === 0xFF);
    }

    private static function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
