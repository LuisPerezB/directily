<?php

declare(strict_types=1);

namespace App\Services\Analytics\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Agregación temporal: día, semana ISO (inicia lunes) o mes (día 1). */
final class Granularity
{
    public const DAY = 'day';

    public const WEEK = 'week';

    public const MONTH = 'month';

    public const ALL = [self::DAY, self::WEEK, self::MONTH];

    /** Expresión SQL (PostgreSQL) que trunca una columna de fecha al inicio del período. */
    public static function truncSql(string $granularity, string $column): string
    {
        return match ($granularity) {
            self::DAY => "{$column}::date",
            self::WEEK => "date_trunc('week', {$column})::date",
            self::MONTH => "date_trunc('month', {$column})::date",
            default => throw new InvalidArgumentException("Granularidad no soportada: {$granularity}"),
        };
    }

    public static function periodStart(string $granularity, CarbonImmutable $date): CarbonImmutable
    {
        return match ($granularity) {
            self::DAY => $date->startOfDay(),
            self::WEEK => $date->startOfWeek(CarbonImmutable::MONDAY)->startOfDay(),
            self::MONTH => $date->startOfMonth()->startOfDay(),
            default => throw new InvalidArgumentException("Granularidad no soportada: {$granularity}"),
        };
    }
}
