<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `supplier_offers`. Oferta de un proveedor por producto: costo, moneda, unidad, mínimo, múltiplo, plazo, capacidad, vigencia y gastos de adquisición explícitos.
 * Etapa 2 · P4 (docs/architecture/v2/P4-decisiones.md).
 */
class SupplierOffer extends Model
{
    use BelongsToTenant;

    protected $table = 'supplier_offers';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'float', 'min_order_qty' => 'float', 'order_multiple' => 'float', 'lead_time_std_days' => 'float',
            'capacity_per_period' => 'float', 'fixed_order_cost' => 'float', 'acquisition_cost_pct' => 'float',
            'acquisition_cost_per_unit' => 'float', 'valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d',
            'known_at' => 'datetime', 'is_active' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
