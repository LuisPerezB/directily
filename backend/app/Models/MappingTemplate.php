<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plantilla de mapeo del Auto Mapper (V2.5 §4): propuesta determinista versionada por (empresa o plataforma, sistema, proveedor, entidad).
 *
 * `tenant_id` NULL = plantilla PÚBLICA de la plataforma: la ven todas las empresas y solo la crea y aprueba un superadministrador; las
 * empresas la leen, la aplican a sus fuentes o la bifurcan, nunca la modifican. A diferencia de BelongsToTenant, el alcance global deja ver
 * las filas de la empresa activa Y las públicas, y sigue siendo fail-closed: sin empresa activa, la consulta falla. Las tareas de plataforma
 * usan `platform()` (solo públicas, sin empresa) y los tests de aislamiento `withoutTenantScope()`.
 */
class MappingTemplate extends Model
{
    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RETIRED = 'retired';

    protected $guarded = ['id', 'tenant_id'];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant_or_public', function (Builder $builder): void {
            $context = app(TenantContext::class);
            if (! $context->has()) {
                throw new TenantNotResolvedException(
                    'Consulta a mapping_templates sin empresa activa. Use MappingTemplate::platform() en tareas de plataforma.'
                );
            }
            $table = $builder->getModel()->qualifyColumn('tenant_id');
            $builder->where(function (Builder $q) use ($table, $context): void {
                $q->where($table, $context->id())->orWhereNull($table);
            });
        });

        static::creating(function (self $model): void {
            // Una fila de empresa siempre nace con la empresa del contexto; una pública se crea con ::createPublic() (plataforma).
            $context = app(TenantContext::class);
            $tenantId = $model->getAttribute('tenant_id');
            if ($tenantId !== null && $context->has() && (int) $tenantId !== $context->id()) {
                throw new TenantNotResolvedException('Intento de crear una plantilla para otra empresa.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'field_map' => 'array',
            'value_maps' => 'array',
            'confidence' => 'array',
            'source_spec' => 'array',
            'drift' => 'array',
            'approved_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** Solo plantillas públicas, sin necesitar empresa (tareas de plataforma). */
    public static function platform(): Builder
    {
        return static::withoutGlobalScope('tenant_or_public')->whereNull('tenant_id');
    }

    /** Sin ningún alcance (pruebas y mantenimiento). */
    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope('tenant_or_public');
    }

    public function isPublic(): bool
    {
        return $this->tenant_id === null;
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
