<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

/**
 * Resolver del sistema: registros A y AAAA por DNS (dns_get_record) y, si no hay respuesta, el resolvedor de libc para IPv4
 * (gethostbynamel: archivo hosts y nombres internos de Docker). Con lo que devuelve se valida la política y se FIJA la IP de
 * la conexión: no existe una segunda resolución que un atacante pueda cambiar (DNS rebinding).
 */
final class SystemHostResolver implements SqlHostResolver
{
    public function resolve(string $hostname): array
    {
        $found = [];
        $records = @dns_get_record($hostname, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['type'] === 'A' ? ($record['ip'] ?? null) : ($record['type'] === 'AAAA' ? ($record['ipv6'] ?? null) : null);
            if (is_string($ip)) {
                $found[] = $ip;
            }
        }
        if ($found === []) {
            $legacy = @gethostbynamel($hostname);
            foreach (is_array($legacy) ? $legacy : [] as $ip) {
                $found[] = $ip;
            }
        }

        return array_values(array_unique($found));
    }
}
