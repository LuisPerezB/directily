<?php

declare(strict_types=1);

namespace App\Services\Auth\Mfa;

/**
 * Códigos de recuperación del segundo factor (V2.5 · W6-A). Cada código es aleatorio (CSPRNG), de 16 caracteres de un alfabeto sin ambigüedades (~78 bits), se muestra UNA
 * vez agrupado en cuatro bloques y se guarda solo su HASH: HMAC-SHA-256 con una clave derivada de la clave de la aplicación (HKDF). Para secretos aleatorios de alta
 * entropía un hash lento no agrega seguridad; la clave de la aplicación impide además que quien solo obtiene la base de datos pruebe candidatos fuera de línea.
 * Un código se consume al usarse (`u` = fecha de uso). Cada entrada lleva `k`, el identificador de la clave con que se calculó su hash.
 *
 * Rotación de `APP_KEY`: con `APP_PREVIOUS_KEYS` la verificación prueba la clave vigente y las anteriores, de modo que los códigos no usados SIGUEN sirviendo mientras la clave
 * anterior esté configurada; al retirarla, los que quedaron con ella dejan de servir (no se pueden volver a calcular: el código en claro no se guarda). Por eso `MfaService::status`
 * informa cuántos están en una clave anterior (`recovery_codes_on_previous_key`) para pedir códigos nuevos ANTES de retirarla.
 */
final class RecoveryCodes
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';

    private const LENGTH = 16;

    /**
     * @return array{plain: list<string>, stored: list<array{h: string, u: ?string, k: string}>}
     */
    public static function generate(int $count): array
    {
        $plain = [];
        $stored = [];
        $kid = self::keyId((string) app('encrypter')->getKey());
        for ($i = 0; $i < $count; $i++) {
            $raw = '';
            for ($j = 0; $j < self::LENGTH; $j++) {
                $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $plain[] = implode('-', str_split($raw, 4));
            $stored[] = ['h' => self::hash($raw), 'u' => null, 'k' => $kid];
        }

        return ['plain' => $plain, 'stored' => $stored];
    }

    /** Normaliza lo que escribe la persona: mayúsculas y sin separadores. */
    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /** Hash con la clave de la aplicación VIGENTE. */
    public static function hash(string $normalized): string
    {
        return hash_hmac('sha256', $normalized, self::pepper((string) app('encrypter')->getKey()));
    }

    /** ¿Tiene la forma de un código de recuperación (16 caracteres del alfabeto)? */
    public static function looksLikeCode(string $code): bool
    {
        return preg_match('/^['.self::ALPHABET.']{'.self::LENGTH.'}$/', self::normalize($code)) === 1;
    }

    /**
     * Índice del código sin usar que coincide, o null. Compara contra todos (sin salida anticipada) y en tiempo constante, con la clave vigente y las anteriores.
     *
     * @param  list<array{h: string, u: ?string}>  $stored
     */
    public static function find(array $stored, string $code): ?int
    {
        return self::match($stored, $code)['index'] ?? null;
    }

    /**
     * Como {@see self::find()} pero dice también si el hash que coincidió era de una clave ANTERIOR (para auditarlo).
     *
     * @param  list<array{h: string, u: ?string}>  $stored
     * @return array{index: int, previous_key: bool}|null
     */
    public static function match(array $stored, string $code): ?array
    {
        if (! self::looksLikeCode($code)) {
            return null;
        }
        $normalized = self::normalize($code);
        $keys = (array) app('encrypter')->getAllKeys();   // la vigente primero, luego las de APP_PREVIOUS_KEYS
        $found = null;
        foreach ($keys as $position => $key) {
            $hash = hash_hmac('sha256', $normalized, self::pepper((string) $key));
            foreach ($stored as $index => $entry) {
                if (hash_equals((string) ($entry['h'] ?? ''), $hash) && ($entry['u'] ?? null) === null) {
                    $found = ['index' => $index, 'previous_key' => $position > 0];
                }
            }
        }

        return $found;
    }

    /** @param list<array{h: string, u: ?string}>|null $stored */
    public static function remaining(?array $stored): int
    {
        return count(array_filter($stored ?? [], fn (array $e): bool => ($e['u'] ?? null) === null));
    }

    /**
     * Cuántos códigos SIN USAR se calcularon con una clave que ya no es la vigente.
     *
     * @param  list<array{h: string, u: ?string, k?: string}>|null  $stored
     */
    public static function onPreviousKey(?array $stored): int
    {
        $current = self::keyId((string) app('encrypter')->getKey());

        return count(array_filter($stored ?? [], fn (array $e): bool => ($e['u'] ?? null) === null && ($e['k'] ?? $current) !== $current));
    }

    public static function keyId(string $key): string
    {
        return substr(hash('sha256', 'predictily:mfa:recovery:kid:'.$key), 0, 12);
    }

    private static function pepper(string $key): string
    {
        return hash_hkdf('sha256', $key, 32, 'predictily:mfa:recovery:v1');
    }
}
