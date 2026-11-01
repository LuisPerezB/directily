<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabla `data_quality_reports`. Ver docs/03-modelo-datos.md.
 * Generado a partir del esquema; responsable: backend-core.
 */
class DataQualityReport extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'summary' => 'array',
            'issues' => 'array',
            // V2.5 (ADR-V25-24): métricas del dataset, hallazgos por clase y resultado de los gates por módulo.
            'metrics' => 'array',
            'issue_counts' => 'array',
            'gate_results' => 'array',
            'as_of' => 'date:Y-m-d',
            'created_at' => 'datetime',
        ];
    }

    public function forecastRun(): BelongsTo
    {
        return $this->belongsTo(ForecastRun::class, 'forecast_run_id');
    }

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class, 'import_job_id');
    }

    public function sourceSync(): BelongsTo
    {
        return $this->belongsTo(SourceSync::class, 'source_sync_id');
    }

    public function bronzeObject(): BelongsTo
    {
        return $this->belongsTo(BronzeObject::class, 'bronze_object_id');
    }

    /** Hallazgos persistidos de este informe (`data_quality_issues`). */
    public function findings(): HasMany
    {
        return $this->hasMany(DataQualityIssue::class, 'report_id');
    }
}
