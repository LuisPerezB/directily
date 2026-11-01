<?php

declare(strict_types=1);

namespace App\Models\Builders;

use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use App\Support\Tenancy\TenantContext;
use LogicException;

/**
 * Operaciones masivas sobre `shared_forecast_grants`: la lectura es de las dos partes (emisor y receptor) pero la ESCRITURA es solo del emisor. Con empresa
 * en contexto, update / delete / increment… solo alcanzan las concesiones que la empresa EMITIÓ (el receptor no puede acortar, alargar ni borrar lo que recibió)
 * y solo se crean concesiones con `issuer_tenant_id` = la empresa. Sin empresa en contexto no hay escritura masiva (la lectura ya falla por el scope).
 */
final class PartyOwnedBuilder extends GuardedWritesBuilder
{
    protected function restrictWrites(): void
    {
        $context = app(TenantContext::class);
        if (! $context->has()) {
            throw new TenantNotResolvedException('Escritura sobre shared_forecast_grants sin empresa activa.');
        }
        $this->where($this->getModel()->qualifyColumn('issuer_tenant_id'), $context->id());
    }

    protected function assertInsertable(array $rows): void
    {
        $context = app(TenantContext::class);
        foreach ($rows as $row) {
            if (! $context->has() || (int) ($row['issuer_tenant_id'] ?? 0) !== $context->id()) {
                throw new LogicException('Solo la empresa emisora crea una concesión.');
            }
        }
    }
}
