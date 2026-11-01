<?php

declare(strict_types=1);

namespace App\Support\Canonical;

use InvalidArgumentException;

/**
 * Versiones de los contratos canónicos (V2.5 §3; ADR-V25-24; arquitecto).
 *
 * Cada registro Silver guarda en `schema_version` la versión del contrato con que se escribió. El paquete W2-B
 * completa este catálogo con la lista de campos de cada entidad y una prueba que falla si las columnas cambian sin
 * subir la versión. Formato: `<entidad>@<año>.<mes>.<correlativo>`.
 */
final class CanonicalContracts
{
    /** @var array<string, string> */
    public const VERSIONS = [
        'sales' => 'sales@2026.03.1',
        'sales_line' => 'sales_line@2026.03.1',
        'inventory_snapshot' => 'inventory_snapshot@2026.03.1',
        'product' => 'product@2026.03.1',
        'customer' => 'customer@2026.03.1',
        'commercial_commitment' => 'commercial_commitment@2026.03.1',
    ];

    public static function version(string $entity): string
    {
        return self::VERSIONS[$entity] ?? throw new InvalidArgumentException("Contrato canónico desconocido: {$entity}.");
    }
}
