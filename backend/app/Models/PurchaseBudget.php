<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `purchase_budgets`. Presupuesto de compra por período; aplica a órdenes emitidas dentro del período.
 * Etapa 2 · P4 (docs/architecture/v2/P4-decisiones.md).
 */
class PurchaseBudget extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_budgets';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
