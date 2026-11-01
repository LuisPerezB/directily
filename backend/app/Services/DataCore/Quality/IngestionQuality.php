<?php

declare(strict_types=1);

namespace App\Services\DataCore\Quality;

use App\Models\BronzeObject;
use App\Models\ImportJob;
use App\Models\SourceMapping;
use App\Models\SourceSync;
use App\Services\Imports\Processors\ImportRunContext;
use App\Support\Canonical\CanonicalContracts;
use Illuminate\Support\Facades\DB;

/**
 * Persistencia de la calidad de ingestión (ADR-V25-05 y ADR-V25-24; W2-A): amplía la fila de `data_quality_reports` de cada importación,
 * sincronización y lote con `dataset`, `issue_counts`, `rules_version` y `metrics`, CONSERVANDO `summary` e `issues` tal cual los
 * consumen `ImportJobResource`, el tablero, la exportación LOPDP y el criterio 4 del E2E; guarda cada hallazgo en `data_quality_issues`;
 * y crea la reconciliación por carga (`reconciliation_reports`). Todo por lotes (nunca una consulta por documento).
 */
final class IngestionQuality
{
    /** Contrato canónico con que se escribe cada entidad de importación (las demás no tienen contrato Silver propio todavía). */
    private const CONTRACT_BY_ENTITY = ['sales' => 'sales', 'inventory' => 'inventory_snapshot', 'products' => 'product', 'commitments' => 'commercial_commitment'];

    /**
     * Informe de una importación (validación o confirmación): reemplaza el anterior de la misma importación (`summary` e `issues`
     * idénticos a los de siempre) y deja sus hallazgos en `data_quality_issues`.
     *
     * @param  array{score: float, issues: list<array<string, mixed>>, summary: array<string, mixed>}  $report  lo que arma QualityReportBuilder
     * @return int id del informe
     */
    public function persistImportReport(ImportJob $job, array $report, ImportRunContext $ctx): int
    {
        $findings = $ctx->findings;
        // Issues de RESUMEN (sin fila: días sin ventas, documentos descartados por versión anterior…): hallazgos agregados.
        $rowCodes = array_column($ctx->issues->summary(), 'code');
        foreach ($report['issues'] as $item) {
            if (! in_array($item['code'], $rowCodes, true)) {
                $findings->aggregate((string) $item['code'], (string) $item['severity'], (int) ($item['count'] ?? 1), (string) ($item['message'] ?? $item['code']));
            }
        }
        $schema = $ctx->bronze !== null ? (array) ($ctx->bronze->metadata ?? []) : [];
        // En una sincronización, la deriva de ESTA extracción (si el original ya existía con el mismo contenido, su objeto conserva la de la primera vez).
        $drift = $ctx->sync !== null && array_key_exists('schema', (array) $ctx->sync->stats) ? ((array) $ctx->sync->stats)['schema']['drift'] ?? null : ($schema['drift'] ?? null);
        if (is_array($drift)) {
            $findings->aggregate('schema_drift', 'warning', 1, self::driftMessage($drift), null);
        }

        $maps = $ctx->valueMaps;
        $transformations = ['value_maps' => $maps->hits(), 'auto_fix' => $findings->autoFixTotals()];
        $mapsForRules = $maps->toArray();
        if ($ctx->sync !== null) {
            $syncMaps = (array) ((($ctx->sync->mapping?->options) ?? [])['value_maps'] ?? []);
            $mapsForRules = ValueMaps::merge($syncMaps, $mapsForRules);
            // En una sincronización los mapeos de valores se aplican al preparar el CSV (conector): sus aplicaciones vienen de la sincronización.
            $transformations['value_maps'] = (array) (($ctx->sync->stats ?? [])['transformations']['value_maps'] ?? []);
        }
        $metrics = $report['summary'] + [
            'transformations' => $transformations,
            'schema' => $ctx->bronze === null ? null : ['fingerprint' => $ctx->bronze->schema_fingerprint, 'version' => $ctx->bronze->schema_version, 'drift' => $drift],
            'lineage' => ['bronze_object_id' => $ctx->bronze?->id, 'schema_version' => $this->contractVersion($job->entity_type)],
        ];

        // Un REPROCESAMIENTO desde Bronze (predictily:bronze-reprocess) vuelve a leer filas que ya contó la carga original: su informe se marca
        // (`summary` y `metrics` llevan `reprocess_of_bronze_object_id`) y su `dataset` es «<entidad>_reprocess», así que el % de filas inválidas de
        // los gates (que suma por dataset) no cuenta dos veces las mismas filas.
        $reprocessOf = ((array) $job->options)['reprocess_of_bronze_object_id'] ?? null;
        if ($reprocessOf !== null) {
            $report['summary']['reprocess_of_bronze_object_id'] = (int) $reprocessOf;
            $metrics['reprocess_of_bronze_object_id'] = (int) $reprocessOf;
        }
        DB::table('data_quality_reports')->where('tenant_id', $job->tenant_id)->where('import_job_id', $job->id)->delete();
        $id = (int) DB::table('data_quality_reports')->insertGetId([
            'tenant_id' => $job->tenant_id,
            'import_job_id' => $job->id,
            'scope' => 'import',
            'score' => $report['score'],
            'summary' => json_encode($report['summary']),
            'issues' => json_encode($report['issues']),
            'created_at' => now(),
            'dataset' => $reprocessOf !== null ? $job->entity_type.'_reprocess' : $job->entity_type,
            'source_sync_id' => $ctx->sync?->id,
            'bronze_object_id' => $ctx->bronze?->id,
            'metrics' => json_encode($metrics),
            'issue_counts' => json_encode($findings->issueCounts()),
            'rules_version' => DataQualityCatalog::rulesVersion(['dataset' => $job->entity_type, 'value_maps' => (new ValueMaps($mapsForRules))->digest()]),
        ]);
        $this->insertIssues($job->tenant_id, $id, $findings->rows());

        return $id;
    }

