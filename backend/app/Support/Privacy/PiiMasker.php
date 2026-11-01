<?php

declare(strict_types=1);

namespace App\Support\Privacy;

/**
 * Enmascara datos personales en texto libre (logs, metadatos de auditoría).
 *
 * - Correos: j***@e***.com
 * - Cédula (10 dígitos) / RUC (13 dígitos) ecuatorianos: [ID]
 * - Tokens Bearer, tokens Sanctum "id|secreto" y cadenas hex/base64 largas: [TOKEN]
 */
final class PiiMasker
{
    /** Claves cuyo valor nunca debe registrarse. */
    public const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'current_password', 'token', 'access_token', 'api_key', 'secret',
        'credentials', 'authorization', 'cookie', 'temporary_password', 'tax_id', 'cedula', 'ruc', 'name_encrypted',
        'credentials_encrypted', 'remember_token',
    ];

    public static function mask(string $text): string
    {
        $text = preg_replace_callback(
            '/([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*@([A-Za-z0-9])[A-Za-z0-9\-]*((?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,})/',
            fn (array $m) => $m[1].'***@'.$m[2].'***'.$m[3],
            $text,
        ) ?? $text;
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9|._\-]+/i', 'Bearer [TOKEN]', $text) ?? $text;
        $text = preg_replace('/\b\d+\|[A-Za-z0-9]{20,}\b/', '[TOKEN]', $text) ?? $text;
        $text = preg_replace('/(?<!\d)(\d{13}|\d{10})(?!\d)/', '[ID]', $text) ?? $text;
        $text = preg_replace('/\b[A-Fa-f0-9]{40,}\b/', '[TOKEN]', $text) ?? $text;

        return $text;
    }

    /**
     * Limpia recursivamente un arreglo: elimina claves sensibles y enmascara strings.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function sanitize(array $data, int $depth = 0): array
    {
        if ($depth > 5) {
            return ['_truncated' => true];
        }
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $clean[$key] = '[REDACTED]';

                continue;
            }
            $clean[$key] = match (true) {
                is_array($value) => self::sanitize($value, $depth + 1),
                // Los mapas JSON (stdClass) se limpian igual y siguen siendo objeto (no se vuelven lista al estar vacíos).
                $value instanceof \stdClass => (object) self::sanitize((array) $value, $depth + 1),
                is_string($value) => self::mask(mb_substr($value, 0, 500)),
                default => $value,
            };
        }

        return $clean;
    }
}
