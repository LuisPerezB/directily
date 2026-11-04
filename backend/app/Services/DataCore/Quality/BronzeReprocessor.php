<?php

declare(strict_types=1);

namespace App\Services\DataCore\Quality;

use App\Exceptions\DomainStateException;
use App\Models\BronzeObject;
use App\Models\ImportJob;
use App\Models\SourceMapping;
use App\Models\SourceSync;
use App\Models\Tenant;
use App\Services\Bridge\BridgeBatchProcessor;
use App\Services\DataCore\Bronze\BronzeStore;
use App\Services\DataCore\Sync\CanonicalImportRunner;
use App\Services\DataCore\Sync\CanonicalStager;
use App\Services\Imports\ImportEntity;
use App\Services\Imports\ImportService;
use App\Services\Imports\ImportStatus;
use App\Services\Sales\SalesBatchService;
use App\Services\Sales\SalesDocumentWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Reprocesamiento determinista de un objeto Bronze de ventas (ADR-V25-04 y ADR-V25-24; W2-A): lee el original íntegro (verificando su
 * SHA-256), re-ejecuta la MISMA normalización y escritura que la carga original con el mapeo, las opciones y el modo de ingestión con
 * que se cargó, y no destruye nada. Es idempotente: como los documentos ya vigentes tienen el mismo `source_hash`, una segunda
 * ejecución los reporta `unchanged`.
 *
 *  - `file_upload` (CSV/XLSX): con el mapeo de columnas y las opciones de la última importación completada de ese original;
 *  - `sql_extract` (NDJSON comprimido): con la versión del mapeo SQL con que se extrajo (misma transformación del conector);
 *  - `api_batch` (JSON): con las opciones y el modo declarados en el lote.
 * El reprocesamiento no consume la cuota mensual de filas ni cuenta como una petición nueva.
 *
 * SEGURIDAD: reprocesar un original VIEJO sin `source_recorded_at` ganaría por «última ingestión» y revertiría versiones más nuevas. Antes
 * de escribir se proyecta el resultado (validación) y, si algún documento existente con otro contenido se escribió DESPUÉS del original y no
 * hay orden de fuente que decida, se detiene con 409 `reprocess_would_overwrite_newer`; `--force` lo confirma y el resultado informa cuántos
 * documentos más nuevos sobrescribió (`overwrote_newer_documents`).
 */
final class BronzeReprocessor
{
    public function __construct(
        private readonly BronzeStore $store,
        private readonly ImportService $imports,
        private readonly CanonicalImportRunner $runner,
        private readonly CanonicalStager $stager,
        private readonly SalesBatchService $batches,
    ) {}

    /**
     * @return array{object_id: int, kind: string, import_job_id: ?int, documents: array<string, int>, overwrote_newer_documents: int}
     *
     * @throws DomainStateException
     */
    public function reprocess(Tenant $tenant, BronzeObject $object, bool $force = false): array
    {
        if ($object->entity !== 'sales') {
            throw new DomainStateException('Solo se reprocesan objetos Bronze de ventas.', 'unsupported_entity', ['entity' => $object->entity]);
        }
        if ($object->status === 'purged') {
            throw new DomainStateException('El objeto Bronze fue purgado por retención: no hay contenido que reprocesar.', 'bronze_purged');
        }
        $contents = $this->store->read($object); // verifica el SHA-256

        return match ($object->kind) {
            'file_upload' => $this->file($tenant, $object, $contents, $force),
            'sql_extract' => $this->sql($tenant, $object, $contents, $force),
            'api_batch' => $this->batch($tenant, $object, $contents, $force),
            // V2.5 · W6-B: el lote del Bridge es NDJSON gzip de filas canónicas (no el cuerpo JSON de POST /sales/batch): lo reprocesa su propio servicio.
            'bridge_batch' => app(BridgeBatchProcessor::class)->reprocess($tenant, $object, $force),
            default => throw new DomainStateException('Tipo de objeto Bronze no reprocesable: '.$object->kind, 'unsupported_kind'),
        };
    }

