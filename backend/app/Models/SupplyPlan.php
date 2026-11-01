<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabla `supply_plans`. Plan de reposición (sugerencias: no emite pedidos ni compromete fondos). Estado del solver: optimal, feasible_with_gap, time_limit, infeasible, heuristic_fallback o not_solved.
 * Etapa 2 · P4 (docs/architecture/v2/P4-decisiones.md).
 */
class SupplyPlan extends Model
{
    use BelongsToTenant;

    protected $table = 'supply_plans';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['mip_gap' => 'float', 'objective_value' => 'float', 'solve_seconds' => 'float', 'total_purchase_cost' => 'float', 'total_holding_cost' => 'float', 'total_shortage_penalty' => 'float', 'unmet_units_total' => 'float', 'demand_units_total' => 'float', 'details' => 'array'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplyPlanLine::class, 'supply_plan_id');
    }

    public function shortfalls(): HasMany
    {
        return $this->hasMany(SupplyPlanShortfall::class, 'supply_plan_id');
    }

    public function coreRun(): BelongsTo
    {
        return $this->belongsTo(CoreRun::class, 'core_run_id');
    }
}
