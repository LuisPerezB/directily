<?php

declare(strict_types=1);

namespace App\Services\Intelligence;

use App\Services\Intelligence\Exceptions\PublicCatalogReadOnlyException;
use App\Support\Tenancy\TenantContext;

/**
 * Marca explícita de «operación de plataforma»: la única forma de crear, modificar o borrar una fila del catálogo público (tenant_id NULL).
 * Solo vale SIN empresa en contexto (seeder, comandos, rutas /admin del superadministrador): dentro de una petición de empresa nunca se
 * puede abrir, aunque el código lo pida. Es el equivalente en escritura del global scope fail-closed de lectura.
 */
final class PlatformWrite
{
    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        if (app(TenantContext::class)->has()) {
            throw new PublicCatalogReadOnlyException;
        }
        self::$depth++;
        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0 && ! app(TenantContext::class)->has();
    }
}
