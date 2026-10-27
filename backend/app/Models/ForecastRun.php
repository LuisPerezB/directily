<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tabla `forecast_runs`. Ver docs/03-modelo-datos.md.
 * Generado a partir del esquema; responsable: backend-analytics.
 */
class ForecastRun extends Model
{
    use BelongsToTenant;

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date:Y-m-d',
            'data_from' => 'date:Y-m-d',
            'data_to' => 'date:Y-m-d',
            'options' => 'array',
            'selection' => 'array',
            'summary' => 'array',
            'is_current' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_PRODUCTION = 'production';

    public const TYPE_HISTORICAL = 'historical';

    public function trainingJob(): HasOne
    {
        return $this->hasOne(TrainingJob::class, 'forecast_run_id')
            ->where('job_type', TrainingJob::TYPE_FORECAST_PIPELINE)
            ->latestOfMany();
    }

    public function trainingJobs(): HasMany
    {
        return $this->hasMany(TrainingJob::class, 'forecast_run_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(ForecastValue::class, 'forecast_run_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(ModelEvaluation::class, 'forecast_run_id');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class, 'forecast_run_id');
    }

    /** @param Builder<ForecastRun> $query */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', self::STATUS_COMPLETED);
    }

    /** @param Builder<ForecastRun> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING]);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }
}
