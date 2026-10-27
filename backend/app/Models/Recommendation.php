<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `recommendations`. Ver docs/03-modelo-datos.md.
 * Generado a partir del esquema; responsable: backend-analytics.
 */
class Recommendation extends Model
{
    use BelongsToTenant;

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'inventory_snapshot_date' => 'date:Y-m-d',
            'on_hand' => 'float',
            'reserved' => 'float',
            'in_transit' => 'float',
            'available' => 'float',
            'service_level' => 'float',
            'demand_lead_time' => 'float',
            'demand_lead_time_std' => 'float',
            'safety_stock' => 'float',
            'reorder_point' => 'float',
            'suggested_qty' => 'float',
            'unit_cost' => 'float',
            'estimated_investment' => 'float',
            'days_of_cover' => 'float',
            'stockout_probability' => 'float',
            'stockout_date_estimate' => 'date:Y-m-d',
            'assumptions' => 'array',
            'acknowledged_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function forecastRun(): BelongsTo
    {
        return $this->belongsTo(ForecastRun::class, 'forecast_run_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
