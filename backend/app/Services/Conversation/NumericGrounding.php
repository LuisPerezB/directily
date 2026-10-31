<?php

declare(strict_types=1);

namespace App\Services\Conversation;

/**
 * Verificación de que las cifras del texto del modelo proceden de las herramientas (regla 2 de §6.A: ningún LLM calcula).
 *
 * Un número del texto es «fundamentado» si coincide —con el redondeo con el que se escribió— con algún número de las fuentes
 * permitidas: resultados de herramientas, argumentos de las herramientas, el mensaje del usuario, o el tamaño de una lista
 * devuelta por una herramienta. También se admite la forma porcentual (×100) de un valor. Se omiten los marcadores de lista
 * («1.», «2)») y los números pegados a letras (identificadores como P90, H30, v1).
 *
 * Es una comprobación conservadora, no una prueba formal: ante un número no fundamentado el orquestador descarta el texto del
 * modelo y devuelve solo las cifras de las herramientas.
 */
final class NumericGrounding
{
    private const EPS = 1e-9;

    /**
     * @param  list<mixed>  $sources  arreglos/cadenas/números con los datos permitidos (los decimales ≤ 1 admiten forma porcentual)
     * @return list<string> números del texto sin respaldo, tal como están escritos
     */
    public static function ungrounded(string $text, array $sources): array
    {
        $allowed = [];
        foreach ($sources as $source) {
            self::collect($source, $allowed);
        }
        $allowed = array_values(array_unique($allowed, SORT_REGULAR));

        $missing = [];
        foreach (self::extract($text) as $token) {
            if (! self::isGrounded($token, $allowed)) {
                $missing[$token] = $token;
            }
        }

        return array_values($missing);
    }

    /** @return list<string> */
    public static function extract(string $text): array
    {
        $tokens = [];
        // Marcadores de lista al inicio de línea («1. », «2) »).
        $text = preg_replace('/^\s*\d{1,2}[.)]\s/mu', ' ', $text) ?? $text;
        if (preg_match_all('/(?<![\p{L}_\d])\d+(?:[.,]\d+)*/u', $text, $m) !== false) {
            foreach ($m[0] as $token) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /** @param list<array{0: float, 1: bool}> $allowed [valor, admite forma porcentual] */
    private static function isGrounded(string $token, array $allowed): bool
    {
        foreach (self::candidates($token) as [$value, $decimals]) {
            foreach ($allowed as [$a, $percentable]) {
                if (abs(round($a, $decimals) - $value) < self::EPS
                    || ($percentable && abs(round($a * 100, $decimals) - $value) < self::EPS)) {
                    return true;
                }
                // Truncado en lugar de redondeado (p. ej. 0,2399 → 0,23).
                $factor = 10 ** $decimals;
                if (abs(floor($a * $factor + 1e-9) / $factor - $value) < self::EPS) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Interpretaciones posibles del número escrito (separadores de miles y decimales de es/en).
     *
     * @return list<array{0: float, 1: int}> [valor, decimales escritos]
     */
    private static function candidates(string $token): array
    {
        $hasDot = str_contains($token, '.');
        $hasComma = str_contains($token, ',');
        $out = [];
        if (! $hasDot && ! $hasComma) {
            return [[(float) $token, 0]];
        }
        if ($hasDot && $hasComma) {
            $decimalSep = strrpos($token, '.') > strrpos($token, ',') ? '.' : ',';
            $thousandsSep = $decimalSep === '.' ? ',' : '.';
            $normalized = str_replace($decimalSep, '.', str_replace($thousandsSep, '', $token));
            $decimals = strlen(substr($token, (int) strrpos($token, $decimalSep) + 1));

            return [[(float) $normalized, $decimals]];
        }
        $sep = $hasDot ? '.' : ',';
        $parts = explode($sep, $token);
        $last = end($parts);
        // Interpretación decimal (solo si hay un único separador).
        if (count($parts) === 2) {
            $out[] = [(float) ($parts[0].'.'.$parts[1]), strlen($parts[1])];
        }
        // Interpretación de miles (grupos de 3 dígitos).
        if (strlen($last) === 3 && strlen($parts[0]) <= 3 && array_slice($parts, 1) === array_filter(array_slice($parts, 1), fn ($p) => strlen($p) === 3)) {
            $out[] = [(float) implode('', $parts), 0];
        }

        return $out !== [] ? $out : [[(float) str_replace($sep, '', $token), 0]];
    }

    /**
     * Solo las razones numéricas (decimales con |v| ≤ 1: probabilidades, WAPE, variaciones) admiten su forma porcentual (×100);
     * enteros, cantidades y números extraídos de textos (fechas, SKU) no.
     *
     * @param  list<array{0: float, 1: bool}>  $allowed
     */
    private static function collect(mixed $value, array &$allowed): void
    {
        if (is_float($value)) {
            $allowed[] = [$value, abs($value) <= 1.0];
        } elseif (is_int($value)) {
            $allowed[] = [(float) $value, false];
        } elseif (is_string($value)) {
            foreach (self::numbersIn($value) as $n) {
                $allowed[] = [$n, false];
            }
        } elseif (is_array($value)) {
            // El tamaño de una lista es un dato que la herramienta ya entrega implícitamente (p. ej. «3 productos»).
            $allowed[] = [(float) count($value), false];
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    foreach (self::numbersIn($key) as $n) {
                        $allowed[] = [$n, false];
                    }
                }
                self::collect($item, $allowed);
            }
        }
    }

    /** @return list<float> */
    private static function numbersIn(string $text): array
    {
        $out = [];
        if (preg_match_all('/\d+(?:\.\d+)?/u', $text, $m) !== false) {
            foreach ($m[0] as $token) {
                $out[] = (float) $token;
            }
        }

        return $out;
    }
}