    /**
     * Informe de un lote POST /sales/batch (alcance `sales_batch`, sin importación): mismos campos ampliados.
     *
     * @param  array<string, mixed>  $summary  conteos del lote (creados, actualizados, rechazados…)
     * @return int id del informe
     */
    public function persistBatchReport(int $tenantId, ?int $bronzeObjectId, ?BronzeObject $bronze, array $summary, FindingCollector $findings, string $rulesExtra = ''): int
    {
        $schema = $bronze !== null ? (array) ($bronze->metadata ?? []) : [];
        if (is_array($schema['drift'] ?? null)) {
            $findings->aggregate('schema_drift', 'warning', 1, self::driftMessage($schema['drift']), null);
        }
        $issues = [];
        foreach ($findings->classCounts() as $class => $count) {
            if ($count > 0) {
                $issues[] = ['class' => $class, 'count' => $count];
            }
        }
        $id = (int) DB::table('data_quality_reports')->insertGetId([
            'tenant_id' => $tenantId,
            'import_job_id' => null,
            'scope' => 'sales_batch',
            'score' => null,
            'summary' => json_encode($summary),
            'issues' => json_encode($issues),
            'created_at' => now(),
            'dataset' => 'sales',
            'bronze_object_id' => $bronzeObjectId,
            'metrics' => json_encode($summary + [
                'transformations' => ['value_maps' => [], 'auto_fix' => $findings->autoFixTotals()],
                'schema' => $bronze === null ? null : ['fingerprint' => $bronze->schema_fingerprint, 'version' => $bronze->schema_version, 'drift' => $schema['drift'] ?? null],
                'lineage' => ['bronze_object_id' => $bronzeObjectId, 'schema_version' => CanonicalContracts::version('sales')],
            ]),
            'issue_counts' => json_encode($findings->issueCounts()),
            'rules_version' => DataQualityCatalog::rulesVersion(['dataset' => 'sales', 'path' => 'sales_batch', 'extra' => $rulesExtra]),
        ]);
        $this->insertIssues($tenantId, $id, $findings->rows());

        return $id;
    }

    /**
     * Informe de una carga detenida por un problema estructural (BLOCKER): la carga NO continúa.
     *
     * @return int id del informe
     */
    public function recordBlocker(int $tenantId, string $entity, string $code, string $message, ?ImportJob $job = null, ?SourceSync $sync = null, ?int $bronzeObjectId = null): int
    {
        $findings = new FindingCollector($entity, 10);
        $findings->aggregate($code, 'error', 1, $message);
        if ($job !== null) {
            DB::table('data_quality_reports')->where('tenant_id', $tenantId)->where('import_job_id', $job->id)->delete();
        }
        $id = (int) DB::table('data_quality_reports')->insertGetId([
            'tenant_id' => $tenantId,
            'import_job_id' => $job?->id,
            'scope' => $job !== null ? 'import' : 'sync',
            'score' => null,
            'summary' => json_encode(['blocked' => true, 'code' => $code]),
            'issues' => json_encode([['code' => $code, 'severity' => 'error', 'count' => 1, 'message' => $message]]),
            'created_at' => now(),
            'dataset' => $entity,
            'source_sync_id' => $sync?->id,
            'bronze_object_id' => $bronzeObjectId,
            'metrics' => json_encode(['blocked' => true]),
            'issue_counts' => json_encode($findings->issueCounts()),
            'rules_version' => DataQualityCatalog::rulesVersion(['dataset' => $entity]),
        ]);
        $this->insertIssues($tenantId, $id, $findings->rows());

        return $id;
    }

