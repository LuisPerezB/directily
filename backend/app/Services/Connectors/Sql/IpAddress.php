<?php

declare(strict_types=1);

namespace App\Services\Connectors\Sql;

/**
 * Utilidades de direcciones IP en binario (inet_pton) para la política de destino de los conectores: canonicalización,
 * extracción de IPv4 embebidas en IPv6 y pertenencia a un CIDR. Sin dependencias de configuración ni de red.
 */
final class IpAddress
{
    /** Binario (4 u 16 bytes) de un literal IP válido (IPv4 decimal canónico o IPv6), o null si no lo es. */
    public static function pack(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = @inet_pton($ip);

        return $packed === false ? null : $packed;
    }

    /** Texto canónico (inet_ntop) de una dirección en binario. */
    public static function text(string $packed): string
    {
        return (string) inet_ntop($packed);
    }

    /** ¿Es IPv4 mapeada en IPv6 (::ffff:a.b.c.d, también escrita ::ffff:a9fe:a9fe)? */
    public static function isMapped(string $packed): bool
    {
        return strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff";
    }

    /**
     * IPv4 embebida en una IPv6 (4 bytes) cuando la dirección es una forma de transición que el sistema operativo enruta
     * hacia esa IPv4: mapeada ::ffff:a.b.c.d (también ::ffff:a9fe:a9fe), compatible ::a.b.c.d, traducida SIIT
     * ::ffff:0:a.b.c.d y NAT64 64:ff9b::a.b.c.d. Devuelve null si no es ninguna (o si no es IPv6).
     */
    public static function embeddedIPv4(string $packed): ?string
    {
        if (strlen($packed) !== 16) {
            return null;
        }
        $prefix = substr($packed, 0, 12);
        $low = substr($packed, 12, 4);
        $isTranslated = $prefix === str_repeat("\0", 8)."\xff\xff\0\0";
        $isNat64 = $prefix === "\x00\x64\xff\x9b".str_repeat("\0", 8);
        // ::/96 (compatible, en desuso) excepto :: y ::1, que no son IPv4 embebidas.
        $isCompatible = $prefix === str_repeat("\0", 12) && $low !== "\0\0\0\0" && $low !== "\0\0\0\1";

        return (self::isMapped($packed) || $isTranslated || $isNat64 || $isCompatible) ? $low : null;
    }

    /** pack() des-mapeando las IPv4 mapeadas (::ffff:a.b.c.d → a.b.c.d) para poder comparar direcciones. */
    public static function normalizedPack(string $ip): ?string
    {
        $packed = self::pack($ip);
        if ($packed === null) {
            return null;
        }

        return self::isMapped($packed) ? substr($packed, 12, 4) : $packed;
    }

    /**
     * Interpreta «a.b.c.d/n», «2001:db8::/32» o una IP suelta (/32 o /128). Devuelve [binario de la red, bits] o null si es
     * inválido. Una red escrita como IPv4 mapeada (::ffff:10.0.0.0/104) se expresa como IPv4 (10.0.0.0/8).
     *
     * @return array{0: string, 1: int}|null
     */
    public static function parseCidr(string $cidr): ?array
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return null;
        }
        $address = $cidr;
        $length = null;
        if (str_contains($cidr, '/')) {
            [$address, $text] = explode('/', $cidr, 2);
            if ($text === '' || ! ctype_digit($text)) {
                return null;
            }
            $length = (int) $text;
        }
        $raw = self::pack($address);
        if ($raw === null) {
            return null;
        }
        $bits = $length ?? strlen($raw) * 8;
        if ($bits > strlen($raw) * 8) {
            return null;
        }
        if (self::isMapped($raw)) {
            return $bits >= 96 ? [substr($raw, 12, 4), $bits - 96] : null;
        }

        return [$raw, $bits];
    }

    /** ¿Pertenece la dirección (binario) a la red (binario/bits)? Familias distintas nunca coinciden. */
    public static function inNetwork(string $packed, string $network, int $bits): bool
    {
        if (strlen($packed) !== strlen($network)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;
        if (substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    /**
     * ¿Está la dirección (binario) en alguno de los CIDR en texto? Las entradas inválidas nunca coinciden.
     *
     * @param  array<array-key, mixed>  $cidrs
     */
    public static function inAnyCidr(string $packed, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            $parsed = is_string($cidr) ? self::parseCidr($cidr) : null;
            if ($parsed !== null && self::inNetwork($packed, $parsed[0], $parsed[1])) {
                return true;
            }
        }

        return false;
    }
}
