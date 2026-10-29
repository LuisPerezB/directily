<?php

declare(strict_types=1);

namespace App\Services\DataCore\Sync;

use App\Exceptions\DomainStateException;
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
     *
     * @throws DomainStateException
     */
    public function runFile(Tenant $tenant, ?User $user, ImportEntity $entity, string $csvPath, array $headers, ?int $dataSourceId, array $options = [], bool $requireClean = true): ImportJob
    {
        $job = $this->imports->upload($tenant, $user, $csvPath, $entity, $dataSourceId);
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
        ])->save();
        $this->imports->runValidation($job->refresh());
        $job->refresh();
        if ($job->status !== ImportStatus::VALIDATED) {
            return $job; // falló la validación
        }
        $stats = (array) ($job->stats ?? []);
        if (($stats['rows_error'] ?? 0) > 0 && ($requireClean || ! ($importOptions['skip_invalid_rows'] ?? false))) {
            return $job; // queda VALIDATED para revisión
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
