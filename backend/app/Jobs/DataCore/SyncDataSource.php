<?php

declare(strict_types=1);

namespace App\Jobs\DataCore;

use App\Jobs\Middleware\SetTenantContext;
use App\Models\SourceSync;
use App\Services\DataCore\Sync\SourceSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ejecuta una sincronización de fuente SQL (cola «imports»). Transporta la empresa de forma EXPLÍCITA: el
 * middleware SetTenantContext la restablece y el scope fail-closed impide tocar la sincronización de otra empresa.
 */
final class SyncDataSource implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $tenantId, public int $syncId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }

    public function handle(SourceSyncService $service): void
    {
        $sync = SourceSync::query()->find($this->syncId);
        if ($sync !== null) {
            $service->run($sync);
        }
    }

    /** Tiempo agotado o fallo fuera del servicio: no deja la sincronización «en curso» para siempre. */
    public function failed(?Throwable $e): void
    {
        DB::table('source_syncs')->where('tenant_id', $this->tenantId)->where('id', $this->syncId)->whereIn('status', SourceSync::ACTIVE)
            ->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => 'La sincronización se interrumpió (tiempo agotado o error del proceso).']);
    }
}
