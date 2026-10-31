<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

use Illuminate\Support\Facades\Log;

/**
 * Política de destino de las conexiones salientes de los conectores SQL (SSRF; V2.5 §15, ADR-V25-15, defectos HIGH de
 * conectores y seguridad_ui). Reglas, en el orden en que se aplican:
 *
 * 1. Sintaxis: solo nombres RFC 1123 o literales IP canónicos. Se rechazan las formas numéricas que libc/getaddrinfo
 *    interpretan como direcciones (2130706433, 0x7f.0.0.1, 0177.0.0.1, 127.1, 0xA9FEA9FE): una etiqueta final numérica o
 *    hexadecimal solo es válida dentro de un literal IPv4 decimal canónico.
 * 2. Lista blanca de nombres (`connectors.sql.allowed_hosts`): si no está vacía, solo esos destinos (mundo cerrado).
 * 3. Resolución de A y AAAA (SqlHostResolver). TODAS las respuestas deben estar permitidas; un nombre sin respuesta se rechaza.
 * 4. Clase de cada dirección (SqlNetworkClassifier; las IPv4 embebidas en IPv6 se clasifican por su IPv4):
 *    - Bloqueada (metadatos, link-local, 0.0.0.0/8, multicast, broadcast, formas de transición): SIEMPRE denegada.
 *    - Loopback: denegada; solo la autoriza una IP literal o un CIDR explícitos de la lista blanca, y nunca en producción.
 *    - Privada (RFC 1918, 100.64.0.0/10, ULA fc00::/7…): denegada por defecto; la autoriza estar en `allowed_hosts` (el
 *      destino fue aprobado por el operador) o que la IP caiga en `connectors.sql.allowed_cidrs`.
 *    - Pública: permitida.
 * 5. La conexión se abre contra la IP FIJADA (SqlResolvedTarget::address), nunca contra el nombre: no hay segunda resolución
 *    (DNS rebinding). PostgreSQL conserva `host` para TLS y usa `hostaddr`; MySQL y SQL Server se conectan al literal IP.
 *
 * Los mensajes de rechazo son genéricos (no revelan si el nombre existe ni a qué red apunta); el motivo exacto va al registro.
 */
final class SqlHostPolicy
{
    public const DENIED_MESSAGE = 'El destino no está permitido por la política de red de la plataforma. Si es un servidor propio del cliente, pida al administrador de Predictily que lo autorice.';

    public const INVALID_MESSAGE = 'Indique un host válido: un nombre de dominio (RFC 1123) o una dirección IP en notación canónica; no se admiten formas decimales, hexadecimales ni octales.';

    /** Compatibilidad: valida el destino sin devolverlo. @throws SqlSourceException */
    public static function assertAllowed(string $host): void
    {
        self::resolve($host);
    }

    /**
     * Valida y resuelve el destino. Devuelve la IP FIJADA a la que debe conectarse el dialecto.
     *
     * @throws SqlHostRejected
     */
    public static function resolve(string $host): SqlResolvedTarget
    {
        $name = self::normalize($host);
        $literal = IpAddress::pack($name);
        $listed = self::listedHosts();
        $cidrs = self::listedCidrs();

        if ($listed !== null && ! self::isListed($name, $listed)) {
            throw self::denied(SqlHostRejected::NOT_LISTED, $name);
        }
        $addresses = $literal !== null ? [$name] : self::resolver()->resolve($name);
        if ($addresses === []) {
            throw self::denied(SqlHostRejected::UNRESOLVABLE, $name);
        }

        $validated = [];
        foreach ($addresses as $address) {
            $packed = is_string($address) ? IpAddress::pack($address) : null;
            if ($packed === null) {
                throw self::denied(SqlHostRejected::UNRESOLVABLE, $name, is_string($address) ? $address : null);
            }
            $effective = IpAddress::embeddedIPv4($packed) ?? $packed;
            match (SqlNetworkClassifier::classify($packed)) {
                SqlAddressClass::Public => null,
                SqlAddressClass::Private => $listed !== null || IpAddress::inAnyCidr($effective, $cidrs)
                    ? null
                    : throw self::denied(SqlHostRejected::PRIVATE, $name, $address),
                SqlAddressClass::Loopback => ! app()->isProduction() && (self::ipIsListed($effective, $listed ?? []) || IpAddress::inAnyCidr($effective, $cidrs))
                    ? null
                    : throw self::denied(SqlHostRejected::LOOPBACK, $name, $address),
                SqlAddressClass::Blocked => throw self::denied(SqlHostRejected::BLOCKED, $name, $address),
            };
            // ::ffff:a.b.c.d se fija como a.b.c.d; el resto de formas se conserva tal cual.
            $validated[] = IpAddress::isMapped($packed) ? IpAddress::text($effective) : IpAddress::text($packed);
        }

        $pinned = null;
        foreach ($validated as $candidate) {
            if (! str_contains($candidate, ':')) {
                $pinned = $candidate;   // se prefiere IPv4 (rutas y reglas de red más habituales en los ERP)

                break;
            }
        }

        return new SqlResolvedTarget($name, $pinned ?? $validated[0], $literal !== null);
    }

