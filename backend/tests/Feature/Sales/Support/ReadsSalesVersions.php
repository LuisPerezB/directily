<?php

declare(strict_types=1);

namespace Tests\Feature\Sales\Support;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lectura de las versiones de documentos de venta (ADR-V25-23) para las pruebas: normaliza instantes a UTC y aplica la regla de
 * visibilidad as-of del contrato (known_from < medianoche local de C+1 y known_until nula o >= esa medianoche) sobre la vista
 * `sales_line_versions`, exactamente como la leerá el motor.
 */
trait ReadsSalesVersions
{
    /** Instante a «Y-m-d H:i:s» UTC (null se conserva): independiente de la zona de la sesión de PostgreSQL. */
    protected function utc(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->utc()->format('Y-m-d H:i:s');
    }

    /** Fila de `sales` por número de documento (sin scope de empresa). */
    protected function saleRow(Tenant $tenant, string $number): object
    {
        return DB::table('sales')->where('tenant_id', $tenant->id)->where('document_number', $number)->sole();
    }

    /** @return list<object> revisiones de cabecera archivadas de un documento, por número de versión */
    protected function headerRevisions(Tenant $tenant, string $number): array
    {
        return DB::table('sales_document_revisions')->where('tenant_id', $tenant->id)->where('sale_id', $this->saleRow($tenant, $number)->id)->orderBy('revision')->get()->all();
    }

    /**
     * Versiones de línea visibles al corte `$cutoffDate` (fecha local de la empresa), por documento: [número => [revisión => [línea => cantidad]]].
     *
     * @return array<string, array<int, array<int, float>>>
     */
    protected function visibleAt(Tenant $tenant, string $cutoffDate): array
    {
        $rows = DB::select(
            'SELECT s.document_number, v.revision, v.line_number, v.quantity'
            .' FROM sales_line_versions v JOIN tenants t ON t.id = v.tenant_id JOIN sales s ON s.tenant_id = v.tenant_id AND s.id = v.sale_id'
            .' WHERE v.tenant_id = ?'
            .'   AND v.known_from < ((?::date + 1)::timestamp AT TIME ZONE t.timezone)'
            .'   AND (v.known_until IS NULL OR v.known_until >= ((?::date + 1)::timestamp AT TIME ZONE t.timezone))'
            .' ORDER BY s.document_number, v.revision, v.line_number',
            [$tenant->id, $cutoffDate, $cutoffDate],
        );
        $visible = [];
        foreach ($rows as $row) {
            $visible[$row->document_number][(int) $row->revision][(int) $row->line_number] = (float) $row->quantity;
        }

        return $visible;
    }

    /** Todas las versiones de línea de un documento en la vista: [revisión => ['current' => bool, 'known_from' => utc, 'known_until' => utc|null, 'lines' => [línea => cantidad]]]. */
    protected function versionsView(Tenant $tenant, string $number): array
    {
        $saleId = $this->saleRow($tenant, $number)->id;
        $versions = [];
        foreach (DB::table('sales_line_versions')->where('tenant_id', $tenant->id)->where('sale_id', $saleId)->orderBy('revision')->orderBy('line_number')->get() as $row) {
            $versions[(int) $row->revision]['current'] = (bool) $row->is_current;
            $versions[(int) $row->revision]['known_from'] = $this->utc($row->known_from);
            $versions[(int) $row->revision]['known_until'] = $this->utc($row->known_until);
            $versions[(int) $row->revision]['status'] = $row->status;
            $versions[(int) $row->revision]['lines'][(int) $row->line_number] = (float) $row->quantity;
        }

        return $versions;
    }
}
