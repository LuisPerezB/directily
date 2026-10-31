<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `sales_document_revisions` (ADR-V25-23): versión REEMPLAZADA de la cabecera de un documento de venta, con su
 * ventana de conocimiento `[known_from, known_until)`. La versión vigente vive en `sales`. Solo inserción: la escribe
 * `SalesDocumentWriter` en la misma transacción que el reemplazo; sus líneas están en `sale_item_revisions` con el mismo `revision`.
 *
 * `known_from` NULL = inicio del día local de `sale_date` (versión cargada como histórico, `ingestion_mode = backfill`).
 */
class SalesDocumentRevision extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const CHANGE_KINDS = ['status_changed', 'lines_changed', 'header_changed', 'mixed'];

    protected $table = 'sales_document_revisions';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'sale_date' => 'date:Y-m-d',
            'subtotal' => 'float',
            'discount_total' => 'float',
            'tax_total' => 'float',
            'total' => 'float',
            'source_recorded_at' => 'datetime',
            'known_from' => 'datetime',
            'known_until' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    /** Líneas de ESTA versión (consulta, no relación: la clave es `(sale_id, revision)`). */
    public function lines(): Builder
    {
        return SaleItemRevision::query()->where('sale_id', $this->sale_id)->where('revision', $this->revision)->orderBy('line_number');
    }
}
