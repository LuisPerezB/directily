<?php

declare(strict_types=1);

namespace App\Support\Results;

/**
 * Mapas JSON que siempre salen como OBJETO.
 *
 * Un arreglo PHP no distingue «mapa» de «lista», y hay dos fuentes de error al serializar:
 *  - un mapa vacío (`[]`) sale como `[]` en vez de `{}`;
 *  - `JsonResource` renumera (`array_values`) todo arreglo cuyas claves sean todas numéricas, de modo que un mapa por
 *    horizonte (`{"7": …, "30": …}`) sale como lista y pierde las claves.
 * Un `stdClass` evita ambas cosas: `json_encode` lo emite como objeto y `JsonResource::filter()` no lo recorre (por lo que
 * también protege los mapas anidados de su interior).
 *
 * Usar SOLO en campos que son mapas por contrato (model_version(s), options, target_definition, summary, field_map, stats…);
 * nunca en listas (assumptions, limitations, horizons, entity_keys).
 */
final class JsonMap
{
    /**
     * Un mapa: `null` sigue siendo `null` (ausente), un arreglo (incluso vacío o con claves numéricas) pasa a objeto y
     * cualquier otro valor se devuelve tal cual.
     */
    public static function of(mixed $value): mixed
    {
        return is_array($value) ? (object) $value : $value;
    }

    /** Un mapa que nunca es nulo: ausente o vacío ⇒ `{}`. */
    public static function orEmpty(mixed $value): object
    {
        if ($value instanceof \stdClass) {
            return $value;
        }

        return (object) (is_array($value) ? $value : []);
    }
}
