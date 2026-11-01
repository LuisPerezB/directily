<?php

declare(strict_types=1);

namespace App\Services\DataCore\Quality;

use App\Services\Imports\Parsing\ValueParser;

/**
 * Mapeos de valores por fuente (ADR-V25-24; W2-A): `{campo: {"<crudo>": "<canónico>"}}`, en `import_jobs.options.value_maps` (archivos)
 * y en `source_mappings.options.value_maps` (sincronización SQL). Se aplican ANTES del reconocimiento de vocabulario, de modo que un
 * estado que la fuente llama «RECHAZADA» pueda declararse como «anulada» y deje de ser REVIEW_REQUIRED.
 *
 * - Coincidencia: primero EXACTA (comportamiento histórico del conector SQL) y, si no, por clave normalizada (minúsculas, sin tildes,
 *   espacios y signos como «_»): «Rechazada» y «rechazada » coinciden.
 * - Validación: en ventas, los valores de `document_type` y `status` deben pertenecer al vocabulario canónico (los reconoce
 *   `ValueParser::documentType` / `documentStatus`); un destino fuera del vocabulario se rechaza al guardar, no al cargar.
 * - Registro: cada aplicación se cuenta (campo, valor crudo → canónico, ocurrencias) para `metrics.transformations`.
 */
final class ValueMaps
{
    /** Campos de ventas cuyo destino se valida contra el vocabulario canónico. */
    public const VOCABULARY_FIELDS = ['document_type', 'status'];

    /** Tope de entradas por campo (un mapeo de valores es un diccionario de vocabulario, no una tabla de datos). */
    public const MAX_ENTRIES_PER_FIELD = 500;

    /** @var array<string, array<string, mixed>> */
    private array $maps;

    /** @var array<string, array<string, string>> campo => clave normalizada => clave original */
    private array $index = [];

    /** @var array<string, array<string, array{to: mixed, count: int}>> campo => crudo => aplicación */
    private array $hits = [];

