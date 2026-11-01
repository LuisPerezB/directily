<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `cost_forecasts`. Pronóstico de costo unitario por producto y proveedor, con referencia simple y métricas de holdout.
 * Etapa 2 · P4 (docs/architecture/v2/P4-decisiones.md).
 */
class CostForecast extends Model
{
    use BelongsToTenant;

    protected $table = 'cost_forecasts';

    public const UPDATED_AT = null;

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['last_observed_cost' => 'float', 'last_observed_on' => 'date:Y-m-d', 'points' => 'array', 'metrics' => 'array', 'availability' => 'array', 'limitations' => 'array'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