    /** ¿El último informe de la importación tiene hallazgos BLOCKER? (bloquean siempre, con o sin `skip_invalid_rows`). */
    public function hasBlockers(ImportJob $job): bool
    {
        $counts = DB::table('data_quality_reports')->where('tenant_id', $job->tenant_id)->where('import_job_id', $job->id)->latest('id')->value('issue_counts');
        if ($counts === null) {
            return false;
        }

        return (int) ((json_decode((string) $counts, true) ?: [])[DataQualityCatalog::BLOCKER] ?? 0) > 0;
    }

    /**
     * Reconciliación de la carga (sin la tabla por documento): ventas con cuentas por categoría; las demás entidades, por filas.
     *
     * @param  array<string, mixed>  $result  resultado del procesador
     * @param  array<string, mixed>  $stats
     */
    public function persistReconciliation(ImportJob $job, ?SourceSync $sync, ?BronzeObject $bronze, array $result, array $stats): int
    {
        $rowsRead = (int) ($result['rows_read'] ?? $stats['rows_total'] ?? 0);
        $bronzeRows = $bronze !== null ? ($bronze->row_count ?? ($bronze->metadata['rows_read'] ?? null)) : null;
        $tally = $result['tally'] ?? null;
        if ($tally instanceof SalesLoadTally) {
            $built = $tally->build($bronze, $bronzeRows !== null ? (int) $bronzeRows : null);
        } else {
            $built = $this->generic($bronze, $bronzeRows !== null ? (int) $bronzeRows : null, $rowsRead, $stats);
        }
        // Una sincronización concilia además lo que EXTRAJO del origen contra lo que leyó el importador (preparación 1:1).
        if ($sync !== null && $sync->rows_extracted !== null && (int) $sync->rows_extracted !== (int) ($built['layers']['source']['rows'] ?? $rowsRead)) {
            $built['discrepancies'][] = [
                'from' => 'source', 'to' => 'staging', 'metric' => 'rows', 'expected' => (int) $sync->rows_extracted, 'observed' => (int) ($built['layers']['source']['rows'] ?? $rowsRead),
                'difference' => (int) $sync->rows_extracted - (int) ($built['layers']['source']['rows'] ?? $rowsRead), 'categories' => [], 'explained' => false,
                'explanation' => 'La preparación entregó al importador un número de filas distinto del que extrajo la consulta.',
            ];
            $built['status'] = 'unexplained';
        }
        if ($sync !== null) {
            $built['layers']['source']['rows_extracted'] = $sync->rows_extracted;
        }
        $scope = $sync !== null ? 'sync' : 'import';
        $reprocess = ((array) $job->options)['reprocess_of_bronze_object_id'] ?? null;
        if ($reprocess !== null) {
            $built['layers']['reprocess_of_bronze_object_id'] = (int) $reprocess;
        }
        $reprocess = $reprocess !== null;
        DB::table('reconciliation_reports')->where('tenant_id', $job->tenant_id)->where('import_job_id', $job->id)->where('scope', $scope)->delete();

        return (int) DB::table('reconciliation_reports')->insertGetId([
            'tenant_id' => $job->tenant_id,
            'scope' => $scope,
            'entity' => $job->entity_type,
            'data_source_id' => $job->data_source_id,
            'bronze_object_id' => $bronze?->id,
            'import_job_id' => $job->id,
            'source_sync_id' => $sync?->id,
            // Rango de fechas de negocio de la carga: la reconciliación por ventana solo suma cargas que caen por completo dentro de ella. Un
            // reprocesamiento no es una carga nueva (volvería a contar las mismas filas y marcaría la ventana como solapada): sin rango.
            'window_from' => $reprocess ? null : ($stats['date_from'] ?? null),
            'window_to' => $reprocess ? null : ($stats['date_to'] ?? null),
            'layers' => json_encode($built['layers']),
            'discrepancies' => json_encode($built['discrepancies']),
            'status' => $built['status'],
            'created_at' => now(),
        ]);
    }

