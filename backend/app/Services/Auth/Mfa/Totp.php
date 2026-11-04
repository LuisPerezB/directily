<?php

declare(strict_types=1);

namespace App\Services\Auth\Mfa;

use InvalidArgumentException;

/**
 * TOTP (RFC 6238) sobre HOTP (RFC 4226), sin dependencias: ninguna biblioteca TOTP está en composer.lock. Puro y determinista: el tiempo siempre llega por
 * parámetro (el reloj real lo pone quien llama), de modo que las pruebas fijan el reloj y se contrastan con los vectores del propio RFC
 * (tests/Unit/Security/TotpTest.php).
 *
 * - Secreto: bytes aleatorios del CSPRNG (20 por defecto = 160 bits, el tamaño de la clave SHA-1 de RFC 4226 §4 R6) y se presenta en Base32 (RFC 4648, sin relleno).
 * - Algoritmos: SHA-1 (por defecto: lo que aceptan todas las aplicaciones autenticadoras), SHA-256 y SHA-512 (los vectores del RFC los usan).
 * - Verificación con ventana de ±N pasos y comparación en tiempo constante. Devuelve el PASO (contador T) que coincidió, para que quien llama impida repetirlo.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const ALGORITHMS = ['sha1', 'sha256', 'sha512'];

    /** Secreto nuevo en Base32 (sin relleno). */
    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 10) {
            throw new InvalidArgumentException('El secreto TOTP debe tener al menos 10 bytes.');
        }

        return self::base32Encode(random_bytes($bytes));
    }

    public static function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    /** Decodifica Base32 tolerando minúsculas, espacios, guiones y relleno `=`. Cualquier otro carácter es un error. */
    public static function base32Decode(string $encoded): string
    {
        $clean = strtoupper(str_replace([' ', '-', '='], '', $encoded));
        if ($clean === '' || preg_match('/^[A-Z2-7]+$/', $clean) !== 1) {
            throw new InvalidArgumentException('El secreto no es Base32 válido.');
        }
        $bits = '';
        foreach (str_split($clean) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $binary = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binary .= chr((int) bindec($byte));
            }
        }

        return $binary;
    }

    /** HOTP de RFC 4226: HMAC del contador de 8 bytes (big-endian) y truncamiento dinámico. */
    public static function hotp(string $binarySecret, int $counter, int $digits = 6, string $algorithm = 'sha1'): string
    {
        if ($counter < 0) {
            throw new InvalidArgumentException('El contador no puede ser negativo.');
        }
        if (! in_array($algorithm, self::ALGORITHMS, true)) {
            throw new InvalidArgumentException('Algoritmo TOTP no admitido.');
        }
        if ($digits < 6 || $digits > 9) {
            throw new InvalidArgumentException('Los dígitos deben estar entre 6 y 9.');
        }
        $hash = hash_hmac($algorithm, pack('J', $counter), $binarySecret, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** Paso (contador T) de un instante: T = floor((tiempo − T0) / X), con T0 = 0. */
    public static function step(int $timestamp, int $period = 30): int
    {
        return intdiv(max(0, $timestamp), $period);
    }

    /** Código TOTP de un instante dado. `$base32Secret` es el secreto en Base32. */
    public static function code(string $base32Secret, int $timestamp, int $period = 30, int $digits = 6, string $algorithm = 'sha1'): string
    {
        return self::hotp(self::base32Decode($base32Secret), self::step($timestamp, $period), $digits, $algorithm);
    }

    /**
     * Verifica un código con ventana de ±`$window` pasos. Devuelve el paso que coincidió o null.
     *
     * `$notBeforeStep`: el último paso ya aceptado; solo se aceptan pasos ESTRICTAMENTE mayores (RFC 6238 §5.2: un código no se acepta dos veces).
     * Los candidatos se comparan todos (sin salida anticipada) y con `hash_equals`, para no filtrar por tiempo cuál paso coincidió.
     */
    public static function verify(string $base32Secret, string $code, int $timestamp, int $window = 1, int $period = 30, int $digits = 6, string $algorithm = 'sha1', ?int $notBeforeStep = null): ?int
    {
        $code = preg_replace('/[\s-]+/', '', $code) ?? '';
        if (preg_match('/^\d{'.$digits.'}$/', $code) !== 1) {
            return null;
        }
        $binary = self::base32Decode($base32Secret);
        $current = self::step($timestamp, $period);
        $matched = null;
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $current + $offset;
            if ($step < 0) {
                continue;
            }
            $expected = self::hotp($binary, $step, $digits, $algorithm);
            if (hash_equals($expected, $code) && ($notBeforeStep === null || $step > $notBeforeStep) && ($matched === null || $step > $matched)) {
                $matched = $step;
            }
        }

        return $matched;
    }

    /** URI `otpauth://` que escanean las aplicaciones autenticadoras (Key URI Format). */
    public static function uri(string $issuer, string $account, string $base32Secret, int $digits = 6, int $period = 30, string $algorithm = 'sha1'): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $base32Secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper($algorithm),
            'digits' => $digits,
            'period' => $period,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
