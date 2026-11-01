<?php

declare(strict_types=1);

namespace App\Support\Horizons;

/**
 * Catálogo ÚNICO de horizontes (ADR-V25-02): `predictily.horizons.catalog` = [7, 14, 15, 30, 60, 90].
 *
 * El catálogo dice qué horizontes EXISTEN; el plan comercial (`plans.limits.horizons` + `tenants.plan_overrides`) dice cuáles puede usar
 * cada empresa (PlanLimitService). Demanda acepta todo el catálogo; Customer su subconjunto (`predictily.horizons.customer`, sin 60).
 * El motor declara el mismo catálogo en `predictily_engine.platform.config.HORIZONS`; una prueba de cada lado verifica que coinciden.
 */
final class HorizonCatalog
{
    /** @return list<int> todos los horizontes del dominio, ascendentes */
    public static function all(): array
    {
        return self::ints((array) config('predictily.horizons.catalog', []));
    }

    /** @return list<int> horizontes que acepta la demanda (todo el catálogo) */
    public static function demand(): array
    {
        return self::all();
    }

    /** @return list<int> horizontes de Customer: el subconjunto declarado, siempre contenido en el catálogo */
    public static function customer(): array
    {
        return array_values(array_intersect(self::all(), self::ints((array) config('predictily.horizons.customer', []))));
    }

    public static function isValid(int $horizon): bool
    {
        return in_array($horizon, self::all(), true);
    }

    /**
     * Normaliza una lista de horizontes pedida (p. ej. `plan_overrides.horizons`): enteros únicos y ascendentes.
     *
     * @param  array<int|string, mixed>  $values
     * @return list<int>
     */
    public static function ints(array $values): array
    {
        $out = array_values(array_unique(array_map('intval', $values)));
        sort($out);

        return $out;
    }
}
