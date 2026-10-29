<?php

declare(strict_types=1);

namespace App\Services\DataCore\Units;

use Illuminate\Support\Str;

/**
 * Normalización TIPOGRÁFICA de unidades (minúsculas, sin acentos, sin espacios sobrantes ni punto final).
 * NO declara equivalencias: «und» y «unidad» siguen siendo unidades distintas hasta que exista una conversión
 * declarada (factor 1 incluido). Así no hay conversiones silenciosas.
 */
final class UnitNormalizer
{
    public static function normalize(?string $unit): ?string
    {
        if ($unit === null || trim($unit) === '') {
            return null;
        }
        $text = Str::lower(Str::ascii(trim($unit)));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return mb_substr(rtrim($text, '. '), 0, 20);
    }
}
