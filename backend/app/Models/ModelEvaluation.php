<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `model_evaluations`. Ver docs/03-modelo-datos.md.
 * Generado a partir del esquema; responsable: backend-analytics.
 */
class ModelEvaluation extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'is_baseline' => 'boolean',
            'is_selected' => 'boolean',
            'wape' => 'float',
            'mae' => 'float',
            'rmse' => 'float',
            'bias' => 'float',
            'coverage_80' => 'float',
            'coverage_95' => 'float',
            'cutoffs' => 'array',
            'extra' => 'array',
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
