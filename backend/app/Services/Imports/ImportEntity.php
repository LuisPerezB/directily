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
    // Etapa 2 · P3: entidades canónicas nuevas.
    case Commitments = 'commitments';
    case CalendarEvents = 'calendar_events';
    case UnitConversions = 'unit_conversions';
    case Warehouses = 'warehouses';
    // V2.5 · W5-D: lotes con vencimiento por sucursal y producto (perecibilidad y FEFO; ADR-V25-12..14, §10).
    case InventoryLots = 'inventory_lots';

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
            self::Commitments => 'Compromisos comerciales (cotizaciones, pedidos, contratos)',
            self::CalendarEvents => 'Calendario (eventos con alcance)',
            self::UnitConversions => 'Conversiones de unidades',
            self::Warehouses => 'Almacenes',
            self::InventoryLots => 'Lotes de inventario (vencimiento por lote)',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $e) => $e->value, self::cases());
    }
}
