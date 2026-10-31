<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `production_resources`. Recurso de producción genérico con capacidad por período.
 * Etapa 2 · P4 (docs/architecture/v2/P4-decisiones.md).
 */
class ProductionResource extends Model
{
    use BelongsToTenant;

    protected $table = 'production_resources';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['capacity_per_period' => 'float', 'is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
