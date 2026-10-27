<?php

declare(strict_types=1);

namespace App\Services\Connectors\Connectors;

/**
 * Ejemplo de conector saliente para un sistema de facturación usado en Ecuador.
 * Requiere la API key del cliente y validar el contrato de su API; no está implementada
 * la sincronización (no se finge integración).
 */
final class ContificoConnector extends AbstractConnector
{
    public function key(): string
    {
        return 'contifico';
    }

    public function name(): string
    {
        return 'Contífico (planificado)';
    }

    public function description(): string
    {
        return 'Sincronización de documentos de venta desde Contífico mediante su API. Requiere credenciales del cliente; pendiente de implementación y pruebas con una cuenta real.';
    }

    public function status(): string
    {
        return 'requires_credentials';
    }

    public function entityTypes(): array
    {
        return ['sales', 'products'];
    }

    public function credentialKeys(): array
    {
        return ['api_key', 'api_token'];
    }
}
