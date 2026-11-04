<?php

declare(strict_types=1);

namespace App\Services\Conversation\Gateway\Audit;

use App\Services\Conversation\Gateway\Schema\ArgumentSchema;
use App\Support\Privacy\PiiMasker;

/**
 * Minimiza los argumentos de una llamada antes de auditarlos (`gateway_tool_calls.arguments`; sin PII).
 *
 * Regla, guiada por el esquema de la herramienta:
 *  - booleanos, enteros, números y valores de una lista cerrada (`enum`) válidos → se guardan tal cual;
 *  - fechas AAAA-MM-DD (`format: date`) válidas → se guardan;
 *  - texto libre (códigos de comprador, búsquedas por nombre…) → solo su longitud: el valor puede ser un dato personal o una instrucción inyectada;
 *  - listas → solo su tamaño;
 *  - claves no declaradas o valores que no cumplen su tipo → solo el NOMBRE de la clave (saneado) en `rejected_keys`; el valor nunca.
 */
final class ArgumentMinimizer
{
    /**
     * @param  array<string, mixed>|null  $schema  esquema JSON de la herramienta (null = herramienta desconocida: nada se guarda salvo los nombres)
     * @param  array<mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function minimize(?array $schema, array $arguments): array
    {
        $properties = $schema === null ? [] : (array) ($schema['properties'] ?? []);
        $kept = [];
        $rejected = [];
        foreach ($arguments as $key => $value) {
            $definition = is_string($key) ? ($properties[$key] ?? null) : null;
            if (! is_array($definition)) {
                $rejected[] = self::rejectedLabel($key);

                continue;
            }
            $stored = self::store($definition, $value);
            if ($stored === self::REJECT) {
                $rejected[] = self::rejectedLabel($key);
            } else {
                $kept[ArgumentSchema::safeName($key)] = $stored;
            }
        }
        ksort($kept);
        $out = $kept;
        if ($rejected !== []) {
            $out['rejected_keys'] = array_slice(array_values(array_unique($rejected)), 0, 10);
        }

        return PiiMasker::sanitize($out);
    }

    private const REJECT = '__reject__';

    /**
     * Nombre con el que se registra un argumento RECHAZADO. Lo eligió el modelo: solo se conserva si es un nombre corto en snake_case sin dígitos (p. ej. `tenant_id` sin el
     * número, `query`); cualquier otra cosa —texto libre, identificadores con números, instrucciones— se registra como «(clave inválida)».
     */
    private static function rejectedLabel(int|string $key): string
    {
        return is_string($key) && preg_match('/^[a-z][a-z_]{0,23}$/', $key) === 1 ? $key : '(clave inválida)';
    }

    /** @param array<string, mixed> $definition */
    private static function store(array $definition, mixed $value): mixed
    {
        if (isset($definition['enum'])) {
            return in_array($value, (array) $definition['enum'], true) && (is_scalar($value)) ? $value : self::REJECT;
        }
        $type = is_array($definition['type'] ?? null) ? ($definition['type'][0] ?? null) : ($definition['type'] ?? null);

        return match ($type) {
            'boolean' => is_bool($value) ? $value : self::REJECT,
            'integer' => is_int($value) ? $value : (is_float($value) && floor($value) === $value ? (int) $value : self::REJECT),
            'number' => is_int($value) || is_float($value) ? $value : self::REJECT,
            'string' => ! is_string($value) ? self::REJECT
                : (($definition['format'] ?? null) === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : ['chars' => mb_strlen($value)]),
            'array' => is_array($value) ? ['items' => count($value)] : self::REJECT,
            default => self::REJECT,
        };
    }
}
