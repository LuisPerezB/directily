<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla `decision_events` (V2.5 · W5, ADR-V25-13): auditoría de cada transición del workflow y del feedback de la agenda; idempotente por
 * `idempotency_key`. Solo se inserta (nunca se actualiza ni se borra fuera de la cascada de la recomendación).
 */
class DecisionEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'decision_events';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
