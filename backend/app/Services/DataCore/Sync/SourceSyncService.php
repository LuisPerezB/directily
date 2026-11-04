<?php

declare(strict_types=1);

namespace App\Services\DataCore\Sync;

use App\Exceptions\DomainStateException;
use App\Exceptions\PlanLimitExceededException;
use App\Models\BronzeObject;
use App\Models\DataSource;
use App\Models\ImportJob;
use App\Models\SourceMapping;
use App\Models\SourceSync;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Connectors\Sql\SqlQueryRejected;
use App\Services\Connectors\Sql\SqlSourceConnection;
use App\Services\Connectors\Sql\SqlSourceException;
use App\Services\DataCore\Coverage\CoverageService;
use App\Services\DataCore\Quality\BronzeCaptureException;
use App\Services\DataCore\Quality\IngestionBronze;
use App\Services\DataCore\Quality\IngestionQuality;
use App\Services\Imports\ImportEntity;
use App\Services\Imports\ImportService;
use App\Services\Imports\ImportStatus;
use App\Services\Sales\IngestionModeResolver;
use App\Services\Sales\IngestionResolution;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sincronización de una fuente SQL (E2-25): extracción de la consulta autorizada → filas canónicas (CSV de
 * preparación) → MISMO pipeline de importación (validación, idempotencia por clave natural + source_hash,
 * procedencia `data_source_id`) → cobertura. Nunca inserta datos de negocio directamente.
 *
 * W2-A (ADR-V25-24): la extracción CRUDA se guarda en Bronze (NDJSON comprimido, `source_syncs.bronze_object_id`) ANTES de normalizar y
 * de escribir nada en Silver; la huella de esquema detecta la deriva; la importación que corre dentro de la sincronización queda enlazada
 * a ella (`source_syncs.import_job_id`) antes de ejecutarse, de modo que su informe de calidad, su linaje y su reconciliación
 * (alcance `sync`) cuentan el recorrido completo origen → Bronze → Silver.
 */
final class SourceSyncService
{
    public function __construct(
        private readonly SourceMappingService $mappings,
        private readonly CanonicalStager $stager,
        private readonly ImportService $imports,
        private readonly CanonicalImportRunner $runner,
        private readonly CoverageService $coverage,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly IngestionModeResolver $ingestionModes,
        private readonly IngestionBronze $bronze,
        private readonly IngestionQuality $dq,
    ) {}

    /**
     * Crea la sincronización en cola. `$ingestionMode` (backfill | incremental) declara el modo de ingestión de las ventas
     * (ADR-V25-23); sin declararlo manda el del mapeo (`options.ingestion_mode`) y, si tampoco, la regla de primera sincronización.
     *
     * @throws DomainStateException
     */
    public function request(DataSource $source, string $entity, string $mode, ?User $user, string $trigger = 'user', ?string $ingestionMode = null): SourceSync
    {
        $requestedMode = IngestionModeResolver::normalize($ingestionMode);
        $this->mappings->connectorFor($source);
        if ($source->status === 'inactive') {
            throw new DomainStateException('La fuente está inactiva.', 'source_inactive');
        }
        $mapping = $this->mappings->activeFor($source, $entity)
            ?? throw new DomainStateException("No hay un mapeo aprobado y activo para «{$entity}». Defina y apruebe una consulta primero.", 'mapping_not_approved');

        $busy = new DomainStateException('Ya hay una sincronización en curso para esta fuente y entidad.', 'sync_in_progress');
        if (SourceSync::query()->where('data_source_id', $source->id)->where('entity', $entity)->whereIn('status', SourceSync::ACTIVE)->exists()) {
            throw $busy;
        }
        try {
            // Savepoint: una carrera contra el índice único no debe abortar una transacción exterior.
            $sync = DB::transaction(function () use ($source, $mapping, $user, $entity, $mode, $trigger, $requestedMode): SourceSync {
                $sync = new SourceSync;
                $sync->forceFill([
                    'data_source_id' => $source->id,
                    'source_mapping_id' => $mapping->id,
                    'user_id' => $user?->id,
                    'entity' => $entity,
                    'mapping_version' => $mapping->version,
                    'mode' => $mode,
                    'status' => 'queued',
                    'triggered_by' => $trigger,
                    // El modo declarado en la solicitud viaja hasta el trabajo asíncrono en las estadísticas (se reemplazan al terminar).
                    'stats' => $requestedMode !== null ? ['ingestion_mode_requested' => $requestedMode] : null,
                ])->save();

                return $sync;
            });
        } catch (UniqueConstraintViolationException) {
            throw $busy;
        }
        $this->audit->log('datasource.sync_requested', $sync, ['data_source_id' => $source->id, 'entity' => $entity, 'mode' => $mode, 'ingestion_mode' => $requestedMode], userId: $user?->id);

        return $sync;
    }

