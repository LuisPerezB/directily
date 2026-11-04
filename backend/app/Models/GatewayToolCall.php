<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Builders\GatewayToolCallBuilder;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use LogicException;

/**
 * Tabla `gateway_tool_calls` (V2.5 · W6-D; ADR-V25-17): auditoría de CADA llamada a una herramienta del Intelligence Gateway, permitida o no. Solo inserción, en tres
 * capas: eventos del modelo, constructor de consultas (sin update/delete masivos) y disparador de PostgreSQL (migración 2026_03_14_000500); solo AuditRetention::purge()
 * puede borrar (retención o supresión) y la cascada del borrado de la empresa. `arguments` está minimizado (valores solo de argumentos no textuales; del texto libre, únicamente su longitud) y
 * `result_summary` solo trae estado, procedencia y tamaños: nunca nombres, códigos, correos ni cifras de negocio.
 */
class GatewayToolCall extends Model
{
    public const UPDATED_AT = null;

    use BelongsToTenant;

    protected $table = 'gateway_tool_calls';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'result_summary' => 'array',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @param Builder $query */
    public function newEloquentBuilder($query): GatewayToolCallBuilder
    {
        return new GatewayToolCallBuilder($query);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La auditoría del gateway es de solo inserción.'));
        static::deleting(fn () => throw new LogicException('La auditoría del gateway es de solo inserción.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
