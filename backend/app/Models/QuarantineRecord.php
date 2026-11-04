<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `quarantine_records` (ADR-V25-24): documento retenido FUERA de Silver porque tiene hallazgos REVIEW_REQUIRED o QUARANTINED y la
 * carga omitió filas inválidas (`skip_invalid_rows`). Su `payload` es el registro normalizado y minimizado (sin nombres de clientes; los
 * códigos de cliente tal como se guardan en `customers`). Revisable: se libera (revalida con los mapeos vigentes y escribe) o se descarta.
 */
class QuarantineRecord extends Model
{
    use BelongsToTenant;

    /** `superseded`: una carga posterior trajo el documento bien (o se liberó otro registro del mismo documento); ya no se puede liberar. */
    public const STATUSES = ['pending', 'released', 'discarded', 'superseded'];

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'issue_codes' => 'array',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
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
}
