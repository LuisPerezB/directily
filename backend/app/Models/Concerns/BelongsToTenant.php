<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aislamiento multi-tenant a nivel de modelo.
 *
 * - Global scope: toda consulta se filtra por el tenant del contexto. Si NO hay tenant
 *   en contexto, la consulta falla (fail-closed) en lugar de devolver datos de todas
 *   las empresas. Para tareas de plataforma (superadmin, comandos de mantenimiento)
 *   usar explícitamente Model::withoutTenantScope().
 * - Al crear, asigna tenant_id desde el contexto e impide crear registros para
 *   otro tenant distinto al activo.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $context = app(TenantContext::class);
            if (! $context->has()) {
                throw new TenantNotResolvedException(
                    'Consulta a '.$builder->getModel()->getTable().' sin empresa activa. '
                    .'Use withoutTenantScope() solo en tareas de plataforma.'
                );
            }
            $builder->where($builder->getModel()->qualifyColumn('tenant_id'), $context->id());
        });

        static::creating(function ($model): void {
            $context = app(TenantContext::class);
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $context->require()->getKey());
            } elseif ($context->has() && (int) $model->getAttribute('tenant_id') !== $context->id()) {
                throw new TenantNotResolvedException('Intento de crear un registro para otra empresa.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope('tenant');
    }
}
