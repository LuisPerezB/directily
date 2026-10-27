<?php

declare(strict_types=1);

namespace App\Services\Imports;

enum ImportEntity: string
{
    case Sales = 'sales';
    case Products = 'products';
    case Inventory = 'inventory';
    case LeadTimes = 'lead_times';
    case Branches = 'branches';
    case Suppliers = 'suppliers';
    case Promotions = 'promotions';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Ventas (líneas de documentos)',
            self::Products => 'Productos',
            self::Inventory => 'Inventario (existencias por fecha)',
            self::LeadTimes => 'Plazos de abastecimiento',
            self::Branches => 'Sucursales',
            self::Suppliers => 'Proveedores',
            self::Promotions => 'Promociones',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $e) => $e->value, self::cases());
    }
}