    /** @param array<string, mixed> $maps */
    public function __construct(array $maps = [])
    {
        $this->maps = [];
        foreach ($maps as $field => $map) {
            if (! is_string($field) || ! is_array($map) || $map === []) {
                continue;
            }
            $this->maps[$field] = $map;
            foreach (array_keys($map) as $raw) {
                $normalized = self::normalizeKey((string) $raw);
                if ($normalized !== '') {
                    $this->index[$field][$normalized] ??= (string) $raw;
                }
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->maps === [];
    }

    /** @return list<string> */
    public function fields(): array
    {
        return array_keys($this->maps);
    }

    /** @return array<string, array<string, mixed>> */
    public function toArray(): array
    {
        return $this->maps;
    }

    /** Aplica el mapeo del campo (si existe) al valor crudo y cuenta la aplicación. */
    public function apply(string $field, mixed $value, bool $count = true): mixed
    {
        if (! isset($this->maps[$field]) || $value === null || ! (is_scalar($value))) {
            return $value;
        }
        $text = (string) $value;
        if (array_key_exists($text, $this->maps[$field])) {
            $original = $text;
        } else {
            $original = $this->index[$field][self::normalizeKey($text)] ?? null;
        }
        if ($original === null) {
            return $value;
        }
        $target = $this->maps[$field][$original];
        if ($count) {
            $this->hits[$field][$original] ??= ['to' => $target, 'count' => 0];
            $this->hits[$field][$original]['count']++;
        }

        return $target;
    }

    /**
     * Aplicaciones registradas: campo => [{from, to, count}].
     *
     * @return array<string, list<array{from: string, to: mixed, count: int}>>
     */
    public function hits(): array
    {
        $out = [];
        foreach ($this->hits as $field => $entries) {
            foreach ($entries as $from => $entry) {
                $out[$field][] = ['from' => (string) $from, 'to' => $entry['to'], 'count' => $entry['count']];
            }
        }

        return $out;
    }

    /** Une aplicaciones externas (p. ej. las del conector SQL) con las propias. */
    public function absorb(array $hits): void
    {
        foreach ($hits as $field => $entries) {
            foreach ($entries as $entry) {
                $this->hits[$field][(string) $entry['from']] ??= ['to' => $entry['to'], 'count' => 0];
                $this->hits[$field][(string) $entry['from']]['count'] += (int) $entry['count'];
            }
        }
    }

    /** Huella estable de los mapeos (entra en `rules_version`). */
    public function digest(): string
    {
        $maps = $this->maps;
        ksort($maps);
        foreach ($maps as $field => $map) {
            ksort($map);
            $maps[$field] = $map;
        }

        return hash('sha256', json_encode($maps, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /** @param array<string, mixed> ...$sets de menor a mayor precedencia */
    public static function merge(array ...$sets): array
    {
        $merged = [];
        foreach ($sets as $set) {
            foreach ($set as $field => $map) {
                if (is_array($map)) {
                    $merged[$field] = array_merge($merged[$field] ?? [], $map);
                }
            }
        }

        return $merged;
    }

    public static function normalizeKey(string $text): string
    {
        return ValueParser::key($text);
    }

    /**
     * Valida y limpia un diccionario de mapeos para una entidad. Devuelve los mapeos limpios; los errores se acumulan en `$errors`
     * con la clave `"{$prefix}.<campo>"`.
     *
     * @param  array<string, list<string>>  $errors
     * @param  list<string>|null  $allowedFields  campos permitidos (null = cualquiera; el conector SQL valida contra los campos del importador)
     * @return array<string, array<string, string|null>>
     */
    public static function validate(mixed $maps, string $entity, array &$errors, string $prefix, ?array $allowedFields = null): array
    {
        if (! is_array($maps)) {
            $errors[$prefix][] = 'Debe ser un diccionario {campo: {valor_origen: valor_canónico}}.';

            return [];
        }
        $clean = [];
        foreach ($maps as $field => $map) {
            $key = "{$prefix}.{$field}";
            if (! is_string($field) || ($allowedFields !== null && ! in_array($field, $allowedFields, true)) || ! is_array($map)) {
                $errors[$key][] = 'Debe ser un diccionario valor_origen => valor_canónico de un campo del importador.';

                continue;
            }
            if (count($map) > self::MAX_ENTRIES_PER_FIELD) {
                $errors[$key][] = 'Demasiadas entradas (máximo '.self::MAX_ENTRIES_PER_FIELD.'): un mapeo de valores es un vocabulario, no una tabla de datos.';

                continue;
            }
            foreach ($map as $raw => $target) {
                $raw = (string) $raw;
                if (trim($raw) === '' || mb_strlen($raw) > 100) {
                    $errors[$key][] = 'Cada valor de origen debe ser un texto no vacío de hasta 100 caracteres.';

                    continue 2;
                }
                if ($target !== null && ! is_scalar($target)) {
                    $errors[$key][] = "El destino de «{$raw}» debe ser un texto.";

                    continue 2;
                }
                if ($entity === 'sales' && in_array($field, self::VOCABULARY_FIELDS, true)) {
                    $text = $target === null ? '' : trim((string) $target);
                    $recognized = $text !== '' && ($field === 'document_type' ? ValueParser::documentType($text) !== null : ValueParser::documentStatus($text) !== null);
                    if (! $recognized) {
                        $errors[$key][] = $field === 'document_type'
                            ? "«{$raw}» → «{$text}»: el destino debe ser un tipo canónico (factura, nota de venta, nota de crédito u otro)."
                            : "«{$raw}» → «{$text}»: el destino debe ser un estado canónico (completada, anulada o pendiente).";

                        continue 2;
                    }
                }
                $clean[$field][$raw] = $target === null ? null : (string) $target;
            }
        }

        return $clean;
    }
}
