<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Discovery;

use App\Support\Privacy\PiiMasker;

/**
 * Filtro de los valores distintos que salen del muestreo hacia las respuestas, las plantillas y la exportación. Es una LISTA PERMITIDA de forma de vocabulario, no una lista
 * negra de datos personales: un valor sale solo si es un código corto de tipo o estado de documento.
 *
 *  - caracteres: letras latinas (con las tildes del español), dígitos y `_ . / -`, hasta 20 por palabra; cualquier otro (arroba de ancho completo, letras de otros alfabetos
 *    que se parecen a las latinas, símbolos, emojis, comas) descarta el valor;
 *  - a lo sumo 2 palabras (y no dos en Mayúscula inicial, que tienen forma de nombre de persona); hasta 4 solo si el importador YA reconoce el valor como tipo o estado de documento («NOTA DE CREDITO»; el llamador lo indica con `$knownVocabulary`);
 *  - menos de 7 dígitos en total (números de tarjeta, teléfonos, cédulas y RUC tienen más, con o sin separadores);
 *  - sin forma de correo, token o identificación (PiiMasker), sin `@` y sin caracteres de control (que `trim` no debe quitar en silencio).
 * Un valor descartado NO se devuelve en ninguna forma (ni enmascarado) y quien llama deja de tratar la columna como vocabulario. La frecuencia mínima (`min_value_count`) la
 * aplica el muestreador sobre los valores que sí pasan este filtro.
 */
final class SampleMasker
{
    private const WORD = '[A-Za-z0-9_.\/\-ÁÉÍÓÚÜÑáéíóúüñ]{1,20}';

    /** @return ?string el valor tal cual si es seguro; null si no debe salir */
    public static function value(mixed $raw, ?int $maxLength = null, bool $knownVocabulary = false): ?string
    {
        if ($raw === null || is_array($raw) || is_object($raw) || is_resource($raw)) {
            return null;
        }
        $string = is_bool($raw) ? ($raw ? 'true' : 'false') : (string) $raw;
        // trim() también quitaría un NUL: un valor con caracteres de control se descarta entero, no se «limpia».
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $string) === 1) {
            return null;
        }
        $text = trim($string);
        $maxLength ??= (int) config('mapping.sampling.value_max_length', 40);
        if ($text === '' || mb_strlen($text) > $maxLength || ! mb_check_encoding($text, 'UTF-8') || preg_match('/[\p{C}]/u', $text) === 1) {
            return null;
        }
        if (preg_match_all('/\d/', $text) >= 7 || str_contains($text, '@') || PiiMasker::mask($text) !== $text) {
            return null;
        }
        $word = self::WORD;
        if ($knownVocabulary && preg_match("/^{$word}( {$word}){0,3}$/u", $text) === 1) {
            return $text;
        }
        if (preg_match("/^{$word}( {$word})?$/u", $text) !== 1) {
            return null;
        }
        // Dos palabras en Mayúscula inicial y resto en minúscula («Juan Perez») tienen forma de nombre de persona, no de vocabulario: no salen salvo que el importador las reconozca.
        $words = explode(' ', $text);
        if (count($words) === 2 && preg_match('/^\p{Lu}\p{Ll}{2,}$/u', $words[0]) === 1 && preg_match('/^\p{Lu}\p{Ll}{2,}$/u', $words[1]) === 1) {
            return null;
        }

        return $text;
    }
}