    /** Sincronización que no trajo filas: reconciliación en cero (no hay nada que perder entre capas). */
    public function persistEmptySyncReconciliation(SourceSync $sync): int
    {
        return (int) DB::table('reconciliation_reports')->insertGetId([
            'tenant_id' => $sync->tenant_id,
            'scope' => 'sync',
            'entity' => $sync->entity,
            'data_source_id' => $sync->data_source_id,
            'bronze_object_id' => null,
            'import_job_id' => null,
            'source_sync_id' => $sync->id,
            'layers' => json_encode(['source' => ['rows' => 0, 'rows_extracted' => 0], 'bronze' => null, 'silver' => ['rows' => 0], 'gold' => null]),
            'discrepancies' => json_encode([]),
            'status' => 'balanced',
            'created_at' => now(),
        ]);
    }

    public function contractVersion(string $entity): ?string
    {
        return isset(self::CONTRACT_BY_ENTITY[$entity]) ? CanonicalContracts::version(self::CONTRACT_BY_ENTITY[$entity]) : null;
    }

    /** @param array<string, mixed> $drift */
    public static function driftMessage(array $drift): string
    {
        $part = fn (string $label, array $names): string => $names === [] ? '' : " {$label}: ".implode(', ', array_slice($names, 0, 20)).(count($names) > 20 ? '…' : '').'.';

        return 'El esquema de la fuente cambió respecto de la carga anterior (versión '.$drift['previous_version'].' → '.($drift['previous_version'] + 1).').'
            .$part('Columnas agregadas', (array) ($drift['added'] ?? [])).$part('Columnas quitadas', (array) ($drift['removed'] ?? [])).$part('Tipo cambiado', (array) ($drift['retyped'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array{layers: array<string, mixed>, discrepancies: list<array<string, mixed>>, status: string}
     */
    private function generic(?BronzeObject $bronze, ?int $bronzeRows, int $rowsRead, array $stats): array
    {
        $rejected = (int) ($stats['rows_error'] ?? 0);
        $written = max(0, $rowsRead - $rejected);
        $discrepancies = [];
        if ($rejected > 0) {
            $discrepancies[] = [
                'from' => 'source', 'to' => 'silver', 'metric' => 'rows', 'expected' => $rowsRead, 'observed' => $written, 'difference' => $rejected,
                'categories' => ['rejected_rows' => $rejected], 'unexplained' => 0, 'explained' => true,
                'explanation' => "filas rechazadas por errores y no retenidas ({$rejected}).",
            ];
        }
        $status = $discrepancies === [] ? 'balanced' : 'explained';
        if ($bronzeRows !== null && $bronzeRows !== $rowsRead) {
            $status = 'unexplained';
            $discrepancies[] = ['from' => 'source', 'to' => 'bronze', 'metric' => 'rows', 'expected' => $rowsRead, 'observed' => $bronzeRows, 'difference' => $rowsRead - $bronzeRows,
                'categories' => [], 'unexplained' => $rowsRead - $bronzeRows, 'explained' => false, 'explanation' => 'El objeto Bronze no contiene las mismas filas que se leyeron del origen.'];
        }

        return [
            'layers' => [
                'source' => ['rows' => $rowsRead],
                'bronze' => $bronze === null ? null : ['object_id' => $bronze->id, 'kind' => $bronze->kind, 'sha256' => $bronze->sha256, 'size_bytes' => (int) $bronze->size_bytes, 'rows' => $bronzeRows, 'schema_version' => $bronze->schema_version],
                'silver' => ['rows' => $written],
                'gold' => null,
            ],
            'discrepancies' => $discrepancies,
            'status' => $status,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertIssues(int $tenantId, int $reportId, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $now = now();
        $prepared = array_map(fn (array $row) => $row + ['tenant_id' => $tenantId, 'report_id' => $reportId, 'status' => 'open', 'created_at' => $now], $rows);
        foreach (array_chunk($prepared, 1000) as $chunk) {
            DB::table('data_quality_issues')->insert($chunk);
        }
    }

    /** Mapeo SQL de la sincronización (para sus mapeos de valores). */
    public function mappingOf(SourceSync $sync): ?SourceMapping
    {
        return SourceMapping::query()->find($sync->source_mapping_id);
    }
}
