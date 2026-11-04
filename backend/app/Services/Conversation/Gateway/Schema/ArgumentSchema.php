<?php

declare(strict_types=1);

namespace App\Services\Conversation\Gateway\Schema;

/**
 * Validador mínimo de los argumentos de una llamada a herramienta contra el ESQUEMA JSON que se le ofrece al modelo (`AssistantTool::parameters()`). Sin dependencias
 * nuevas. Es la primera barrera (forma y tipo estrictos, lista cerrada de claves); las reglas de Laravel (`rules()`) siguen después para lo que depende de la empresa
 * (p. ej. que un identificador exista en la empresa activa).
 *
 * Subconjunto soportado: `type` (object, string, integer, number, boolean, array), `properties`, `required`, `additionalProperties: false`, `enum`, `minimum`, `maximum`,
 * `minLength`, `maxLength`, `pattern`, `format: date`, `items`, `maxItems`. Sin coerción: «30» no es un entero.
 *
 * Los mensajes nombran el argumento y la restricción, nunca el valor recibido (podría traer datos personales o instrucciones inyectadas).
 */
final class ArgumentSchema
{
    /** Claves que el modelo NUNCA puede aportar, aunque un esquema las declarara: la empresa, el usuario y los permisos salen de la petición. */
    public const RESERVED_KEYS = [
        'tenant', 'tenant_id', 'tenant_uuid', 'company', 'company_id', 'empresa', 'empresa_id',
        'user', 'user_id', 'usuario', 'usuario_id', 'role', 'rol', 'ability', 'abilities', 'habilidad', 'permission', 'permissions',
        'scope', 'commercial_scope', 'commercial_role', 'channel', 'token', 'authorization', 'impersonate', 'as_user',
    ];

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<mixed>  $arguments
     * @return list<string> errores en español; vacío = válido
     */
    public static function validate(array $schema, array $arguments): array
    {
        $errors = [];
        $properties = (array) ($schema['properties'] ?? []);
        $allowed = array_map('strval', array_keys($properties));

        if (($schema['additionalProperties'] ?? true) === false) {
            $unknown = [];
            foreach (array_keys($arguments) as $key) {
                if (! in_array((string) $key, $allowed, true)) {
                    $unknown[] = self::safeName($key);
                }
            }
            if ($unknown !== []) {
                $errors[] = 'Argumentos no permitidos: '.implode(', ', array_slice($unknown, 0, 8)).'.';
            }
        }
        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (! array_key_exists((string) $required, $arguments)) {
                $errors[] = "Falta el argumento «{$required}».";
            }
        }
        foreach ($properties as $name => $definition) {
            if (array_key_exists((string) $name, $arguments)) {
                array_push($errors, ...self::validateValue("«{$name}»", (array) $definition, $arguments[$name]));
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private static function validateValue(string $label, array $definition, mixed $value): array
    {
        $type = $definition['type'] ?? null;
        $types = is_array($type) ? $type : ($type === null ? [] : [$type]);
        if ($value === null && in_array('null', $types, true)) {
            return [];
        }
        if ($types !== [] && ! self::matchesAnyType($types, $value)) {
            return ["El argumento {$label} debe ser de tipo ".implode(' o ', array_map(fn ($t) => self::typeName((string) $t), $types)).'.'];
        }
        $errors = [];
        if (isset($definition['enum']) && ! in_array($value, (array) $definition['enum'], true)) {
            $errors[] = "El argumento {$label} debe ser uno de: ".implode(', ', array_map(fn ($v) => (string) $v, (array) $definition['enum'])).'.';
        }
        if (is_int($value) || is_float($value)) {
            if (isset($definition['minimum']) && $value < $definition['minimum']) {
                $errors[] = "El argumento {$label} no puede ser menor que {$definition['minimum']}.";
            }
            if (isset($definition['maximum']) && $value > $definition['maximum']) {
                $errors[] = "El argumento {$label} no puede ser mayor que {$definition['maximum']}.";
            }
        }
        if (is_string($value)) {
            $length = mb_strlen($value);
            if (isset($definition['minLength']) && $length < (int) $definition['minLength']) {
                $errors[] = "El argumento {$label} es demasiado corto (mínimo {$definition['minLength']} caracteres).";
            }
            if (isset($definition['maxLength']) && $length > (int) $definition['maxLength']) {
                $errors[] = "El argumento {$label} es demasiado largo (máximo {$definition['maxLength']} caracteres).";
            }
            if (isset($definition['pattern']) && preg_match('/'.str_replace('/', '\/', (string) $definition['pattern']).'/u', $value) !== 1) {
                $errors[] = "El argumento {$label} no tiene el formato esperado.";
            }
            if (($definition['format'] ?? null) === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                $errors[] = "El argumento {$label} debe ser una fecha AAAA-MM-DD.";
            }
        }
        if (is_array($value) && array_is_list($value)) {
            if (isset($definition['maxItems']) && count($value) > (int) $definition['maxItems']) {
                $errors[] = "El argumento {$label} admite como máximo {$definition['maxItems']} elementos.";
            }
            if (isset($definition['items'])) {
                foreach ($value as $i => $item) {
                    array_push($errors, ...self::validateValue("{$label}[{$i}]", (array) $definition['items'], $item));
                }
            }
        }

        return $errors;
    }

    /** @param list<mixed> $types */
    private static function matchesAnyType(array $types, mixed $value): bool
    {
        foreach ($types as $t) {
            $ok = match ((string) $t) {
                'string' => is_string($value),
                'integer' => is_int($value) || (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && ! array_is_list($value),
                'null' => $value === null,
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private static function typeName(string $type): string
    {
        return match ($type) {
            'string' => 'texto', 'integer' => 'entero', 'number' => 'número', 'boolean' => 'booleano', 'array' => 'lista', 'object' => 'objeto', default => $type,
        };
    }

    /** Nombre de clave seguro para mostrarlo o registrarlo: solo caracteres de identificador. */
    public static function safeName(int|string $key): string
    {
        $key = (string) $key;

        return preg_match('/^[A-Za-z0-9_.\-]{1,40}$/', $key) === 1 ? $key : '(clave inválida)';
    }

    /** ¿Alguna de las claves es una clave reservada (empresa, usuario, permisos)? */
    public static function reservedKeys(array $arguments): array
    {
        $found = [];
        foreach (array_keys($arguments) as $key) {
            if (in_array(strtolower((string) $key), self::RESERVED_KEYS, true)) {
                $found[] = self::safeName($key);
            }
        }

        return $found;
    }
}
