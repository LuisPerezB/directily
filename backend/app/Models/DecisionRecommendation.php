<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla `decision_recommendations` (V2.5 · W5, ADR-V25-13, §11): recomendación del Decision Engine con el contrato completo de §11.
 * Distinta de `recommendations` (etapa 1: reposición por serie), que se conserva. Contrato del arquitecto (activación de W5): los
 * paquetes agregan relaciones, scopes y lógica en sus servicios, no cambian la tabla ni el significado de los casts. Las transiciones
 * de `status` y `agenda_status` solo las hace el workflow de W5-B (cada una con su `decision_events`).
 */
class DecisionRecommendation extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['suggested', 'assigned', 'reviewed', 'approved', 'rejected', 'in_progress', 'executed', 'measured', 'closed', 'expired'];

    public const AGENDA_STATUSES = ['pendiente', 'contactado', 'interesado', 'pedido_recibido', 'pospuesto', 'descartado'];

    public const ATTRIBUTION_LABELS = ['potential_unproven', 'incremental_estimated', 'causally_attributable'];

    protected $table = 'decision_recommendations';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'as_of' => 'date:Y-m-d',
            'horizon_days' => 'integer',
            'model_versions' => 'array',
            'probability' => 'float',
            'interval' => 'array',
            'assumptions' => 'array',
            'constraints' => 'array',
            'availability' => 'array',
            'action_cost' => 'float',
            'expected_gross_value' => 'float',
            'expected_contribution' => 'float',
            'incremental_estimate' => 'float',
            'incremental_evidence' => 'array',
            'score' => 'float',
            'uncertainty' => 'array',
            'explanation' => 'array',
            'priority' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