    /**
     * Documentos que se actualizarían SIN orden de fuente aunque ya se escribieron DESPUÉS de este original (clasificación de
     * `SalesDocumentWriter::classify`): reprocesarlo los revertiría.
     *
     * @param  array<string, array{state: string, id: ?int, without_order: bool, stored: ?object, mode_mismatch: bool}>  $classes
     */
    public static function newerThanOriginal(array $classes, BronzeObject $object): int
    {
        $count = 0;
        foreach ($classes as $class) {
            if ($class['state'] === SalesDocumentWriter::STATE_UPDATED && $class['without_order'] && $class['stored'] !== null && $object->received_at !== null
                && isset($class['stored']->updated_at) && CarbonImmutable::parse((string) $class['stored']->updated_at)->greaterThan($object->received_at)) {
                $count++;
            }
        }

        return $count;
    }

    private static function overwriteRefused(int $documents): DomainStateException
    {
        return new DomainStateException(
            "{$documents} documento(s) ya están en Silver con otro contenido y se escribieron DESPUÉS de este original, y el original no trae orden de fuente (source_recorded_at): reprocesarlo los sobrescribiría con una versión más vieja. Use --force para confirmarlo.",
            'reprocess_would_overwrite_newer',
            ['documents' => $documents],
        );
    }

    /** @return array{object_id: int, kind: string, import_job_id: ?int, documents: array<string, int>, overwrote_newer_documents: int} */
    private function file(Tenant $tenant, BronzeObject $object, string $contents, bool $force): array
    {
        $original = ImportJob::query()->where('bronze_object_id', $object->id)->whereNotNull('column_mapping')
            ->orderByRaw("CASE WHEN status = 'completed' THEN 0 ELSE 1 END")->orderByDesc('id')->first();
        if ($original === null) {
            throw new DomainStateException('No hay una importación de este original con su mapeo de columnas: no se puede reprocesar.', 'mapping_not_found');
        }
        $format = $object->metadata['format'] ?? (str_ends_with((string) $object->object_key, '.xlsx') ? 'xlsx' : 'csv');
        $stored = sprintf('tenants/%d/imports/%s.%s', $tenant->id, Str::uuid(), $format);
        $this->imports->disk()->put($stored, $contents);
        try {
            $job = new ImportJob;
            $job->forceFill([
                'tenant_id' => $tenant->id, 'user_id' => null, 'data_source_id' => $original->data_source_id, 'entity_type' => ImportEntity::Sales->value,
                'original_filename' => mb_substr('reprocesamiento: '.($object->original_name ?? "bronze-{$object->id}"), 0, 255),
                'stored_path' => $stored, 'file_hash' => $object->sha256, 'file_size' => $object->size_bytes, 'file_format' => $format,
                'status' => ImportStatus::VALIDATING, 'detected_columns' => $original->detected_columns, 'column_mapping' => $original->column_mapping,
                'options' => array_merge((array) $original->options, ['reprocess_of_bronze_object_id' => $object->id]),
                'bronze_object_id' => $object->id,
            ])->save();
            // Proyección primero: si reprocesar revertiría versiones más nuevas y no hay --force, no se escribe nada.
            $this->imports->runValidation($job);
            $job->refresh();
            $newer = (int) (((array) $job->stats)['updated_newer_than_original'] ?? 0);
            if ($job->status !== ImportStatus::VALIDATED) {
                throw new DomainStateException('La proyección del reprocesamiento terminó en estado '.$job->status.': '.($job->error_message ?? 'sin detalle'), 'reprocess_failed');
            }
            if ($newer > 0 && ! $force) {
                $this->imports->reject($job);
                throw self::overwriteRefused($newer);
            }
            $job->forceFill(['status' => ImportStatus::IMPORTING, 'confirmed_at' => now(), 'progress' => 0])->save();
            $this->imports->runCommit($job);
            $job->refresh();
        } finally {
            $this->imports->disk()->delete($stored); // copia de trabajo: el original queda en Bronze
        }
        if ($job->status !== ImportStatus::COMPLETED) {
            throw new DomainStateException('El reprocesamiento terminó en estado '.$job->status.': '.($job->error_message ?? 'sin detalle'), 'reprocess_failed');
        }

        return ['object_id' => $object->id, 'kind' => $object->kind, 'import_job_id' => $job->id, 'documents' => self::documents((array) $job->stats), 'overwrote_newer_documents' => $newer];
    }

