<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabla `data_coverage_gaps` (ADR-V25-23): huecos INTERNOS de una fuente (caída de integración, migración, origen
 * ausente). Los días cubiertos por un hueco son `missing_source`, nunca ceros observados (ADR-V25-03).
 *
 * `basis`: `declared` (usuario o conector) | `inferred` (regla documentada). Los huecos creados por la API son siempre `declared`.
 * El rango `[gap_from, gap_to]` incluye ambos extremos. Los huecos pueden solaparse: quien los consume toma la UNIÓN.
 */
class DataCoverageGap extends Model
{
    use BelongsToTenant;

    /** Entidades que admiten huecos (lista blanca del contrato de la migración 2026_03_01_000100). */
    public const ENTITIES = ['sales', 'inventory', 'purchases', 'costs', 'promotions', 'customers', 'commitments'];

    public const REASONS = ['integration_outage', 'source_missing', 'migration', 'unknown'];

    public const BASES = ['declared', 'inferred'];

    /** Ancho máximo de un hueco (días): acota lo que un consumidor debe expandir por día. */
    public const MAX_SPAN_DAYS = 3660;

    protected $table = 'data_coverage_gaps';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'gap_from' => 'date:Y-m-d',
            'gap_to' => 'date:Y-m-d',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'entity' => $this->entity,
            'data_source_id' => $this->data_source_id,
            'branch_id' => $this->branch_id,
            'gap_from' => $this->gap_from?->toDateString(),
            'gap_to' => $this->gap_to?->toDateString(),
            'days' => $this->gap_from !== null && $this->gap_to !== null ? (int) $this->gap_from->diffInDays($this->gap_to) + 1 : null,
            'reason' => $this->reason,
            'basis' => $this->basis,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
