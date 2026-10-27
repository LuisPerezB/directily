<?php

declare(strict_types=1);

namespace App\Enums;

enum Sector: string
{
    case Ferreteria = 'ferreteria';
    case Distribuidora = 'distribuidora';
    case RepuestosAutomotrices = 'repuestos_automotrices';
    case RepuestosAgricolas = 'repuestos_agricolas';
    case RetailMultisucursal = 'retail_multisucursal';
    case Importadora = 'importadora';
    case ConsumoRecurrente = 'consumo_recurrente';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Ferreteria => 'Ferretería',
            self::Distribuidora => 'Distribuidora mayorista/minorista',
            self::RepuestosAutomotrices => 'Repuestos automotrices',
            self::RepuestosAgricolas => 'Repuestos e insumos agrícolas',
            self::RetailMultisucursal => 'Tienda con varias sucursales',
            self::Importadora => 'Importadora / comercializadora',
            self::ConsumoRecurrente => 'Productos de consumo recurrente',
            self::Otro => 'Otro',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
