<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `supply_plan_shortfalls`. Demanda que el plan no atiende, por período y causa.
 * Etapa 2 · P4 (docs/architecture/v2/P4-decisiones.md).
 */
class SupplyPlanShortfall extends Model
{
    use BelongsToTenant;

    protected $table = 'supply_plan_shortfalls';

    public $timestamps = false;

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['demand' => 'float', 'unmet' => 'float', 'period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
