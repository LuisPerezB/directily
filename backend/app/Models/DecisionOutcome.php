<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `decision_outcomes` (V2.5 · W5, ADR-V25-14): recomendación → acción → evento observable posterior, con clasificación honesta
 * (forecast, observed, temporally_associated, incremental_estimated, causally_attributable) y contribución, no ingresos brutos.
 */
class DecisionOutcome extends Model
{
    use BelongsToTenant;

    public const CREATED_AT = 'measured_at';

    public const UPDATED_AT = null;

    protected $table = 'decision_outcomes';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'action_at' => 'datetime',
            'window_from' => 'date:Y-m-d',
            'window_to' => 'date:Y-m-d',
            'observed_units' => 'float',
            'observed_net_amount' => 'float',
            'contribution' => 'float',
            'contact_cost' => 'float',
            'evidence' => 'array',
            'measured_at' => 'datetime',
        ];
    }

    // >>> W5-C outcomes (relaciones; no cambian la tabla ni los casts)
    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(DecisionRecommendation::class, 'recommendation_id');
    }
    // <<< W5-C outcomes
}
