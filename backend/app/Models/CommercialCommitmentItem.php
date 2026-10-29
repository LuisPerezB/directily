<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea de un compromiso. Pendiente = max(0, quantity − quantity_fulfilled); un compromiso cancelado no tiene pendiente. */
class CommercialCommitmentItem extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'quantity_fulfilled' => 'float',
            'unit_price' => 'float',
            'source_quantity' => 'float',
            'conversion_factor' => 'float',
            'delivery_date' => 'date:Y-m-d',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
