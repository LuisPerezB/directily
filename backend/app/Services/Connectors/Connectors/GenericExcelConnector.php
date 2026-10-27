<?php

declare(strict_types=1);

namespace App\Services\Connectors\Connectors;

final class GenericExcelConnector extends AbstractConnector
{
    public function key(): string
    {
        return 'generic_excel';
    }

    public function name(): string
    {
        return 'Archivo Excel (.xlsx)';
    }

    public function description(): string
    {
        return 'Hojas de cálculo Excel con ventas, productos o inventario. Se usa la primera hoja.';
    }

    public function status(): string
    {
        return 'available';
    }

    public function entityTypes(): array
    {
        return ['sales', 'products', 'inventory', 'lead_times', 'branches'];
    }
}
