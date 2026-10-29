<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Evento de calendario con alcance (país, región, empresa, sucursal, categoría, producto, sector) y estado de
 * conocimiento. `tenant_id` NULL = calendario público (lectura para todas las empresas; la empresa nunca lo escribe).
 * Aislamiento fail-closed: sin empresa en contexto la consulta falla; con empresa solo ve lo propio y lo público.
 */
class CalendarEvent extends Model
{
    public const SCOPES = ['country', 'region', 'tenant', 'branch', 'category', 'product', 'sector'];

    public const CATEGORIES = ['holiday', 'local_holiday', 'commercial_season', 'campaign', 'promotion', 'sector_event', 'agricultural_season', 'international_peak', 'exceptional'];

    public const STATUSES = ['planned', 'confirmed', 'cancelled'];

    protected $guarded = ['id', 'tenant_id'];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant_or_public', function (Builder $builder): void {
            $context = app(TenantContext::class);
            if (! $context->has()) {
                throw new TenantNotResolvedException('Consulta a calendar_events sin empresa activa.');
            }
            $table = $builder->getModel()->getTable();
            $builder->where(fn (Builder $q) => $q->where("{$table}.tenant_id", $context->id())->orWhereNull("{$table}.tenant_id"));
        });

        static::creating(function (self $model): void {
            $context = app(TenantContext::class);
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $context->require()->getKey());
            } elseif ((int) $model->getAttribute('tenant_id') !== $context->id()) {
                throw new TenantNotResolvedException('Intento de crear un evento para otra empresa.');
            }
        });
    }

    /** Solo los eventos de la empresa activa (excluye el calendario público): para escribir. */
    public function scopeOwn(Builder $query): Builder
    {
        return $query->where($this->getTable().'.tenant_id', app(TenantContext::class)->require()->getKey());
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'known_at' => 'datetime',
        ];
    }
}
