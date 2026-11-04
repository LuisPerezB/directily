<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla `model_promotion_decisions` (V2.5 · W3, ADR-V25-21): decisión de la política de promoción (heredada y V2.5, en paralelo)
 * por ejecución y alcance. El motor la escribe; la aprobación humana separada (`approved_by`, `approved_at`) la completa el
 * registro de modelos (W4 · S06) cuando un aprobador distinto de quien entrenó o publicó aprueba el modelo asociado.
 */
class ModelPromotionDecision extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'model_promotion_decisions';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'is_effective' => 'boolean',
            'gates' => 'array',
            'metrics' => 'array',
            'approved_at' => 'datetime',
            'decided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
