<?php

declare(strict_types=1);

namespace App\Services\Analytics\Support;

/** Redondeo consistente para respuestas JSON. */
final class Num
{
    public static function units(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 4);
    }

    public static function money(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }

    public static function ratio(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 4);
    }

    /** Variación relativa (null si la base es 0 o nula). */
    public static function change(?float $new, ?float $base): ?float
    {
        if ($new === null || $base === null || abs($base) < 1e-9) {
            return null;
        }

        return round(($new - $base) / $base, 4);
    }
}
