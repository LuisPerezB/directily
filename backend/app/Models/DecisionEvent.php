<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    // >>> W5-B decision engine
    public const EVENTS = ['transition', 'feedback', 'assignment', 'comment'];

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(DecisionRecommendation::class, 'recommendation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
    // <<< W5-B decision engine
}