    /** Ejecutada por el Job SyncDataSource (con la empresa en contexto). */
    public function run(SourceSync $sync): void
    {
        if ($sync->status !== 'queued') {
            return; // otro intento ya la tomó
        }
        $tenant = $this->context->require();
        $startedAt = CarbonImmutable::now();
        $sync->forceFill(['status' => 'running', 'started_at' => $startedAt])->save();
        $stagedDir = null;
        $connection = null;
        try {
            $source = DataSource::query()->findOrFail($sync->data_source_id);
            $mapping = SourceMapping::query()->findOrFail($sync->source_mapping_id);
            if (! $mapping->is_active || $mapping->approved_at === null) {
                throw new DomainStateException('El mapeo ya no está aprobado y activo.', 'mapping_not_approved');
            }
            $connector = $this->mappings->connectorFor($source);
            $options = (array) $mapping->options;
            try {
                $query = $this->mappings->validateQuery($mapping->query, $connector); // se revalida siempre antes de ejecutar
            } catch (SqlQueryRejected $e) {
                throw new DomainStateException('La consulta guardada ya no cumple la política de solo lectura y no se ejecutó: '.$e->getMessage(), 'sql_query_rejected');
            }

            [$mode, $since, $previous] = $this->window($sync, $mapping, $options, $startedAt);
            $sync->forceFill(['mode' => $mode, 'since' => $since])->save();
            // Modo de ingestión de las ventas (ADR-V25-23): solicitud > mapeo > regla de primera sincronización.
            $ingestion = $mapping->entity === 'sales'
                ? $this->ingestionModes->forSync($sync, ((array) $sync->stats)['ingestion_mode_requested'] ?? null, $options['ingestion_mode'] ?? null)
                : null;

            $stagedDir = sys_get_temp_dir().'/predictily-sync-'.Str::uuid();
            File::ensureDirectoryExists($stagedDir, 0700);
            $file = "{$stagedDir}/sql-sync-{$source->id}-{$mapping->entity}-{$sync->id}.csv";
            $sourceTz = (string) ($options['source_timezone'] ?? $source->config['source_timezone'] ?? $tenant->timezone ?: 'America/Guayaquil');

            $rawFile = "{$stagedDir}/sql-extract-{$source->id}-{$mapping->entity}-{$sync->id}.ndjson.gz";
            $connection = $connector->open($source);
            try {
                $staged = $this->stager->stage($connection, $query, $mapping, $tenant, $sourceTz, $since, $file, $startedAt, $rawFile);
            } catch (SourceColumnsMissingException $e) {
                $this->recordBlocker($tenant->id, $mapping->entity, 'source_columns_missing', $e->getMessage(), $sync);
                throw $e;
            }
            $connection->close();
            $connection = null;

            // Bronze ANTES de normalizar y de escribir en Silver: la extracción cruda, con su huella de esquema y su deriva.
            $bronzeObject = null;
            $schema = null;
            if ($staged->rows > 0) {
                try {
                    $captured = $this->bronze->captureSqlExtract($tenant, $sync->user_id, $source->id, $mapping->entity, $rawFile, $staged->rows, $staged->columns, ['source_sync_id' => $sync->id, 'mapping_version' => $mapping->version]);
                } catch (BronzeCaptureException $e) {
                    $this->recordBlocker($tenant->id, $mapping->entity, 'bronze_capture_failed', 'La extracción no pudo guardarse en Bronze antes de normalizar; la sincronización se detuvo y no se cargó nada.', $sync);
                    throw new DomainStateException('No se pudo conservar la extracción original (Bronze): la sincronización se detuvo y no se cargó nada.', 'bronze_unavailable');
                }
                $bronzeObject = $captured['object'];
                $schema = ['fingerprint' => $captured['schema']['fingerprint'], 'version' => $captured['schema']['version'], 'drift' => $captured['schema']['drift']];
            }
            // La importación lee este contexto desde la sincronización (se persiste ahora, antes de que corra).
            $context = ['transformations' => ['value_maps' => $staged->valueMapHits], 'schema' => $schema, 'bronze_object_id' => $bronzeObject?->id];
            $sync->forceFill([
                'bronze_object_id' => $bronzeObject?->id, 'rows_extracted' => $staged->rows, 'pages' => $staged->pages,
                'stats' => array_merge((array) $sync->stats, $context),
            ])->save();

            // Marca de agua = min(máximo source_recorded_at extraído, inicio de la sincronización). Las fechas futuras o ilegibles
            // no cuentan para el máximo (ver CanonicalStager) y se informan como anomalía.
            $usesRecorded = isset($mapping->field_map['source_recorded_at']);
            $watermark = $usesRecorded ? $this->boundedWatermark($staged->maxRecordedAt ?? $previous ?? $startedAt, $startedAt) : $startedAt;
            $stats = ['mode' => $mode, 'rows_extracted' => $staged->rows, 'pages' => $staged->pages] + $context;
            if ($usesRecorded) {
                $stats['future_recorded_at'] = $staged->futureRecordedAt + $staged->unreadableRecordedAt;
                $stats['recorded_at_unreadable'] = $staged->unreadableRecordedAt;
                if ($stats['future_recorded_at'] > 0) {
                    $stats['watermark_note'] = "{$stats['future_recorded_at']} filas traen una fecha de registro futura o ilegible: no adelantan la marca de agua (se acota al inicio de la sincronización).";
                }
            }
            if ($ingestion !== null) {
                $stats += $ingestion->toArray();
            }

            if ($staged->rows === 0) {
                $this->finish($sync, 'completed', $stats + ['import' => null, 'note' => 'La consulta no devolvió filas nuevas o modificadas.'], $watermark, $staged);
                $this->afterSuccess($source, $mapping, $sync, $startedAt, true, $staged);
                $this->dq->persistEmptySyncReconciliation($sync);

                return;
            }

            $job = $this->runImport($tenant, $sync, $source, $mapping, $file, $options, $ingestion, $bronzeObject);
            $importStats = (array) ($job->stats ?? []);
            $clean = ($importStats['rows_error'] ?? 0) === 0;
            $sync->forceFill(['import_job_id' => $job->id])->save();
            $stats['import'] = $importStats;

            if ($job->status === ImportStatus::COMPLETED) {
                // Minimización (LOPDP): el archivo generado puede contener códigos de cliente en claro; ya fue importado.
                $this->imports->disk()->delete($job->stored_path);
                $stats['staged_file_deleted'] = true;
                $this->finish($sync, 'completed', $stats, $watermark, $staged);
                $this->afterSuccess($source, $mapping, $sync, $startedAt, $clean, $staged);
            } elseif ($job->status === ImportStatus::VALIDATED) {
                // Hay filas con error y el mapeo no permite importar solo las válidas: queda para revisión humana.
                // El archivo importado se conserva en el disco privado hasta que el usuario confirme o rechace.
                $this->finish($sync, 'needs_review', $stats + ['note' => 'Hay filas con error. Revise /imports/'.$job->id.'/errors y confirme o rechace la importación.'], null, $staged);
                $source->forceFill(['last_error' => null])->save();
            } else {
                throw new DomainStateException($job->error_message ?: 'La importación de la sincronización falló.', 'import_failed');
            }
        } catch (Throwable $e) {
            $this->fail($sync, $e, $connection);
        } finally {
            $connection?->close();
            if (is_string($stagedDir) && is_dir($stagedDir)) {
                File::deleteDirectory($stagedDir);
            }
        }
    }

