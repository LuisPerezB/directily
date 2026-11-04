<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Tenant;
use App\Services\Tenancy\TenantRls;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;

/**
 * Contexto de empresa (tenant) activo para la petición o el trabajo en cola actual.
 *
 * Se registra como singleton (scoped) en el contenedor. El middleware ResolveTenant lo
 * establece en peticiones HTTP; los Jobs lo restablecen con el middleware de cola
 * SetTenantContext (cada Job debe transportar el tenant_id explícitamente).
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        TenantRls::sync($tenant?->getKey());   // W6-A: RLS de PostgreSQL (bandera apagada por defecto: no hace nada)
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    /** @throws TenantNotResolvedException */
    public function require(): Tenant
    {
        return $this->tenant ?? throw new TenantNotResolvedException;
    }

    /**
     * Ejecuta un callback con un tenant concreto y restaura el anterior.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;
        TenantRls::sync($tenant->getKey());
        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
            TenantRls::sync($previous?->getKey());
        }
    }

    public function forget(): void
    {
        $this->tenant = null;
        TenantRls::sync(null);
    }
}
