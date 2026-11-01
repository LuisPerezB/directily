<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataCore\Concerns;

use App\Models\ImportError;
use App\Models\ImportJob;
use App\Services\DataCore\Sync\CanonicalImportRunner;
use App\Services\Imports\ImportEntity;
use App\Services\Imports\ImportStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Las escrituras por API de las entidades canónicas pasan por el MISMO importador que los archivos: misma validación,
 * mismas reglas de identidad e idempotencia. Si el importador rechaza la fila, la respuesta es 422 con sus errores.
 */
trait ImportsCanonicalRows
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<scalar|null>>  $rows
     * @param  array<string, mixed>  $options
     *
     * @throws ValidationException
     */
    protected function importRows(ImportEntity $entity, array $headers, array $rows, array $options = []): ImportJob
    {
        $runner = app(CanonicalImportRunner::class);
        $job = $runner->runRows(app(TenantContext::class)->require(), request()->user(), $entity, $headers, $rows, null, $options);
        if ($job->status === ImportStatus::COMPLETED) {
            return $job;
        }
        $errors = [];
        $messages = ImportError::query()->where('import_job_id', $job->id)->where('severity', 'error')->orderBy('row_number')->limit(50)->get();
        foreach ($messages as $e) {
            $errors[$e->column ?? 'row'][] = $e->message;
        }
        $runner->discard($job);
        if ($errors === []) {
            $errors['row'][] = $job->error_message ?: 'La fila no superó la validación.';
        }
        $exception = ValidationException::withMessages($errors);
        $exception->response = response()->json(['message' => 'Los datos no superaron la validación del importador.', 'code' => 'validation_failed', 'errors' => $errors], 422);
        throw $exception;
    }
}
