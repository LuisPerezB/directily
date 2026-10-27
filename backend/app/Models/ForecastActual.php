<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `forecast_actuals`. Ver docs/03-modelo-datos.md.
 * Generado a partir del esquema; responsable: backend-analytics.
 */
class ForecastActual extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'target_date' => 'date:Y-m-d',
            'yhat' => 'float',
            'lo_80' => 'float',
            'hi_80' => 'float',
            'actual' => 'float',
            'abs_error' => 'float',
            'is_censored' => 'boolean',
            'evaluated_at' => 'datetime',
        ];
    }

    public function forecastRun(): BelongsTo
    {
        return $this->belongsTo(ForecastRun::class, 'forecast_run_id');
    }
}
