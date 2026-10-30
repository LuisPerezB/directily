<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `model_registry`. Ver docs/03-modelo-datos.md.
 * Generado a partir del esquema; responsable: backend-analytics.
 */
class ModelRegistry extends Model
{
    use BelongsToTenant;

    protected $table = 'model_registry';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'hyperparameters' => 'array',
            'training_data_from' => 'date:Y-m-d',
            'training_data_to' => 'date:Y-m-d',
            'library_versions' => 'array',
            'artifact_compat' => 'array',
            'validated_at' => 'datetime',
            'trained_at' => 'datetime',
            'promoted_at' => 'datetime',
            'retired_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function forecastRun(): BelongsTo
    {
        return $this->belongsTo(ForecastRun::class, 'forecast_run_id');
    }

    public function trainingJob(): BelongsTo
    {
        return $this->belongsTo(TrainingJob::class, 'training_job_id');
    }
}
