<?php

declare(strict_types=1);

namespace App\Services\DataCore\Sync;

use App\Exceptions\DomainStateException;
use App\Models\BronzeObject;
use App\Models\ImportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Imports\ImportEntity;
use App\Services\Imports\ImportService;
use App\Services\Imports\ImportStatus;
use App\Services\Imports\Processors\ImportRunContext;
use App\Services\Plans\PlanLimitService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Entrega filas CANÓNICAS al MISMO pipeline de importación que un archivo cargado por el usuario (validación,
 * idempotencia, procedencia). Lo usan la sincronización SQL y la API de compromisos/calendario/conversiones/almacenes:
 * ninguna ruta escribe datos de negocio saltándose el importador.
 */
final class CanonicalImportRunner
{
    public function __construct(private readonly ImportService $imports, private readonly PlanLimitService $plans) {}

    /**
     * @param  list<string>  $headers  campos canónicos
     * @param  iterable<list<scalar|null>>  $rows
     * @param  array<string, mixed>  $options  opciones del importador
     */
    public function runRows(Tenant $tenant, ?User $user, ImportEntity $entity, array $headers, iterable $rows, ?int $dataSourceId = null, array $options = [], bool $requireClean = true): ImportJob
    {
        // Filas canónicas que llegan por API: el CSV generado es el original autorizado (Bronze `api_batch`).
        $dir = sys_get_temp_dir().'/predictily-rows-'.Str::uuid();
        File::ensureDirectoryExists($dir, 0700);
        try {
            $file = "{$dir}/api-{$entity->value}.csv";
            $handle = fopen($file, 'wb');
            fputcsv($handle, $headers, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($v) => $v === null ? '' : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v), $row), ',', '"', '');
            }
            fclose($handle);

            return $this->runFile($tenant, $user, $entity, $file, $headers, $dataSourceId, $options, $requireClean);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * Sube el CSV canónico, lo valida y, si cumple la política, lo confirma. Devuelve el ImportJob en su estado final:
     * COMPLETED, VALIDATED (hay filas con error y no se permite importar solo las válidas) o FAILED.
     *
     * @param  list<string>  $headers
     * @param  array<string, mixed>  $options
     * @param  ?string  $bronzeKind  tipo del objeto Bronze del CSV (`api_batch`); null = quien llama ya guardó el original
     *
     * @throws DomainStateException
     */
    public function runFile(Tenant $tenant, ?User $user, ImportEntity $entity, string $csvPath, array $headers, ?int $dataSourceId, array $options = [], bool $requireClean = true, ?string $bronzeKind = 'api_batch', ?BronzeObject $bronze = null, ?callable $afterValidation = null): ImportJob
    {
        $job = $this->prepareFile($tenant, $user, $entity, $csvPath, $headers, $dataSourceId, $options, $bronzeKind, $bronze);

        return $this->runPrepared($tenant, $job, $options, $requireClean, $afterValidation);
    }

    /**
     * Sube el CSV canónico y lo deja listo para validar (estado VALIDATING). La sincronización SQL enlaza aquí su contexto (la extracción
     * cruda en Bronze) con la importación ANTES de ejecutarla.
     *
     * @param  list<string>  $headers
     * @param  array<string, mixed>  $options
     *
     * @throws DomainStateException
     */
    public function prepareFile(Tenant $tenant, ?User $user, ImportEntity $entity, string $csvPath, array $headers, ?int $dataSourceId, array $options = [], ?string $bronzeKind = 'api_batch', ?BronzeObject $bronze = null): ImportJob
    {
        $job = $this->imports->upload($tenant, $user, $csvPath, $entity, $dataSourceId, $bronzeKind);
        if ($job->status === ImportStatus::FAILED) {
            throw new DomainStateException($job->error_message ?: 'No se pudo preparar la importación.', 'import_failed');
        }
        $importOptions = array_merge(
            ImportRunContext::defaultOptions(),
            $options,
            ['decimal_separator' => '.', 'thousands_separator' => '', 'date_format' => 'auto'],
        );
        $job->forceFill([
            'column_mapping' => array_combine($headers, $headers),
            'options' => $importOptions,
            'status' => ImportStatus::VALIDATING,
            'progress' => 0,
            'bronze_object_id' => $bronze?->id ?? $job->bronze_object_id,
        ])->save();

        return $job;
    }

    /**
     * Valida un trabajo preparado y, si cumple la política, lo confirma.
     *
     * @param  array<string, mixed>  $options
     * @param  ?callable(ImportJob): void  $afterValidation  se ejecuta con la importación ya validada y ANTES de escribir; si lanza, la importación se descarta
     */
    public function runPrepared(Tenant $tenant, ImportJob $job, array $options, bool $requireClean, ?callable $afterValidation = null): ImportJob
    {
        $importOptions = (array) $job->options;
        $this->imports->runValidation($job->refresh());
        $job->refresh();
        if ($job->status !== ImportStatus::VALIDATED) {
            return $job; // falló la validación
        }
        $stats = (array) ($job->stats ?? []);
        if (($stats['rows_error'] ?? 0) > 0 && ($requireClean || ! ($importOptions['skip_invalid_rows'] ?? false))) {
            return $job; // queda VALIDATED para revisión
        }
        if ($afterValidation !== null) {
            try {
                $afterValidation($job);
            } catch (\Throwable $e) {
                $this->discard($job);
                throw $e;
            }
        }
        if (($stats['rows_valid'] ?? 0) === 0) {
            throw new DomainStateException('Ninguna fila superó la validación.', 'nothing_to_import');
        }
        $this->plans->assertCan($tenant, 'max_import_rows_per_month', (int) $stats['rows_valid']);
        if (($stats['products_new'] ?? 0) > 0) {
            $this->plans->assertCan($tenant, 'max_active_products', (int) $stats['products_new']);
        }
        if (($stats['branches_new'] ?? 0) > 0) {
            $this->plans->assertCan($tenant, 'max_branches', (int) $stats['branches_new']);
        }
        $job->forceFill(['status' => ImportStatus::IMPORTING, 'confirmed_at' => now(), 'progress' => 0])->save();
        $this->imports->runCommit($job->refresh());

        return $job->refresh();
    }

    /** Descarta una importación que no se confirmó (p. ej. una llamada de API con errores): elimina el archivo. */
    public function discard(ImportJob $job): void
    {
        if (in_array($job->status, ImportStatus::REJECTABLE, true)) {
            $this->imports->reject($job);
        }
    }
}