    /** @return array{object_id: int, kind: string, import_job_id: ?int, documents: array<string, int>, overwrote_newer_documents: int} */
    private function sql(Tenant $tenant, BronzeObject $object, string $contents, bool $force): array
    {
        $sync = SourceSync::query()->where('bronze_object_id', $object->id)->orderByDesc('id')->first();
        $mapping = $sync !== null ? SourceMapping::query()->find($sync->source_mapping_id) : null;
        if ($sync === null || $mapping === null) {
            throw new DomainStateException('No se encuentra la sincronización ni el mapeo con que se extrajo este original.', 'mapping_not_found');
        }
        $options = (array) $mapping->options;
        $sourceTz = (string) ($options['source_timezone'] ?? $tenant->timezone ?: 'America/Guayaquil');
        $dir = sys_get_temp_dir().'/predictily-reprocess-'.Str::uuid();
        File::ensureDirectoryExists($dir, 0700);
        try {
            $rows = (function () use ($contents) {
                $text = (string) gzdecode($contents);
                foreach (explode("\n", $text) as $line) {
                    if ($line !== '') {
                        yield json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    }
                }
            })();
            $csv = "{$dir}/reprocess-{$object->id}.csv";
            $this->stager->stageRows($rows, $mapping, $tenant, $sourceTz, $csv);
            $importOptions = (array) ($options['import'] ?? []);
            $importOptions['ingestion_mode'] = ((array) $sync->stats)['ingestion_mode'] ?? ($options['ingestion_mode'] ?? null);
            $importOptions['reprocess_of_bronze_object_id'] = $object->id;
            $newer = 0;
            $job = $this->runner->runFile($tenant, null, ImportEntity::Sales, $csv, $this->stager->headers($mapping), $sync->data_source_id, $importOptions, false, null, $object,
                function (ImportJob $validated) use ($force, &$newer): void {
                    $newer = (int) (((array) $validated->stats)['updated_newer_than_original'] ?? 0);
                    if ($newer > 0 && ! $force) {
                        throw self::overwriteRefused($newer);
                    }
                });
        } finally {
            File::deleteDirectory($dir);
        }
        if ($job->status !== ImportStatus::COMPLETED) {
            throw new DomainStateException('El reprocesamiento terminó en estado '.$job->status.': '.($job->error_message ?? 'sin detalle'), 'reprocess_failed');
        }
        $this->imports->disk()->delete($job->stored_path);

        return ['object_id' => $object->id, 'kind' => $object->kind, 'import_job_id' => $job->id, 'documents' => self::documents((array) $job->stats), 'overwrote_newer_documents' => $newer];
    }

    /** @return array{object_id: int, kind: string, import_job_id: ?int, documents: array<string, int>, overwrote_newer_documents: int} */
    private function batch(Tenant $tenant, BronzeObject $object, string $contents, bool $force): array
    {
        $body = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $result = $this->batches->ingest(
            $tenant, (array) ($body['documents'] ?? []), $object->data_source_id, (array) ($body['options'] ?? []), $body['ingestion_mode'] ?? null, $object, $force,
        );

        return ['object_id' => $object->id, 'kind' => $object->kind, 'import_job_id' => null, 'documents' => [
            'new' => $result['created'], 'updated' => $result['updated'], 'unchanged' => $result['unchanged'], 'stale_skipped' => $result['stale_skipped'], 'rejected' => $result['rejected'],
        ], 'overwrote_newer_documents' => 0];
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, int>
     */
    private static function documents(array $stats): array
    {
        return [
            'new' => (int) ($stats['documents_new'] ?? 0), 'updated' => (int) ($stats['documents_updated'] ?? 0), 'unchanged' => (int) ($stats['documents_unchanged'] ?? 0),
            'stale_skipped' => (int) ($stats['stale_skipped'] ?? 0), 'rejected' => (int) ($stats['documents_invalid'] ?? 0),
        ];
    }
}
