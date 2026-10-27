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
 */
final class SetTenantContext
{
    public function handle(object $job, Closure $next): void
    {
        $context = app(TenantContext::class);
        $tenant = Tenant::query()->findOrFail($job->tenantId);
        $context->set($tenant);
        try {
            $next($job);
        } finally {
            $context->forget();
        }
    }
}
