<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Supply;

use App\Http\Controllers\Controller;
use App\Models\CoreRun;
use App\Models\Tenant;
use App\Support\Results\ResultEnvelope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Base de los controladores de decisiones comunes (empresa activa, paginación acotada, envoltura de resultados). */
abstract class SupplyController extends Controller
{
    protected function tenant(): Tenant
    {
        return app(TenantContext::class)->require();
    }

    protected function perPage(Request $request, int $default = 25): int
    {
        return max(1, min(100, (int) $request->integer('per_page', $default)));
    }

    /**
     * Envoltura común (ADR-V2-03) de una corrida de decisiones.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function envelope(CoreRun $run, array $extra = []): array
    {
        $tenant = $this->tenant();

        return ResultEnvelope::fromCoreRun($run, array_merge([
            'entity_keys' => match ($run->core) {
                'cost' => ['product_id', 'supplier_id'],
                'scenario' => ['strategy_key'],
                default => ($run->operation === 'plan' ? ['branch_id', 'product_id', 'supplier_id'] : ['policy']),
            },
            'unit' => 'product_unit',
            'currency' => $tenant->currency ?: 'USD',
        ], $extra));
    }
}
