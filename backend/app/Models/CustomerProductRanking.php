<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Top-k de productos probables por comprador y horizonte (Customer Core). */
class CustomerProductRanking extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'purchase_probability' => 'float',
            'expected_quantity' => 'float',
            'expected_amount' => 'float',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
