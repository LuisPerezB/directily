<?php

declare(strict_types=1);

namespace Tests\Feature\DataCore\Support;

use App\Models\ImportJob;

/** Sube un CSV con encabezados canónicos, lo mapea 1:1, lo valida y (si se pide) lo confirma. */
trait ImportsCsv
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     * @param  array<string, mixed>  $options
     */
    protected function importCsv(string $entity, array $headers, array $rows, bool $confirm = true, array $options = []): ImportJob
    {
        $job = $this->uploadAndValidate($this->tenant, $this->csv($headers, $rows), $entity, array_combine($headers, $headers), $options, "{$entity}.csv");
        $job->refresh();
        if ($confirm) {
            $this->assertSame('validated', $job->status, (string) $job->error_message);
            $this->assertSame(0, (int) ($job->stats['rows_error'] ?? 0), 'Errores de importación: '.json_encode($this->importErrors($job)));
            $this->postJson("/api/v1/imports/{$job->id}/confirm")->assertAccepted();
            $job = $this->withinTenant($this->tenant, fn () => ImportJob::query()->findOrFail($job->id));
            $this->assertSame('completed', $job->status, (string) $job->error_message);
        }

        return $job;
    }

    /** @return list<array<string, mixed>> */
    protected function importErrors(ImportJob $job, string $severity = 'error'): array
    {
        return $this->getJson("/api/v1/imports/{$job->id}/errors?severity={$severity}&per_page=100")->assertOk()->json('data');
    }
}
