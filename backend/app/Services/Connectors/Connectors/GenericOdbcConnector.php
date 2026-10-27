<?php

declare(strict_types=1);

namespace App\Services\Connectors\Connectors;

final class GenericOdbcConnector extends AbstractConnector
{
    public function key(): string { return 'generic_odbc'; }
    public function name(): string { return 'Base de datos del ERP (ODBC/SQL)'; }
    public function description(): string { return 'Lectura programada de vistas SQL del ERP local mediante un agente instalado en la red del cliente. Planificado.'; }
    public function status(): string { return 'planned'; }
    public function entityTypes(): array { return ['sales', 'products', 'inventory']; }
    public function credentialKeys(): array { return ['dsn', 'username', 'password']; }
}
