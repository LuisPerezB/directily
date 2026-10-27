<?php

declare(strict_types=1);

namespace App\Jobs\Imports;

use App\Jobs\Middleware\SetTenantContext;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoDataService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Carga datos ficticios de demostración en una empresa existente (POST /tenant/demo-data). */
final class LoadDemoData implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $tenantId, public string $slug, public ?int $userId = null) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }

    public function handle(DemoDataService $demo): void
    {
        try {
            $demo->load(Tenant::query()->findOrFail($this->tenantId), $this->slug, $this->userId ? User::query()->find($this->userId) : null);
        } catch (Throwable $e) {
            Log::error('Carga de datos demo fallida', ['tenant_id' => $this->tenantId, 'error' => $e->getMessage()]);
        }
    }
}
