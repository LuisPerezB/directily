<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;

/**
 * Middleware de cola: restablece el TenantContext a partir de $job->tenantId.
 * Todo Job que toque datos de empresa debe declarar `public int $tenantId` y
 * devolver [new SetTenantContext()] en middleware().
 *
 * Restaura el tenant previo al terminar (importante con QUEUE_CONNECTION=sync, donde el
 * Job corre dentro de la petición HTTP que lo despachó).
 */
final class SetTenantContext
{
    public function handle(object $job, Closure $next): void
    {
        $tenant = Tenant::query()->findOrFail($job->tenantId);
        app(TenantContext::class)->runAs($tenant, fn () => $next($job));
    }
}