    /** Mensaje de error de sintaxis (para la validación de la configuración de la fuente) o null si el host es sintácticamente válido. */
    public static function syntaxError(string $host): ?string
    {
        try {
            self::normalize($host);

            return null;
        } catch (SqlHostRejected $e) {
            return $e->getMessage();
        }
    }

    /**
     * Nombre RFC 1123 en minúsculas o IP literal en texto canónico (sin corchetes). Rechaza cualquier otra forma.
     *
     * @throws SqlHostRejected
     */
    public static function normalize(string $host): string
    {
        if ($host === '' || strlen($host) > 255 || preg_match('/[^\x21-\x7E]/', $host) === 1) {
            throw self::invalid();
        }
        if ($host[0] === '[') {
            $inner = str_ends_with($host, ']') ? IpAddress::pack(substr($host, 1, -1)) : null;
            if ($inner === null || strlen($inner) !== 16) {
                throw self::invalid();
            }

            return IpAddress::text($inner);
        }
        $packed = IpAddress::pack($host);
        if ($packed !== null) {
            return IpAddress::text($packed);
        }

        $name = strtolower($host);
        if (str_ends_with($name, '.')) {
            $name = substr($name, 0, -1);
        }
        if ($name === '' || strlen($name) > 253) {
            throw self::invalid();
        }
        $labels = explode('.', $name);
        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                throw self::invalid();
            }
        }
        $last = $labels[array_key_last($labels)];
        if (ctype_digit($last) || preg_match('/^0x[0-9a-f]*$/', $last) === 1) {
            throw self::invalid();   // 2130706433, 0x7f.0.0.1, 0177.0.0.1, 127.1, 0xA9FEA9FE…
        }

        return $name;
    }

    private static function resolver(): SqlHostResolver
    {
        return app()->bound(SqlHostResolver::class) ? app(SqlHostResolver::class) : new SystemHostResolver;
    }

    /**
     * Entradas de `allowed_hosts` normalizadas (nombres e IP literales); null si la lista no está configurada (sin restricción
     * por nombre). Una entrada inválida nunca autoriza nada, pero tampoco vacía la lista: con la lista configurada solo
     * pasan los destinos válidos que figuren en ella (falla cerrado ante un error de escritura).
     *
     * @return list<string>|null
     */
    private static function listedHosts(): ?array
    {
        $out = [];
        $configured = false;
        foreach ((array) config('connectors.sql.allowed_hosts', []) as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                continue;
            }
            $configured = true;
            try {
                $out[] = self::normalize(trim($entry));
            } catch (SqlHostRejected) {
                Log::warning('connectors.sql.allowed_hosts: entrada no válida ignorada (use nombres RFC 1123 o IP literales).');
            }
        }

        return $configured ? $out : null;
    }

    /** @return list<string> */
    private static function listedCidrs(): array
    {
        return array_values(array_filter(array_map(
            fn ($c) => is_string($c) ? trim($c) : '',
            (array) config('connectors.sql.allowed_cidrs', []),
        ), fn (string $c) => $c !== ''));
    }

    /** @param list<string> $listed */
    private static function isListed(string $name, array $listed): bool
    {
        if (in_array($name, $listed, true)) {
            return true;
        }

        return IpAddress::pack($name) !== null && self::ipIsListed(IpAddress::normalizedPack($name) ?? '', $listed);
    }

    /** ¿Está la IP (binario, des-mapeada) escrita como literal en la lista blanca de hosts? @param list<string> $listed */
    private static function ipIsListed(string $effective, array $listed): bool
    {
        foreach ($listed as $entry) {
            $packed = IpAddress::normalizedPack($entry);
            if ($packed !== null && $packed === $effective) {
                return true;
            }
        }

        return false;
    }

    private static function invalid(): SqlHostRejected
    {
        return new SqlHostRejected(self::INVALID_MESSAGE, SqlHostRejected::INVALID_SYNTAX);
    }

    private static function denied(string $reason, string $host, ?string $address = null): SqlHostRejected
    {
        // Solo al registro del servidor (no a la empresa): el motivo exacto sería un oráculo de la red interna.
        Log::warning('Destino de conexión SQL rechazado por la política de red.', ['reason' => $reason, 'host' => $host, 'address' => $address]);

        return new SqlHostRejected(self::DENIED_MESSAGE, $reason);
    }
}
