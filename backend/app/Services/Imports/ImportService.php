<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Exceptions\DomainStateException;
use App\Jobs\Imports\CommitImport;
use App\Jobs\Imports\ValidateImport;
use App\Models\Alert;
use App\Models\ImportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Imports\Fields\ImportFieldRegistry;
use App\Services\Imports\Mapping\ColumnMatcher;
use App\Services\Imports\Processors\ImportRunContext;
use App\Services\Imports\Reading\ReaderFactory;
use App\Services\Plans\PlanLimitService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orquesta el ciclo de vida de una importación (docs/06 §3.6):
 * subir → analizar (sincrónico, solo primeras filas) → mapear → validar (cola) →
 * confirmar → importar (cola) → completada. Conserva el archivo original y su SHA-256.
 */
final class ImportService
{
    public function __construct(
        private readonly ImportFieldRegistry $fields,
        private readonly ColumnMatcher $matcher,
        private readonly ReaderFactory $readers,
        private readonly ImportProcessorFactory $processors,
        private readonly QualityReportBuilder $quality,
        private readonly PlanLimitService $plans,
        private readonly AuditLogger $audit,
    ) {
    }

    public function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk((string) config('predictily.imports.disk', 'local'));
    }

    /** Sube un archivo (UploadedFile o ruta local para datos demo) y lo analiza. */
    public function upload(Tenant $tenant, ?User $user, UploadedFile|string $file, ImportEntity $entity, ?int $dataSourceId = null): ImportJob
    {
        $originalName = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file);
        $extension = $file instanceof UploadedFile ? $file->getClientOriginalExtension() : pathinfo($file, PATHINFO_EXTENSION);
        $format = ReaderFactory::formatFromExtension($extension);
        if ($format === null) {
            throw new DomainStateException('Formato no soportado. Use CSV o XLSX.', 'unsupported_format');
        }
        $sourcePath = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $hash = hash_file('sha256', $sourcePath) ?: '';
        $storedPath = sprintf('tenants/%d/imports/%s.%s', $tenant->id, Str::uuid(), $format === 'csv' ? 'csv' : 'xlsx');
        $stream = fopen($sourcePath, 'rb');
        $this->disk()->put($storedPath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $previous = ImportJob::query()->where('file_hash', $hash)->where('entity_type', $entity->value)->where('status', ImportStatus::COMPLETED)->latest('id')->first(['id', 'created_at']);

        $job = new ImportJob();
        $job->forceFill([
            'tenant_id' => $tenant->id,
            'user_id' => $user?->id,
            'data_source_id' => $dataSourceId,
            'entity_type' => $entity->value,
            'original_filename' => mb_substr($originalName, 0, 255),
            'stored_path' => $storedPath,
            'file_hash' => $hash,
            'file_size' => (int) filesize($sourcePath),
            'file_format' => $format,
            'status' => ImportStatus::UPLOADED,
            'options' => ImportRunContext::defaultOptions(),
            'stats' => $previous ? ['previous_import_id' => $previous->id] : null,
        ])->save();

        $this->audit->log('import.uploaded', $job, ['entity_type' => $entity->value, 'format' => $format, 'size' => $job->file_size]);

        return $this->analyze($job);
    }

    /** Detecta columnas, sugiere mapeo y arma la vista previa (lee solo las primeras filas). */
    public function analyze(ImportJob $job): ImportJob
    {
        try {
            $reader = $this->readers->make($this->disk()->path($job->stored_path), $job->file_format, $job->options ?? []);
            $headers = $reader->headers();
            if ($headers === [] || count(array_filter($headers, fn ($h) => $h !== '')) === 0) {
                throw new DomainStateException('El archivo no tiene encabezados o está vacío.', 'empty_file');
            }
            $rows = [];
            foreach ($reader->rows() as $n => $values) {
                $rows[] = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : (is_scalar($v) || $v === null ? $v : (string) $v), array_slice($values, 0, count($headers)));
                if ($n >= (int) config('predictily.imports.preview_rows', 20)) {
                    break;
                }
            }
            $suggested = $this->matcher->suggest($this->fields->fields(ImportEntity::from($job->entity_type)), $headers);
            $job->forceFill([
                'status' => ImportStatus::AWAITING_MAPPING,
                'detected_columns' => $headers,
                'suggested_mapping' => $suggested,
                'preview' => ['headers' => $headers, 'rows' => $rows, 'dialect' => $reader->dialect()],
                'error_message' => null,
            ])->save();
        } catch (Throwable $e) {
            $job->forceFill([
                'status' => ImportStatus::FAILED,
                'error_message' => $e instanceof DomainStateException ? $e->getMessage() : 'No se pudo leer el archivo. Verifique que sea un CSV o XLSX válido.',
            ])->save();
            Log::warning('Análisis de importación fallido', ['import_job_id' => $job->id, 'error' => $e->getMessage()]);
        }

        return $job;
    }

    /**
     * Guarda el mapeo/opciones y lanza la validación asíncrona.
     *
     * @param array<string, string|null> $mapping
     * @param array<string, mixed> $options
     */
    public function setMapping(ImportJob $job, array $mapping, array $options = []): ImportJob
    {
        return DB::transaction(function () use ($job, $mapping, $options): ImportJob {
            $locked = ImportJob::query()->lockForUpdate()->findOrFail($job->id);
            if (! in_array($locked->status, ImportStatus::MAPPABLE, true) || $locked->detected_columns === null) {
                throw new DomainStateException('La importación no admite cambios de mapeo en su estado actual.', 'invalid_state', ['status' => $locked->status]);
            }
            $entity = ImportEntity::from($locked->entity_type);
            $this->assertMappingValid($entity, $mapping, $locked->detected_columns, $options);

            $locked->forceFill([
                'column_mapping' => array_filter($mapping, fn ($v) => $v !== null && $v !== ''),
                'options' => array_merge(ImportRunContext::defaultOptions(), $locked->options ?? [], $options),
                'status' => ImportStatus::VALIDATING,
                'progress' => 0,
                'error_message' => null,
                'validated_at' => null,
            ])->save();

            DB::afterCommit(fn () => ValidateImport::dispatch($locked->tenant_id, $locked->id)->onQueue('imports'));

            return $locked;
        });
    }

    public function confirm(ImportJob $job): ImportJob
    {
        $tenant = Tenant::query()->findOrFail($job->tenant_id);

        return DB::transaction(function () use ($job, $tenant): ImportJob {
            $locked = ImportJob::query()->lockForUpdate()->findOrFail($job->id);
            if ($locked->status !== ImportStatus::VALIDATED) {
                throw new DomainStateException('Solo se puede confirmar una importación validada.', 'invalid_state', ['status' => $locked->status]);
            }
            $stats = $locked->stats ?? [];
            if (($stats['rows_error'] ?? 0) > 0 && ! ($locked->options['skip_invalid_rows'] ?? false)) {
                throw new DomainStateException(
                    'La importación tiene filas con errores. Corríjalas o active la opción de importar solo filas válidas.',
                    'has_errors',
                    ['rows_error' => $stats['rows_error']],
                );
            }
            if (($stats['rows_valid'] ?? 0) === 0) {
                throw new DomainStateException('No hay filas válidas para importar.', 'nothing_to_import');
            }
            $this->plans->assertCan($tenant, 'max_import_rows_per_month', (int) ($stats['rows_valid'] ?? 0));
            if (($stats['products_new'] ?? 0) > 0) {
                $this->plans->assertCan($tenant, 'max_active_products', (int) $stats['products_new']);
            }
            if (($stats['branches_new'] ?? 0) > 0) {
                $this->plans->assertCan($tenant, 'max_branches', (int) $stats['branches_new']);
            }

            $locked->forceFill(['status' => ImportStatus::IMPORTING, 'confirmed_at' => now(), 'progress' => 0])->save();
            $this->audit->log('import.confirmed', $locked, ['entity_type' => $locked->entity_type, 'rows_valid' => $stats['rows_valid'] ?? 0]);
            DB::afterCommit(fn () => CommitImport::dispatch($locked->tenant_id, $locked->id)->onQueue('imports'));

            return $locked;
        });
    }

    public function reject(ImportJob $job): ImportJob
    {
        if (! in_array($job->status, ImportStatus::REJECTABLE, true)) {
            throw new DomainStateException('La importación no puede rechazarse en su estado actual.', 'invalid_state', ['status' => $job->status]);
        }
        // Minimización: el archivo rechazado se elimina; se conserva su huella y metadatos.
        $this->disk()->delete($job->stored_path);
        $job->forceFill(['status' => ImportStatus::REJECTED, 'finished_at' => now()])->save();
        $this->audit->log('import.rejected', $job);

        return $job;
    }

    /** Ejecutado por el Job ValidateImport. */
    public function runValidation(ImportJob $job): void
    {
        if ($job->status !== ImportStatus::VALIDATING) {
            return; // otro intento ya la procesó o cambió de estado
        }
        $job->forceFill(['started_at' => now()])->save();
        try {
            $result = $this->execute($job, false);
            $job->forceFill([
                'status' => ImportStatus::VALIDATED,
                'validated_at' => now(),
                'progress' => 100,
            ])->save();
            $this->audit->log('import.validated', $job, ['rows_total' => $result['stats']['rows_total'] ?? 0, 'rows_error' => $result['stats']['rows_error'] ?? 0]);
        } catch (Throwable $e) {
            $this->fail($job, $e, 'validación');
        }
    }

    /** Ejecutado por el Job CommitImport. */
    public function runCommit(ImportJob $job): void
    {
        if ($job->status !== ImportStatus::IMPORTING) {
            return;
        }
        $tenant = Tenant::query()->findOrFail($job->tenant_id);
        try {
            $result = $this->execute($job, true);
            $job->forceFill(['status' => ImportStatus::COMPLETED, 'finished_at' => now(), 'progress' => 100])->save();
            $this->plans->recordUsage($tenant, 'import_rows', (int) ($result['stats']['rows_valid'] ?? 0));
            $this->audit->log('import.completed', $job, array_intersect_key($result['stats'], array_flip(['rows_valid', 'documents_new', 'documents_updated', 'documents_unchanged', 'products_created'])));
            $this->afterCommit($job);
        } catch (Throwable $e) {
            $this->fail($job, $e, 'importación');
        }
    }

    /**
     * Ejecuta validación+importación de forma síncrona (datos demo, comandos).
     *
     * @param array<string, string|null> $mapping
     * @param array<string, mixed> $options
     */
    public function importNow(ImportJob $job, ?array $mapping = null, array $options = []): ImportJob
    {
        $mapping ??= $job->suggested_mapping ?? [];
        $job->forceFill([
            'column_mapping' => array_filter($mapping, fn ($v) => $v !== null && $v !== ''),
            'options' => array_merge(ImportRunContext::defaultOptions(), $job->options ?? [], $options),
            'status' => ImportStatus::VALIDATING,
        ])->save();
        $this->runValidation($job->refresh());
        if ($job->refresh()->status !== ImportStatus::VALIDATED) {
            return $job;
        }
        $job->forceFill(['status' => ImportStatus::IMPORTING, 'confirmed_at' => now()])->save();
        $this->runCommit($job->refresh());

        return $job->refresh();
    }

    /** @return array{stats: array<string, mixed>, issues: list<array<string, mixed>>} */
    private function execute(ImportJob $job, bool $commit): array
    {
        $tenant = Tenant::query()->findOrFail($job->tenant_id);
        DB::table('import_errors')->where('tenant_id', $job->tenant_id)->where('import_job_id', $job->id)->delete();

        $reader = $this->readers->make($this->disk()->path($job->stored_path), $job->file_format, $job->options ?? []);
        $ctx = new ImportRunContext($tenant, $job, $reader, $job->column_mapping ?? [], $commit);
        $result = $this->processors->for(ImportEntity::from($job->entity_type))->run($ctx);

        $stats = $result['stats'];
        $normalized = $stats['normalized_preview'] ?? [];
        unset($stats['normalized_preview']);
        $stats['errors_stored'] = $ctx->issues->storedCount();
        if (isset(($job->stats ?? [])['previous_import_id'])) {
            $stats['previous_import_id'] = $job->stats['previous_import_id'];
        }

        $report = $this->quality->build($stats, $result['issues']);
        $this->quality->persist($job, $report);

        $preview = $job->preview ?? [];
        $preview['normalized'] = $normalized;
        $job->forceFill(['stats' => $stats, 'preview' => $preview])->save();

        return $result;
    }

    private function afterCommit(ImportJob $job): void
    {
        if (in_array($job->entity_type, [ImportEntity::Sales->value, ImportEntity::Inventory->value], true)
            && class_exists(\App\Jobs\Forecasting\RefreshReadiness::class)) {
            try {
                \App\Jobs\Forecasting\RefreshReadiness::dispatch($job->tenant_id)->onQueue('forecasting');
            } catch (Throwable $e) {
                Log::warning('No se pudo programar la evaluación de preparación de datos', ['error' => $e->getMessage()]);
            }
        }
    }

    private function fail(ImportJob $job, Throwable $e, string $phase): void
    {
        Log::error("Error en {$phase} de importación", ['import_job_id' => $job->id, 'error' => $e->getMessage(), 'exception' => $e::class]);
        $job->forceFill([
            'status' => ImportStatus::FAILED,
            'finished_at' => now(),
            'error_message' => $e instanceof DomainStateException
                ? $e->getMessage()
                : "Ocurrió un error durante la {$phase}. Revise el archivo e intente nuevamente.",
        ])->save();
        $this->audit->log('import.failed', $job, ['phase' => $phase, 'exception' => class_basename($e)]);
        Alert::query()->forceCreate([
            'tenant_id' => $job->tenant_id,
            'type' => 'import_failed',
            'severity' => 'warning',
            'title' => 'Importación fallida',
            'message' => "La {$phase} del archivo «{$job->original_filename}» no pudo completarse.",
            'payload' => ['import_job_id' => $job->id],
            'related_type' => 'import_job',
            'related_id' => $job->id,
            'dedup_key' => 'import_failed:'.$job->id,
            'status' => 'open',
        ]);
    }

    /**
     * @param array<string, string|null> $mapping
     * @param list<string> $columns
     * @param array<string, mixed> $options
     */
    private function assertMappingValid(ImportEntity $entity, array $mapping, array $columns, array $options): void
    {
        $fields = $this->fields->keyed($entity);
        $errors = [];
        $used = [];
        foreach ($mapping as $field => $column) {
            if (! isset($fields[$field])) {
                $errors["mapping.{$field}"][] = "Campo desconocido: {$field}.";
                continue;
            }
            if ($column === null || $column === '') {
                continue;
            }
            if (! in_array($column, $columns, true)) {
                $errors["mapping.{$field}"][] = "La columna «{$column}» no existe en el archivo.";
            }
            if (isset($used[$column])) {
                $errors["mapping.{$field}"][] = "La columna «{$column}» ya está asignada a {$used[$column]}.";
            }
            $used[$column] = $field;
        }
        foreach ($this->fields->requiredKeys($entity) as $required) {
            if (empty($mapping[$required])) {
                $errors["mapping.{$required}"][] = "El campo «{$fields[$required]->label}» es obligatorio.";
            }
        }
        if ($entity === ImportEntity::LeadTimes && empty($mapping['product_sku']) && empty($mapping['supplier_code'])) {
            $errors['mapping.product_sku'][] = 'Mapee al menos el producto o el proveedor.';
        }
        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }
}
