<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Builders\TenantOrPublicBuilder;
use App\Models\Tenant;
use App\Services\Intelligence\Exceptions\PublicCatalogReadOnlyException;
use App\Services\Intelligence\PlatformWrite;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tablas con `tenant_id` NULLABLE: NULL = catálogo público de la plataforma; con valor = dato privado de la empresa.
 *
 * - Lectura: global scope fail-closed. Sin empresa en contexto la consulta falla; con empresa ve lo propio MÁS lo público, nunca lo de otra.
 * - Escritura: una fila pública solo se crea, modifica o borra dentro de PlatformWrite::run() y sin empresa en contexto (propietario de la
 *   plataforma). Con empresa en contexto, una fila nueva siempre queda a nombre de esa empresa (jamás pública), no se puede crear para otra y
 *   no se puede cambiar el `tenant_id` de una fila existente (volver pública una fila privada o pasarla a otra empresa).
 *   Lo anterior vale por instancia (eventos del modelo) Y en masa (TenantOrPublicBuilder: update, delete, insert, upsert…).
 */
trait BelongsToTenantOrPublic
{
    public static function bootBelongsToTenantOrPublic(): void
    {
        static::addGlobalScope('tenant_or_public', function (Builder $builder): void {
            $context = app(TenantContext::class);
            if (! $context->has()) {
                throw new TenantNotResolvedException(
                    'Consulta a '.$builder->getModel()->getTable().' sin empresa activa. Use withoutTenantScope() solo en tareas de plataforma.'
                );
            }
            $table = $builder->getModel()->getTable();
            $builder->where(fn (Builder $q) => $q->where("{$table}.tenant_id", $context->id())->orWhereNull("{$table}.tenant_id"));
        });

        static::creating(function ($model): void {
            $context = app(TenantContext::class);
            $tenantId = $model->getAttribute('tenant_id');
            if ($context->has()) {
                if ($tenantId === null) {
                    $model->setAttribute('tenant_id', $context->id());
                } elseif ((int) $tenantId !== $context->id()) {
                    throw new TenantNotResolvedException('Intento de crear un registro para otra empresa.');
                }

                return;
            }
            if ($tenantId === null && ! PlatformWrite::active()) {
                throw new TenantNotResolvedException('Un registro público solo lo crea el propietario de la plataforma (PlatformWrite::run).');
            }
        });

        static::updating(function ($model): void {
            if ($model->isDirty('tenant_id')) {
                throw new PublicCatalogReadOnlyException('No se puede cambiar la empresa a la que pertenece un registro.');
            }
            $model->assertWritable();
        });

        static::deleting(function ($model): void {
            $model->assertWritable();
        });
    }

    /** Una fila pública solo se modifica como plataforma; una de empresa, con esa empresa en contexto (o sin contexto, en mantenimiento). */
    public function assertWritable(): void
    {
        $tenantId = $this->getOriginal('tenant_id');
        $context = app(TenantContext::class);
        if ($tenantId === null) {
            if (! PlatformWrite::active()) {
                throw new PublicCatalogReadOnlyException;
            }

            return;
        }
        if ($context->has() && (int) $tenantId !== $context->id()) {
            throw new TenantNotResolvedException('Intento de modificar un registro de otra empresa.');
        }
    }

    /**
     * Las operaciones masivas del builder (update, delete, forceDelete, increment, insert, upsert…) no disparan los eventos de modelo: este builder les aplica las
     * mismas reglas (ver TenantOrPublicBuilder), de modo que una empresa tampoco puede tocar el catálogo público «en masa».
     */
    public function newEloquentBuilder($query): TenantOrPublicBuilder
    {
        return new TenantOrPublicBuilder($query);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isPublic(): bool
    {
        return $this->getAttribute('tenant_id') === null;
    }

    /** Solo lo de la empresa activa (excluye lo público): para operaciones de escritura de la empresa. */
    public function scopeOwn(Builder $query): Builder
    {
        return $query->where($this->getTable().'.tenant_id', app(TenantContext::class)->require()->getKey());
    }

    /** Solo el catálogo público (para el propietario de la plataforma, sin empresa en contexto). */
    public static function publicCatalog(): Builder
    {
        return static::withoutGlobalScope('tenant_or_public')->whereNull((new static)->getTable().'.tenant_id');
    }

    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope('tenant_or_public');
    }
}
