<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla `inventory_lots` (V2.5 · W5, §10): lotes con vencimiento por sucursal, producto y fecha de foto (FEFO y perecibilidad).
 */
class InventoryLot extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_lots';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'quantity' => 'float',
        ];
    }
}
