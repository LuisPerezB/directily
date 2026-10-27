<?php

declare(strict_types=1);

namespace App\Services\Connectors\Connectors;

final class GenericCsvConnector extends AbstractConnector
{
    public function key(): string { return 'generic_csv'; }
    public function name(): string { return 'Archivo CSV'; }
    public function description(): string { return 'Exportación CSV de cualquier sistema de facturación, POS o ERP. Se carga con el asistente de importación.'; }
    public function status(): string { return 'available'; }
    public function entityTypes(): array { return ['sales', 'products', 'inventory', 'lead_times', 'branches']; }
}
