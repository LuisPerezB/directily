<?php

declare(strict_types=1);

namespace App\Jobs\Privacy;

use App\Jobs\Middleware\SetTenantContext;
use App\Models\TenantExport;
use App\Services\Audit\AuditLogger;
use App\Services\Privacy\TenantDataExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExportTenantData implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(public int $tenantId, public int $exportId)
    {
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetTenantContext()];
    }

    public function handle(TenantDataExporter $exporter, AuditLogger $audit): void
    {
        $export = TenantExport::query()->findOrFail($this->exportId);
        $export->forceFill(['status' => 'running'])->save();
        try {
            $exporter->export($export);
            $audit->log('tenant.export_completed', $export, ['size' => $export->file_size], $export->tenant_id, $export->user_id);
        } catch (Throwable $e) {
            Log::error('Exportación de empresa fallida', ['export_id' => $export->id, 'error' => $e->getMessage()]);
            $export->forceFill(['status' => 'failed', 'error_message' => 'No se pudo generar la exportación.'])->save();
        }
    }
}
