<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reconciliación por producto y horizonte: demanda agregada vigente = compradores modelados + comprometido fuera del
 * modelo + residual. Las dos vistas (agregada y por comprador) nunca se suman.
 */
class CustomerReconciliation extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'aggregate_quantity' => 'float',
            'buyers_total_quantity' => 'float',
            'buyers_committed_quantity' => 'float',
            'buyers_incremental_quantity' => 'float',
            'buyers_expected_quantity' => 'float',
            'committed_outside_model' => 'float',
            'residual_quantity' => 'float',
            'excess_quantity' => 'float',
            'excess_ratio' => 'float',
            'residual_share' => 'float',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
