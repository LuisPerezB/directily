<?php

declare(strict_types=1);

namespace App\Services\Connectors\Connectors;

use App\Models\DataSource;
use App\Services\Connectors\ConnectorInterface;
use App\Services\Connectors\ConnectorTestResult;

abstract class AbstractConnector implements ConnectorInterface
{
    public function credentialKeys(): array
    {
        return [];
    }

    public function test(DataSource $source): ConnectorTestResult
    {
        return match ($this->status()) {
            'available' => new ConnectorTestResult(true, 'Conector disponible.'),
            'requires_credentials' => new ConnectorTestResult(false, 'Este conector requiere credenciales del proveedor y acceso de red al servicio externo. No está activo en este entorno.', ['required' => $this->credentialKeys()]),
            default => new ConnectorTestResult(false, 'Conector planificado: aún no implementado. Mientras tanto use la importación CSV/Excel o la API REST.'),
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'name' => $this->name(),
            'description' => $this->description(),
            'status' => $this->status(),
            'entity_types' => $this->entityTypes(),
            'credential_keys' => $this->credentialKeys(),
        ];
    }
}
