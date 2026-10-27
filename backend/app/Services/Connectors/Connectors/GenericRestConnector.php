<?php

declare(strict_types=1);

namespace App\Services\Connectors\Connectors;

final class GenericRestConnector extends AbstractConnector
{
    public function key(): string
    {
        return 'generic_rest';
    }

    public function name(): string
    {
        return 'API REST de Predictily';
    }

    public function description(): string
    {
        return 'Su sistema envía documentos de venta a POST /api/v1/sales/batch con un token ligado a la empresa. Idempotente por tipo y número de documento.';
    }

    public function status(): string
    {
        return 'available';
    }

    public function entityTypes(): array
    {
        return ['sales'];
    }
}
