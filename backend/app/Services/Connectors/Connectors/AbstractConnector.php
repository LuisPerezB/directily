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
            'requires_credentials', 'implemented_untested' => new ConnectorTestResult(false, 'Este conector requiere credenciales del proveedor y acceso de red al servicio externo. No está activo en este entorno.', ['required' => $this->credentialKeys()]),
            default => new ConnectorTestResult(false, 'Conector planificado: aún no implementado. Mientras tanto use la importación CSV/Excel o la API REST.'),
        };
    }

    /**
     * Nivel de comprobación real del conector (etapa 2): tested (suite automatizada del repositorio),
     * tested_with_test_database, implemented_untested o not_verified/planned. Nunca se infiere de `status`.
     */
    public function verification(): string
    {
        return match ($this->status()) {
            'available' => 'tested',
            'planned' => 'planned',
            default => 'not_verified',
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
            'verification' => $this->verification(),
            'entity_types' => $this->entityTypes(),
            'credential_keys' => $this->credentialKeys(),
        ];
    }
}
