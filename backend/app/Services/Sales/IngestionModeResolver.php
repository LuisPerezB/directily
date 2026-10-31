<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\SourceSync;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Resuelve `sales.ingestion_mode` (ADR-V25-23) para cada camino de escritura de ventas. Todo camino fija el modo de forma
 * EXPLÍCITA; este servicio solo decide cuál cuando quien escribe no lo declaró:
 *
 *  - Importación de archivo: `backfill` si la empresa no tiene ninguna fila en `sales` (primera carga histórica); si no, `incremental`.
 *  - POST /sales/batch: `incremental`.
 *  - Sincronización SQL: `backfill` en la primera sincronización de la fuente que trajo datos; después, `incremental`.
 *
 * Una declaración válida (`backfill` | `incremental`) siempre manda sobre la regla. La `basis` queda registrada junto al
 * modo (estadísticas del import, de la sincronización y respuesta del lote) para poder auditar cómo se decidió.
 */
final class IngestionModeResolver
{
    public const BACKFILL = 'backfill';

    public const INCREMENTAL = 'incremental';

    /** @var list<string> */
    public const MODES = [self::BACKFILL, self::INCREMENTAL];

    public const BASIS_DECLARED = 'declared';

    public const BASIS_FIRST_LOAD = 'first_load';

    public const BASIS_SUBSEQUENT_LOAD = 'subsequent_load';

    public const BASIS_FIRST_SYNC = 'first_sync';

    public const BASIS_SUBSEQUENT_SYNC = 'subsequent_sync';

    public const BASIS_API_DEFAULT = 'api_default';

    /**
     * Normaliza una declaración: vacío => null (automático); un valor fuera del catálogo es un error de programación o de
     * validación que no debe degradarse en silencio.
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(mixed $mode): ?string
    {
        if ($mode === null || $mode === '') {
            return null;
        }
        if (! is_string($mode) || ! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Modo de ingestión no válido: debe ser backfill o incremental.');
        }

        return $mode;
    }

    public function tenantHasSales(Tenant $tenant): bool
    {
        return DB::table('sales')->where('tenant_id', $tenant->id)->exists();
    }

    /** Importación de archivo (validación y confirmación). */
    public function forImport(Tenant $tenant, mixed $declared): IngestionResolution
    {
        $declared = self::normalize($declared);
        if ($declared !== null) {
            return new IngestionResolution($declared, self::BASIS_DECLARED);
        }

        return $this->tenantHasSales($tenant)
            ? new IngestionResolution(self::INCREMENTAL, self::BASIS_SUBSEQUENT_LOAD)
            : new IngestionResolution(self::BACKFILL, self::BASIS_FIRST_LOAD);
    }

    /** POST /sales/batch: por defecto incremental (conocido desde que Predictily lo recibe). */
    public function forBatch(mixed $declared): IngestionResolution
    {
        $declared = self::normalize($declared);

        return $declared !== null
            ? new IngestionResolution($declared, self::BASIS_DECLARED)
            : new IngestionResolution(self::INCREMENTAL, self::BASIS_API_DEFAULT);
    }

    /**
     * Sincronización SQL de una fuente. `$requested` es lo declarado en la solicitud; `$mapped` lo declarado en
     * `source_mappings.options.ingestion_mode`. Precedencia: solicitud > mapeo > regla.
     *
     * Regla: la sincronización es `backfill` si es la primera de la fuente y entidad que trajo datos (una sincronización
     * completada con cero filas no cuenta: no cargó el histórico); si no, `incremental`.
     */
    public function forSync(SourceSync $sync, mixed $requested, mixed $mapped): IngestionResolution
    {
        if (($declared = self::normalize($requested)) !== null || ($declared = self::normalize($mapped)) !== null) {
            return new IngestionResolution($declared, self::BASIS_DECLARED);
        }
        $loadedBefore = SourceSync::query()
            ->where('data_source_id', $sync->data_source_id)->where('entity', $sync->entity)
            ->where('status', 'completed')->where('rows_extracted', '>', 0)->where('id', '<', $sync->id)
            ->exists();

        return $loadedBefore
            ? new IngestionResolution(self::INCREMENTAL, self::BASIS_SUBSEQUENT_SYNC)
            : new IngestionResolution(self::BACKFILL, self::BASIS_FIRST_SYNC);
    }
}
