<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla `reconciliation_reports` (ADR-V25-24): conteos y totales por capa fuente → Bronze → Silver → Gold, discrepancias y
 * estado (`balanced`, `explained`, `unexplained`). `scope`: `import` y `sync` (por carga, los escribe W2-A) y `window`
 * (por ventana de fechas, los escribe App\Services\DataCore\Reconciliation\WindowReconciler). Solo inserción.
 */
class ReconciliationReport extends Model
{
    use BelongsToTenant;

    public const SCOPES = ['import', 'sync', 'window'];

    public const STATUSES = ['balanced', 'explained', 'unexplained'];

    public const UPDATED_AT = null;

    protected $table = 'reconciliation_reports';

    /** tenant_id se asigna desde TenantContext (BelongsToTenant), nunca por asignación masiva. */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'layers' => 'array',
            'discrepancies' => 'array',
            'window_from' => 'date:Y-m-d',
            'window_to' => 'date:Y-m-d',
            'created_at' => 'datetime',
        ];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        $layers = (array) ($this->layers ?? []);
        $unavailable = [];
        $compared = [];
        foreach (['source', 'bronze', 'silver', 'gold'] as $layer) {
            $info = $layers[$layer] ?? null;
            if (! is_array($info) || ($info['available'] ?? true) === false) {
                $unavailable[$layer] = is_array($info) ? ($info['reason'] ?? 'No disponible.') : 'No disponible.';

                continue;
            }
            // Disponible pero no comparable (p. ej. cargas solapadas o cortadas por la ventana): también se informa con su razón.
            if (($info['comparable'] ?? true) === false || ($info['compared'] ?? true) === false) {
                $unavailable[$layer] = $info['reason'] ?? 'Disponible pero no comparada.';

                continue;
            }
            $compared[] = $layer;
        }

        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'entity' => $this->entity,
            'data_source_id' => $this->data_source_id,
            'bronze_object_id' => $this->bronze_object_id,
            'import_job_id' => $this->import_job_id,
            'source_sync_id' => $this->source_sync_id,
            'window_from' => $this->window_from?->toDateString(),
            'window_to' => $this->window_to?->toDateString(),
            'status' => $this->status,
            'layers' => $layers === [] ? (object) [] : $layers,
            'discrepancies' => $this->discrepancies ?? [],
            'unavailable_layers' => $unavailable === [] ? (object) [] : $unavailable,
            'compared_layers' => $compared,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
