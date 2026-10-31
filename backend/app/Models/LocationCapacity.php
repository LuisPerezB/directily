<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `location_capacities`. Capacidad de almacenamiento de una sucursal en una unidad física explícita.
 * Etapa 2 · P4 (docs/architecture/v2/P4-decisiones.md).
 */
class LocationCapacity extends Model
{
    use BelongsToTenant;

    protected $table = 'location_capacities';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['capacity' => 'float', 'valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
