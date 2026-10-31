<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `sale_item_revisions` (ADR-V25-23): líneas de una versión REEMPLAZADA de un documento de venta. Se copian de
 * `sale_items` (con su `revision`) justo antes de reemplazarlas; la ventana de conocimiento está en la cabecera
 * (`sales_document_revisions`, mismo `sale_id` y `revision`). Solo inserción.
 */
class SaleItemRevision extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'sale_item_revisions';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date:Y-m-d',
            'quantity' => 'float',
            'unit_price' => 'float',
            'discount_amount' => 'float',
            'net_amount' => 'float',
            'tax_amount' => 'float',
            'unit_cost' => 'float',
            'is_return' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
