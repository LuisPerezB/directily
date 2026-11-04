<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use App\Exceptions\DomainStateException;
use App\Models\BronzeObject;
use App\Models\DataSource;
use App\Models\SourceMapping;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\DataCore\Bronze\BronzeStore;
use App\Services\DataCore\Sync\CanonicalImportRunner;
use App\Services\DataCore\Sync\CanonicalStager;
use App\Services\Imports\Fields\ImportFieldRegistry;
use App\Services\Imports\ImportEntity;
use App\Services\Imports\ImportService;
use App\Services\Imports\ImportStatus;
use App\Services\Sales\IngestionModeResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Lote Bridge en Bronze → pipeline de importación EXISTENTE (ADR-V25-10 y ADR-V25-24). Nunca escribe datos de negocio directamente:
 *
 *   Bronze (`bridge_batch`, NDJSON gzip de filas con campos canónicos) → mismas transformaciones que la sincronización SQL
 *   (`CanonicalStager::stageRows`: mapeos de valores y zonas horarias) → CSV de preparación → `CanonicalImportRunner` (validación,
 *   idempotencia por clave natural + source_hash, procedencia `data_source_id`, linaje `bronze_object_id`).
 *
 * Idempotente: el estado de procesamiento vive en `bronze_objects.metadata.bridge.processing`; un lote ya completado o enviado a revisión
 * no se vuelve a procesar, y un procesamiento en curso (menos de una hora) no se duplica. Un fallo queda registrado (`failed`) y se
 * reintenta cuando el agente vuelva a enviar el mismo lote. Las filas con errores dejan el lote «para revisión» (`needs_review`), igual
 * que la sincronización SQL: el original queda a salvo en Bronze y el administrador resuelve la importación con la API de importaciones.
 *
 * Opciones de la fuente (`data_sources.config.bridge`): `source_timezone`, `value_maps` y `import` (skip_invalid_rows,
 * default_branch_code, create_missing_products|branches|categories). Si no son válidas, el lote falla con un mensaje claro.
 * La cobertura de la fuente NO se infiere desde el Bridge (se declara con la API de cobertura): ver docs/integrations/CONNECTOR_FRAMEWORK.md.
 */
final class BridgeBatchProcessor
{
    private const IMPORT_OPTION_KEYS = ['skip_invalid_rows', 'default_branch_code', 'create_missing_products', 'create_missing_branches', 'create_missing_categories'];

