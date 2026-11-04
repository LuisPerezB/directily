<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `shadow_forecast_values` (V2.5 · W3, §8 shadow mode): predicciones del challenger de cada alcance que el motor calcula pero NO sirve
 * (nunca entran a `forecast_values`). Se evalúan después del corte contra lo realizado (`model_evaluations.evaluation_type =
 * 'shadow_prospective'`). Solo las escribe el motor; la API las expone únicamente de forma agregada (cantidad y fechas por familia).
 */
class ShadowForecastValue extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'shadow_forecast_values';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'target_date' => 'date:Y-m-d',
            'horizon_step' => 'integer',
            'yhat' => 'float',
            'lo_80' => 'float',
            'hi_80' => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function forecastRun(): BelongsTo
    {
        return $this->belongsTo(ForecastRun::class, 'forecast_run_id');
    }

    public function modelRegistry(): BelongsTo
    {
        return $this->belongsTo(ModelRegistry::class, 'model_registry_id');
    }
}
