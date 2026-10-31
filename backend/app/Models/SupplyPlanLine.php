<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `supply_plan_lines`. Línea de un plan: proveedor, cantidad, fecha sugerida de emisión, recepción prevista, costo y restricciones activas.
 * Etapa 2 · P4 (docs/architecture/v2/P4-decisiones.md).
 */
class SupplyPlanLine extends Model
{
    use BelongsToTenant;

    protected $table = 'supply_plan_lines';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'offer_quantity' => 'float', 'unit_cost' => 'float', 'line_cost' => 'float', 'order_date' => 'date:Y-m-d', 'expected_receipt_date' => 'date:Y-m-d', 'actionable_now' => 'boolean', 'binding_constraints' => 'array', 'assumptions' => 'array'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