    public function __construct(
        private readonly BronzeStore $store,
        private readonly CanonicalStager $stager,
        private readonly CanonicalImportRunner $runner,
        private readonly ImportService $imports,
        private readonly ImportFieldRegistry $fields,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** Ejecutado por el trabajo ProcessBridgeBatch con la empresa en contexto. */
    public function process(int $objectId): void
    {
        $tenant = $this->context->require();
        $object = $this->claim($objectId);
        if ($object === null) {
            return;   // ya procesado, en curso, purgado o inexistente
        }
        $dir = null;
        try {
            $source = DataSource::query()->findOrFail($object->data_source_id);
            if ($source->status === 'inactive') {
                throw new DomainStateException('La fuente está inactiva: el lote queda en Bronze y se procesará cuando se reactive.', 'source_inactive');
            }
            $dir = sys_get_temp_dir().'/predictily-bridge-'.Str::uuid();
            File::ensureDirectoryExists($dir, 0700);
            ['entity' => $entity, 'mapping' => $mapping, 'csv' => $csv, 'rows' => $rows, 'options' => $importOptions, 'ingestion' => $ingestion] = $this->stage($tenant, $object, $source, $dir);
            $job = $this->runner->prepareFile($tenant, null, $entity, $csv, $this->stager->headers($mapping), $source->id, $importOptions, null, $object);
            $job = $this->runner->runPrepared($tenant, $job, $importOptions, requireClean: ! ($importOptions['skip_invalid_rows'] ?? false));
            $stats = (array) ($job->stats ?? []);

            if ($job->status === ImportStatus::COMPLETED) {
                $this->imports->disk()->delete($job->stored_path);   // minimización: el original autorizado ya está en Bronze
                $this->finish($object, 'completed', ['import_job_id' => $job->id, 'rows' => $rows, 'import' => $this->summary($stats)] + ($ingestion ?? []), 'processed');
                $source->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();
            } elseif ($job->status === ImportStatus::VALIDATED) {
                $this->finish($object, 'needs_review', ['import_job_id' => $job->id, 'rows' => $rows, 'import' => $this->summary($stats), 'note' => 'Hay filas con error. Revise /imports/'.$job->id.'/errors y confirme o rechace la importación.'] + ($ingestion ?? []), null);
            } else {
                throw new DomainStateException((string) ($job->error_message ?: 'La importación del lote falló.'), 'import_failed');
            }
        } catch (Throwable $e) {
            $this->fail($object, $e);
        } finally {
            if (is_string($dir) && is_dir($dir)) {
                File::deleteDirectory($dir);
            }
        }
    }

    /**
     * Prepara un lote para el pipeline: valida sus columnas, aplica las MISMAS transformaciones que la sincronización SQL (mapeos de valores y
     * zonas horarias) y deja el CSV canónico en `$dir`. Determinista: el mismo lote y la misma configuración producen el mismo CSV.
     *
     * @return array{entity: ImportEntity, mapping: SourceMapping, csv: string, rows: int, options: array<string, mixed>, ingestion: ?array<string, string>}
     */
    private function stage(Tenant $tenant, BronzeObject $object, DataSource $source, string $dir, ?string $forcedIngestionMode = null): array
    {
        $entity = ImportEntity::from($object->entity);
        $bridge = (array) ($object->metadata['bridge'] ?? []);
        $settings = $this->sourceSettings($source);
        $headers = $this->readHeaders($object);
        $this->assertAllowedColumns($entity, $headers);

        $mapping = new SourceMapping;
        $mapping->forceFill([
            'data_source_id' => $source->id, 'entity' => $entity->value, 'version' => 1, 'is_active' => true,
            'field_map' => array_combine($headers, $headers),
            'options' => array_filter(['value_maps' => $settings['value_maps']], fn ($v) => $v !== []),
        ]);
        $csv = "{$dir}/bridge-{$entity->value}-{$object->id}.csv";
        $sourceTz = (string) ($bridge['source_timezone'] ?? $settings['source_timezone'] ?? $source->source_timezone ?? $tenant->timezone ?: 'America/Guayaquil');
        $rows = $this->stager->stageRows($this->rows($object, $headers), $mapping, $tenant, $sourceTz, $csv);

        $options = $settings['import'];
        $ingestion = null;
        if ($entity === ImportEntity::Sales) {
            $ingestion = $forcedIngestionMode !== null
                ? ['mode' => $forcedIngestionMode, 'ingestion_mode' => $forcedIngestionMode, 'ingestion_mode_basis' => IngestionModeResolver::BASIS_DECLARED]
                : $this->ingestionFor($object, $bridge);
            $options['ingestion_mode'] = $ingestion['mode'];
        }
        $options['bridge_batch_of_bronze_object_id'] = $object->id;

        return ['entity' => $entity, 'mapping' => $mapping, 'csv' => $csv, 'rows' => $rows, 'options' => $options, 'ingestion' => $ingestion];
    }

    /**
     * Reprocesamiento determinista desde Bronze (ADR-V25-24): lee el original (verificando su SHA-256), repite la MISMA normalización con el modo
     * de ingestión con que se cargó y escribe por el pipeline; es idempotente (los documentos vigentes con el mismo `source_hash` quedan
     * `unchanged`). Se detiene con 409 `reprocess_would_overwrite_newer` si sobrescribiría versiones más nuevas, salvo `$force`.
     * Lo invoca BronzeReprocessor para los objetos `bridge_batch`.
     *
     * @return array{object_id: int, kind: string, import_job_id: ?int, documents: array<string, int>, overwrote_newer_documents: int}
     *
     * @throws DomainStateException
     */
    public function reprocess(Tenant $tenant, BronzeObject $object, bool $force = false): array
    {
        $source = DataSource::query()->find($object->data_source_id)
            ?? throw new DomainStateException('No se encuentra la fuente Bridge del lote.', 'mapping_not_found');
        $dir = sys_get_temp_dir().'/predictily-bridge-reprocess-'.Str::uuid();
        File::ensureDirectoryExists($dir, 0700);
        try {
            $original = (string) ($object->metadata['bridge']['processing']['ingestion_mode'] ?? '');
            $staged = $this->stage($tenant, $object, $source, $dir, in_array($original, IngestionModeResolver::MODES, true) ? $original : null);
            $staged['options']['reprocess_of_bronze_object_id'] = $object->id;
            $newer = 0;
            $job = $this->runner->runFile($tenant, null, $staged['entity'], $staged['csv'], $this->stager->headers($staged['mapping']), $source->id, $staged['options'], false, null, $object,
                function ($validated) use ($force, &$newer): void {
                    $newer = (int) (((array) $validated->stats)['updated_newer_than_original'] ?? 0);
                    if ($newer > 0 && ! $force) {
                        throw new DomainStateException(
                            "{$newer} documento(s) ya están en Silver con otro contenido y se escribieron DESPUÉS de este original, y el original no trae orden de fuente (source_recorded_at): reprocesarlo los sobrescribiría con una versión más vieja. Use --force para confirmarlo.",
                            'reprocess_would_overwrite_newer', ['documents' => $newer],
                        );
                    }
                });
        } finally {
            File::deleteDirectory($dir);
        }
        if ($job->status !== ImportStatus::COMPLETED) {
            throw new DomainStateException('El reprocesamiento terminó en estado '.$job->status.': '.($job->error_message ?? 'sin detalle'), 'reprocess_failed');
        }
        $this->imports->disk()->delete($job->stored_path);
        $stats = (array) ($job->stats ?? []);

        return ['object_id' => $object->id, 'kind' => $object->kind, 'import_job_id' => $job->id, 'documents' => [
            'new' => (int) ($stats['documents_new'] ?? 0), 'updated' => (int) ($stats['documents_updated'] ?? 0), 'unchanged' => (int) ($stats['documents_unchanged'] ?? 0),
            'stale_skipped' => (int) ($stats['stale_skipped'] ?? 0), 'rejected' => (int) ($stats['documents_invalid'] ?? 0),
        ], 'overwrote_newer_documents' => $newer];
    }

    /** Toma el lote para procesarlo (bloqueo de fila): null si no corresponde procesarlo ahora. */
    private function claim(int $objectId): ?BronzeObject
    {
        return DB::transaction(function () use ($objectId): ?BronzeObject {
            $object = BronzeObject::query()->whereKey($objectId)->where('kind', 'bridge_batch')->lockForUpdate()->first();
            if ($object === null || $object->status !== 'stored') {
                return null;
            }
            $processing = (array) ($object->metadata['bridge']['processing'] ?? []);
            $status = (string) ($processing['status'] ?? 'queued');
            if (in_array($status, ['completed', 'needs_review'], true)) {
                return null;
            }
            if ($status === 'running' && isset($processing['started_at']) && now()->diffInMinutes(CarbonImmutable::parse((string) $processing['started_at']), true) < 60) {
                return null;
            }
            $this->setProcessing($object, ['status' => 'running', 'started_at' => now()->toIso8601String()]);
            $object->save();

            return $object;
        });
    }

    /** @param array<string, mixed> $processing */
    private function setProcessing(BronzeObject $object, array $processing): void
    {
        $metadata = (array) $object->metadata;
        $metadata['bridge'] = array_merge((array) ($metadata['bridge'] ?? []), ['processing' => $processing]);
        $object->metadata = $metadata;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function finish(BronzeObject $object, string $status, array $details, ?string $bronzeStatus): void
    {
        $this->setProcessing($object, ['status' => $status, 'finished_at' => now()->toIso8601String()] + $details);
        if ($bronzeStatus !== null) {
            $object->status = $bronzeStatus;
        }
        $object->save();
        $this->audit->log('bridge.batch_'.$status, $object, ['entity' => $object->entity, 'import_job_id' => $details['import_job_id'] ?? null, 'rows' => $details['rows'] ?? null], (int) $object->tenant_id);
    }

    private function fail(BronzeObject $object, Throwable $e): void
    {
        $known = $e instanceof DomainStateException || $e instanceof BridgeRejected || $e instanceof ValidationException;
        $message = $known ? $e->getMessage() : 'El procesamiento del lote falló por un error interno. Revise los registros del servidor.';
        $code = $e instanceof DomainStateException ? $e->errorCode : ($e instanceof BridgeRejected ? $e->errorCode : 'internal_error');
        Log::error('Procesamiento de lote Bridge fallido', ['bronze_object_id' => $object->id, 'exception' => $e::class, 'error' => mb_substr($e->getMessage(), 0, 300)]);
        try {
            $this->setProcessing($object->refresh(), ['status' => 'failed', 'finished_at' => now()->toIso8601String(), 'error_code' => $code, 'error' => mb_substr($message, 0, 500)]);
            if ($code === 'bridge_batch_invalid') {
                $object->status = 'quarantined';   // contenido que incumple el contrato: no se reprocesa ni se acepta su checkpoint (B-1)
            }
            $object->save();
            DataSource::query()->whereKey($object->data_source_id)->update(['last_error' => mb_substr($message, 0, 1000)]);
            $this->audit->log('bridge.batch_failed', $object, ['entity' => $object->entity, 'error_code' => $code], (int) $object->tenant_id);
        } catch (Throwable $inner) {
            Log::error('No se pudo registrar el fallo del lote Bridge', ['bronze_object_id' => $object->id, 'error' => $inner->getMessage()]);
        }
    }

    /**
     * Opciones de la fuente: validadas aquí porque la fuente se crea con la API genérica de fuentes.
     *
     * @return array{source_timezone: ?string, value_maps: array<string, mixed>, import: array<string, mixed>}
     */
    private function sourceSettings(DataSource $source): array
    {
        $bridge = (array) (((array) $source->config)['bridge'] ?? []);
        $timezone = $bridge['source_timezone'] ?? null;
        if ($timezone !== null && ! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new DomainStateException('config.bridge.source_timezone no es una zona horaria válida.', 'bridge_config_invalid');
        }
        $import = array_intersect_key((array) ($bridge['import'] ?? []), array_flip(self::IMPORT_OPTION_KEYS));
        $valueMaps = (array) ($bridge['value_maps'] ?? []);

        return ['source_timezone' => $timezone, 'value_maps' => $valueMaps, 'import' => $import];
    }

    /** Columnas de la primera fila del lote (las declaradas y validadas al subirlo). @return list<string> */
    private function readHeaders(BronzeObject $object): array
    {
        $keys = [];
        foreach ($this->lines($object) as $row) {
            foreach (array_keys($row) as $key) {
                $keys[(string) $key] = true;
            }
        }
        if ($keys === []) {
            throw new DomainStateException('El lote no trae filas.', 'bridge_batch_invalid');
        }

        return array_keys($keys);
    }

    /** @param list<string> $headers */
    private function assertAllowedColumns(ImportEntity $entity, array $headers): void
    {
        $definitions = $this->fields->keyed($entity);
        $unknown = array_values(array_diff($headers, array_keys($definitions)));
        if ($unknown !== []) {
            throw new DomainStateException('El lote trae columnas que no son campos canónicos: '.implode(', ', array_slice($unknown, 0, 10)).'.', 'bridge_batch_invalid');
        }
        foreach ($definitions as $key => $definition) {
            if ($definition->required && ! in_array($key, $headers, true)) {
                throw new DomainStateException("El lote no trae el campo obligatorio «{$key}».", 'bridge_batch_invalid');
            }
        }
    }

    /**
     * Filas del lote con TODAS las columnas (las ausentes en una fila van como null).
     *
     * @param  list<string>  $headers
     * @return \Generator<int, array<string, mixed>>
     */
    private function rows(BronzeObject $object, array $headers): \Generator
    {
        $template = array_fill_keys($headers, null);
        foreach ($this->lines($object) as $row) {
            yield array_replace($template, $row);
        }
    }

    /**
     * Líneas NDJSON del lote (lectura en flujo con TODOS los límites de BridgeBatchReader: gzip real, filas, bytes y relación de descompresión, línea y valor,
     * valores escalares). Verifica el SHA-256 de Bronze al leer. Un lote inválido (que no debería llegar aquí: la recepción lo valida entero) se pone en cuarentena.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function lines(BronzeObject $object): \Generator
    {
        $contents = $this->store->read($object);
        $path = tempnam(sys_get_temp_dir(), 'bridge-read-');
        if ($path === false) {
            throw new DomainStateException('No hay almacenamiento temporal disponible.', 'storage_unavailable');
        }
        try {
            file_put_contents($path, $contents);
            $compressed = strlen($contents);
            unset($contents);
            try {
                foreach (BridgeBatchReader::rows($path, $compressed) as $row) {
                    yield $row;
                }
            } catch (BridgeBatchInvalid $e) {
                throw new DomainStateException($e->getMessage(), 'bridge_batch_invalid');
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * Modo de ingestión de las ventas (ADR-V25-23): la PRIMERA extracción completa de la fuente es `backfill` (todos sus lotes); las demás,
     * `incremental`. «Primera» = ningún lote procesado de otra extracción.
     *
     * @param  array<string, mixed>  $bridge
     * @return array{mode: string, ingestion_mode: string, ingestion_mode_basis: string}
     */
    private function ingestionFor(BronzeObject $object, array $bridge): array
    {
        $runId = (string) ($bridge['run_id'] ?? '');
        $loadedBefore = BronzeObject::query()->where('kind', 'bridge_batch')->where('data_source_id', $object->data_source_id)->where('entity', $object->entity)
            ->where('id', '<', $object->id)->where('status', 'processed')
            ->whereRaw("metadata->'bridge'->>'run_id' IS DISTINCT FROM ?", [$runId])->exists();
        $full = ($bridge['run_mode'] ?? 'full') === 'full';
        if ($full && ! $loadedBefore) {
            return ['mode' => IngestionModeResolver::BACKFILL, 'ingestion_mode' => IngestionModeResolver::BACKFILL, 'ingestion_mode_basis' => IngestionModeResolver::BASIS_FIRST_SYNC];
        }

        return ['mode' => IngestionModeResolver::INCREMENTAL, 'ingestion_mode' => IngestionModeResolver::INCREMENTAL, 'ingestion_mode_basis' => IngestionModeResolver::BASIS_SUBSEQUENT_SYNC];
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function summary(array $stats): array
    {
        return array_intersect_key($stats, array_flip(['rows_total', 'rows_valid', 'rows_error', 'documents_new', 'documents_updated', 'documents_unchanged', 'stale_skipped', 'products_created', 'ingestion_mode']));
    }
}
