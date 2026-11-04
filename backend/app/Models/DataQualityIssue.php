<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `data_quality_issues` (ADR-V25-05 y ADR-V25-24): un hallazgo de calidad con clase, código estable, entidad, referencia del
 * registro SIN PII, fila de origen, campo, valor original ACOTADO (el registro íntegro queda en Bronze), propuesta, si se aplicó y motivo.
 */
class DataQualityIssue extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const STATUSES = ['open', 'accepted', 'rejected', 'reprocessed'];

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'source_row' => 'integer',
            'applied' => 'boolean',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(DataQualityReport::class, 'report_id');
    }
}
