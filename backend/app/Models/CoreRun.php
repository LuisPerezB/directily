<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ejecución de un core de la etapa 2 (customer | supply | cost | scenario). La demanda usa
 * ForecastRun. Campos de la envoltura común: docs/architecture/PREDICTILY_V2_GAP_ANALYSIS.md ADR-V2-03.
 */
class CoreRun extends Model
{
    use BelongsToTenant;

    public const CORES = ['customer', 'supply', 'cost', 'scenario'];

    public const QUALITY = ['ok', 'degraded', 'insufficient_data', 'not_configured', 'insufficient_evidence'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'as_of' => 'date:Y-m-d',
            'horizon_start' => 'date:Y-m-d',
            'horizon_end' => 'date:Y-m-d',
            'horizons' => 'array',
            'target_definition' => 'array',
            'options' => 'array',
            'effective_config' => 'array',
            'model_versions' => 'array',
            'evaluation_manifest' => 'array',
            'assumptions' => 'array',
            'limitations' => 'array',
            'summary' => 'array',
            'is_current' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function scopeOfCore(Builder $query, string $core): Builder
    {
        return $query->where('core', $core);
    }

    public function dataSnapshot(): BelongsTo
    {
        return $this->belongsTo(DataSnapshot::class, 'data_snapshot_id');
    }

    public function trainingJobs(): HasMany
    {
        return $this->hasMany(TrainingJob::class, 'core_run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
