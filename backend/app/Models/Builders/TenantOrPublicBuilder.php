<?php

declare(strict_types=1);

namespace App\Models\Builders;

use App\Services\Intelligence\Exceptions\PublicCatalogReadOnlyException;
use App\Services\Intelligence\PlatformWrite;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use App\Support\Tenancy\TenantContext;

/**
 * Operaciones masivas sobre tablas con `tenant_id` NULLABLE (NULL = catálogo público; ver BelongsToTenantOrPublic).
 *
 * - update / delete / forceDelete / increment / decrement / touch: con empresa en contexto solo alcanzan SUS filas (`tenant_id` = empresa): las públicas
 *   no se modifican ni se borran nunca desde una empresa. Sin empresa, solo dentro de PlatformWrite::run() se tocan las públicas; fuera de él, solo las privadas.
 * - insert / insertGetId / insertOrIgnore / upsert: con empresa en contexto cada fila debe traer `tenant_id` = la empresa (ni NULL = pública, ni otra empresa).
 *   Sin empresa, `tenant_id` NULL solo dentro de PlatformWrite::run().
 * - upsert: además no puede «caer» sobre una fila existente que quien llama no escribiría (pública o de otra empresa) ni reasignar `tenant_id`.
 */
final class TenantOrPublicBuilder extends GuardedWritesBuilder
{
    protected function restrictWrites(): void
    {
        $column = $this->getModel()->qualifyColumn('tenant_id');
        $context = app(TenantContext::class);
        if ($context->has()) {
            $this->where($column, $context->id());
        } elseif (! PlatformWrite::active()) {
            $this->whereNotNull($column);
        }
    }

    protected function assertInsertable(array $rows): void
    {
        $context = app(TenantContext::class);
        foreach ($rows as $row) {
            $tenantId = $row['tenant_id'] ?? null;
            if ($context->has()) {
                if ($tenantId === null) {
                    throw new PublicCatalogReadOnlyException('Una empresa no puede crear filas públicas: cada fila debe llevar el tenant_id de la empresa.');
                }
                if ((int) $tenantId !== $context->id()) {
                    throw new TenantNotResolvedException('Intento de crear un registro para otra empresa.');
                }
            } elseif ($tenantId === null && ! PlatformWrite::active()) {
                throw new TenantNotResolvedException('Un registro público solo lo crea el propietario de la plataforma (PlatformWrite::run).');
            }
        }
    }

    protected function assertUpsertSafe(array $rows, array $uniqueBy, ?array $update): void
    {
        $context = app(TenantContext::class);
        if (! $context->has()) {
            if (PlatformWrite::active() || $uniqueBy === []) {
                return;
            }
        } elseif ($update !== null && in_array('tenant_id', $update, true)) {
            throw new PublicCatalogReadOnlyException('Un upsert no puede reasignar la empresa de una fila.');
        }
        $column = $this->getModel()->qualifyColumn('tenant_id');
        foreach ($rows as $row) {
            $query = $this->getModel()->newQuery()->withoutGlobalScopes();
            foreach ($uniqueBy as $key) {
                if (! array_key_exists($key, $row)) {
                    continue 2;
                }
                $query->where($this->getModel()->qualifyColumn($key), $row[$key]);
            }
            // La fila que se pisaría pertenece a otra parte: pública (NULL) o de otra empresa.
            $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, '<>', $context->has() ? $context->id() : -1));
            if ($query->exists()) {
                throw new PublicCatalogReadOnlyException('El upsert coincide con una fila pública o de otra empresa, que no se puede modificar.');
            }
        }
    }
}
