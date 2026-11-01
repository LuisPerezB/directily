<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `bronze_objects` (ADR-V25-04 y ADR-V25-24): objeto original autorizado e INMUTABLE (archivo subido,
 * extracción SQL, lote REST o Bridge) guardado antes de normalizar. El contenido nunca cambia: solo `status`,
 * `retention_until` y `metadata`. La clave la genera el servidor (BronzeStore); nunca proviene del cliente.
 */
class BronzeObject extends Model
{
    use BelongsToTenant;

    public const KINDS = ['file_upload', 'sql_extract', 'api_batch', 'bridge_batch'];

    public const STATUSES = ['stored', 'processed', 'quarantined', 'purged'];

    protected $table = 'bronze_objects';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'size_bytes' => 'integer',
            'row_count' => 'integer',
            'schema_version' => 'integer',
            'received_at' => 'datetime',
            'retention_until' => 'date:Y-m-d',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }
}
