<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla `tenant_usage_records` (V2.5 · W4, ADR-V25-09 y §14): consumo medido por empresa y día (segundos de cómputo del motor,
 * bytes almacenados, ejecuciones) que alimenta el modelo de costos. NO lleva precios: los precios son parámetros del modelo de
 * costos (docs/architecture/AWS_COST_MODEL.md). Distinta de `tenant_usage` (contadores por período para los límites del plan).
 */
class TenantUsageRecord extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'tenant_usage_records';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'period' => 'date:Y-m-d',
            'quantity' => 'float',
            'created_at' => 'datetime',
        ];
    }
}
