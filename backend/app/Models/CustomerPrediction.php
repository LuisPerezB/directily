<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Predicción de comportamiento de compra por comprador × producto × horizonte (Customer Core, escrita por el motor).
 * `expected_amount` es un SUPUESTO (esperanza × último precio neto), nunca una venta confirmada.
 */
class CustomerPrediction extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'as_of' => 'date:Y-m-d',
            'purchase_probability' => 'float',
            'probability_raw' => 'float',
            'probability_coherence_adjusted' => 'boolean',
            'quantity_if_purchase' => 'float',
            'quantity_lo' => 'float',
            'quantity_hi' => 'float',
            'expected_quantity' => 'float',
            'committed_quantity' => 'float',
            'incremental_quantity' => 'float',
            'total_view_quantity' => 'float',
            'assumed_unit_price' => 'float',
            'expected_amount' => 'float',
            'median_interval_days' => 'float',
            // V2.5 · W3-C (ADR-V25-11): nº de pedidos esperado (modelo de conteo), cantidad por pedido, unidades esperadas y la ventana de recompra
            // (cuantiles del tiempo hasta la siguiente compra, desde as_of). Nulos cuando el motor no los estima: nunca un cero inventado.
            'expected_orders' => 'float',
            'quantity_per_order' => 'float',
            'expected_units' => 'float',
            'window_p10_days' => 'float',
            'window_p50_days' => 'float',
            'window_p90_days' => 'float',
            'quality_flags' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function coreRun(): BelongsTo
    {
        return $this->belongsTo(CoreRun::class, 'core_run_id');
    }
}
