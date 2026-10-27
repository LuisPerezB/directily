<?php

declare(strict_types=1);

namespace App\Services\Imports\Mapping;

use App\Services\Imports\Fields\FieldDefinition;
use Illuminate\Support\Str;

/**
 * Sugiere el mapeo columna-origen → campo Predictily por sinónimos.
 * Normaliza acentos, mayúsculas y separadores ("Fecha Emisión" → "fecha_emision").
 */
final class ColumnMatcher
{
    public static function normalize(string $header): string
    {
        $h = Str::lower(Str::ascii(trim($header)));
        $h = preg_replace('/[^a-z0-9]+/', '_', $h) ?? $h;

        return trim($h, '_');
    }

    /**
     * @param list<FieldDefinition> $fields
     * @param list<string> $headers
     * @return array<string, string|null> campo => encabezado original (o null)
     */
    public function suggest(array $fields, array $headers): array
    {
        $normalizedHeaders = [];
        foreach ($headers as $header) {
            $normalizedHeaders[$header] = self::normalize($header);
        }

        $mapping = array_fill_keys(array_map(fn (FieldDefinition $f) => $f->key, $fields), null);
        $used = [];

        // 1) Coincidencia exacta con la clave canónica o un sinónimo (en orden de prioridad).
        foreach ($fields as $field) {
            $candidates = array_merge([$field->key], array_map(self::normalize(...), $field->synonyms));
            foreach ($candidates as $candidate) {
                $header = array_search($candidate, $normalizedHeaders, true);
                if ($header !== false && ! isset($used[$header])) {
                    $mapping[$field->key] = (string) $header;
                    $used[$header] = true;
                    break;
                }
            }
        }

        // 2) Coincidencia parcial por tokens (p. ej. "cantidad_vendida_total" → quantity).
        foreach ($fields as $field) {
            if ($mapping[$field->key] !== null) {
                continue;
            }
            foreach ($normalizedHeaders as $header => $normalized) {
                if (isset($used[$header])) {
                    continue;
                }
                foreach (array_merge([$field->key], $field->synonyms) as $synonym) {
                    $syn = self::normalize($synonym);
                    if (strlen($syn) >= 4 && preg_match('/(^|_)'.preg_quote($syn, '/').'(_|$)/', $normalized) === 1) {
                        $mapping[$field->key] = (string) $header;
                        $used[$header] = true;
                        continue 3;
                    }
                }
            }
        }

        return $mapping;
    }
}
