<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `training_jobs`. Ver docs/03-modelo-datos.md.
 * Generado a partir del esquema; responsable: backend-analytics.
 */
class TrainingJob extends Model
{
    use BelongsToTenant;

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'result' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }


    public const TYPE_FORECAST_PIPELINE = 'forecast_pipeline';
    public const TYPE_EVALUATE_ACTUALS = 'evaluate_actuals';
    public const TYPE_READINESS = 'readiness';
    public const TYPE_RETRAIN_CHECK = 'retrain_check';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }
}
