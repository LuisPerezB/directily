<?php

declare(strict_types=1);

namespace App\Jobs\Imports;

use App\Jobs\Middleware\SetTenantContext;
use App\Models\ImportJob;
use App\Services\Imports\ImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Valida todas las filas del archivo según el mapeo (cola "imports"). */
final class ValidateImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(public int $tenantId, public int $importJobId)
    {
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetTenantContext()];
    }

    public function handle(ImportService $imports): void
    {
        $job = ImportJob::query()->find($this->importJobId);
        if ($job !== null) {
            $imports->runValidation($job);
        }
    }
}
