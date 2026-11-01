<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

/**
 * Clasifica una dirección IP de destino. Las IPv4 embebidas en IPv6 (::ffff:a.b.c.d, ::ffff:a9fe:a9fe, ::a.b.c.d, SIIT y
 * NAT64) se clasifican por la IPv4 que alcanzan: así ::ffff:169.254.169.254 es metadatos y ::ffff:10.0.0.5 es privada.
 *
 * Tablas (las únicas fuentes de verdad de la política; documentadas en docs/07-seguridad-multitenant.md §13):
 * - BLOQUEADAS siempre: 0.0.0.0/8, link-local 169.254.0.0/16 (incluye 169.254.169.254), 192.0.0.0/24 (incluye 192.0.0.192,
 *   metadatos de Oracle), multicast 224.0.0.0/4, reservado 240.0.0.0/4 (incluye broadcast), 168.63.129.16 (Azure),
 *   100.100.100.200 (Alibaba), «::», fe80::/10, ff00::/8, fd00:ec2::/32 (metadatos IPv6 de AWS, fd00:ec2::254), 6to4 2002::/16,
 *   Teredo 2001::/32, descarte 100::/64 y NAT64 de uso local 64:ff9b:1::/48.
 * - LOOPBACK: 127.0.0.0/8 y ::1.
 * - PRIVADAS: 10/8, 172.16/12, 192.168/16, 100.64/10, 198.18/15, TEST-NET, fc00::/7, fec0::/10, 2001:db8::/32 y, como red de
 *   seguridad, cualquier dirección que PHP no considere de alcance global (FILTER_FLAG_GLOBAL_RANGE).
 */
final class SqlNetworkClassifier
{
    private const BLOCKED = [
        '0.0.0.0/8', '169.254.0.0/16', '192.0.0.0/24', '224.0.0.0/4', '240.0.0.0/4', '168.63.129.16/32', '100.100.100.200/32',
        '::/128', 'fe80::/10', 'ff00::/8', 'fd00:ec2::/32', '2002::/16', '2001::/32', '100::/64', '64:ff9b:1::/48',
    ];

    private const LOOPBACK = ['127.0.0.0/8', '::1/128'];

    private const PRIVATE = [
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', '198.18.0.0/15', '192.0.2.0/24', '198.51.100.0/24',
        '203.0.113.0/24', '192.88.99.0/24', 'fc00::/7', 'fec0::/10', '2001:db8::/32', '3fff::/20',
    ];

    /** @param string $packed dirección en binario (4 o 16 bytes) */
    public static function classify(string $packed): SqlAddressClass
    {
        $embedded = IpAddress::embeddedIPv4($packed);
        if ($embedded !== null) {
            // Una forma de transición hereda la clase de la IPv4 que alcanza; nunca es «más permitida» que ella.
            return self::classify($embedded);
        }
        if (self::matches($packed, self::BLOCKED)) {
            return SqlAddressClass::Blocked;
        }
        if (self::matches($packed, self::LOOPBACK)) {
            return SqlAddressClass::Loopback;
        }
        if (self::matches($packed, self::PRIVATE)) {
            return SqlAddressClass::Private;
        }
        // Red de seguridad: lo que PHP no considera globalmente enrutable no se trata como público.
        if (filter_var(IpAddress::text($packed), FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return SqlAddressClass::Private;
        }

        return SqlAddressClass::Public;
    }

    /** @param list<string> $cidrs */
    private static function matches(string $packed, array $cidrs): bool
    {
        return IpAddress::inAnyCidr($packed, $cidrs);
    }
}