    /**
     * Ventana de la extracción.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: string, 1: ?CarbonImmutable, 2: ?CarbonImmutable} [modo, since, marca de agua previa]
     */
    private function window(SourceSync $sync, SourceMapping $mapping, array $options, CarbonImmutable $startedAt): array
    {
        $last = SourceSync::query()->where('data_source_id', $sync->data_source_id)->where('entity', $mapping->entity)
            ->where('status', 'completed')->whereNotNull('watermark')->where('id', '<', $sync->id)->orderByDesc('id')->first();
        // Una marca de agua guardada en el futuro (por una sincronización anterior a la cota) se acota también al leerla.
        $previous = $last?->watermark ? $this->boundedWatermark(CarbonImmutable::instance($last->watermark), $startedAt) : null;
        if ($sync->mode === 'incremental' && $previous !== null) {
            $overlap = (int) ($options['overlap_minutes'] ?? config('connectors.sql.default_overlap_minutes', 120));

            return ['incremental', $previous->subMinutes($overlap), $previous];
        }

        // Primera sincronización (o carga completa pedida): sin ventana.
        return ['full', null, $previous];
    }

    /** min(candidata, inicio de la sincronización): una marca de agua nunca queda en el futuro. */
    private function boundedWatermark(CarbonImmutable $candidate, CarbonImmutable $startedAt): CarbonImmutable
    {
        return $candidate->greaterThan($startedAt) ? $startedAt : $candidate;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function runImport(Tenant $tenant, SourceSync $sync, DataSource $source, SourceMapping $mapping, string $file, array $options, ?IngestionResolution $ingestion, ?BronzeObject $bronze): ImportJob
    {
        $user = $sync->user_id !== null ? User::query()->find($sync->user_id) : null;
        $importOptions = (array) ($options['import'] ?? []);
        if ($ingestion !== null) {
            $importOptions['ingestion_mode'] = $ingestion->mode; // el import lo trata como declarado: la decisión ya se tomó aquí
        }

        // La importación se prepara y se ENLAZA a la sincronización antes de ejecutarse: su informe, linaje y reconciliación leen ese contexto.
        // El original (Bronze) es la extracción cruda, no el CSV de preparación.
        $job = $this->runner->prepareFile($tenant, $user, ImportEntity::from($mapping->entity), $file, $this->stager->headers($mapping), $source->id, $importOptions, null, $bronze);
        $sync->forceFill(['import_job_id' => $job->id])->save();

        // Sin `skip_invalid_rows` en el mapeo, una fila con error deja la sincronización «para revisión» (no importa nada).
        return $this->runner->runPrepared($tenant, $job, $importOptions, requireClean: ! ($importOptions['skip_invalid_rows'] ?? false));
    }

    /** BLOCKER de la sincronización (la carga se detiene): queda un informe de calidad con el hallazgo. */
    private function recordBlocker(int $tenantId, string $entity, string $code, string $message, SourceSync $sync): void
    {
        try {
            $this->dq->recordBlocker($tenantId, $entity, $code, $message, null, $sync);
        } catch (Throwable $e) {
            Log::warning('No se pudo registrar el hallazgo BLOCKER de la sincronización', ['sync_id' => $sync->id, 'error' => $e->getMessage()]);
        }
    }

    /** @param array<string, mixed> $stats */
    private function finish(SourceSync $sync, string $status, array $stats, ?CarbonImmutable $watermark, StagingResult $staged): void
    {
        $sync->forceFill([
            'status' => $status,
            'stats' => $stats,
            'watermark' => $watermark,
            'rows_extracted' => $staged->rows,
            'pages' => $staged->pages,
            'finished_at' => now(),
            'error_message' => null,
        ])->save();
        $this->audit->log('datasource.sync_'.$status, $sync, ['data_source_id' => $sync->data_source_id, 'entity' => $sync->entity, 'mode' => $sync->mode, 'rows' => $staged->rows]);
    }

    private function afterSuccess(DataSource $source, SourceMapping $mapping, SourceSync $sync, CarbonImmutable $startedAt, bool $clean, StagingResult $staged): void
    {
        $source->forceFill(['last_synced_at' => now(), 'last_error' => null, 'status' => 'active'])->save();
        $this->coverage->recordConnectorSync($source, $mapping->entity, $startedAt, $sync->mode, $clean, $staged->minEventDate, (array) $mapping->options);
    }

    private function fail(SourceSync $sync, Throwable $e, ?SqlSourceConnection $connection): void
    {
        $message = $e instanceof DomainStateException || $e instanceof SqlSourceException || $e instanceof PlanLimitExceededException || $e instanceof ValidationException
            ? $e->getMessage()
            : 'La sincronización falló por un error interno. Revise los registros del servidor.';
        Log::error('Sincronización de fuente SQL fallida', ['sync_id' => $sync->id, 'data_source_id' => $sync->data_source_id, 'exception' => $e::class,
            'error' => SqlSourceConnection::safeMessage($e)]);
        try {
            $sync->forceFill(['status' => 'failed', 'error_message' => mb_substr($message, 0, 1000), 'finished_at' => now()])->save();
            $update = ['last_error' => mb_substr($message, 0, 1000)];
            if ($e instanceof SqlSourceException) {
                $update['status'] = 'error';
            }
            DataSource::query()->whereKey($sync->data_source_id)->update($update);
            $this->audit->log('datasource.sync_failed', $sync, ['data_source_id' => $sync->data_source_id, 'entity' => $sync->entity, 'exception' => class_basename($e)]);
        } catch (Throwable $inner) {
            Log::error('No se pudo registrar el fallo de la sincronización', ['sync_id' => $sync->id, 'error' => $inner->getMessage()]);
        }
    }
}
