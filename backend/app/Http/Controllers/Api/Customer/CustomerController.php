<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Customer\CustomerRunService;
use App\Services\Forecasting\Support\CoreServices;
use App\Services\Plans\PlanLimitService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Base de los controladores del Customer Core (empresa activa, paginación acotada, horizonte autorizado). */
abstract class CustomerController extends Controller
{
    /** Empresa activa. Todo el Customer Core exige que el plan incluya el comportamiento de compra (402 `features.customer`; Starter no lo incluye). */
    protected function tenant(): Tenant
    {
        $tenant = app(TenantContext::class)->require();
        app(PlanLimitService::class)->assertCustomer($tenant);

        return $tenant;
    }

    protected function perPage(Request $request, int $default = 25): int
    {
        return max(1, min(100, (int) $request->integer('per_page', $default)));
    }

    /**
     * Horizonte pedido (o el mayor permitido por el plan ∩ el subconjunto de Customer del catálogo cuando no se indica). Un horizonte fuera del plan
     * responde 402: no se revela un resultado comercial no autorizado.
     */
    protected function horizon(Request $request, ?int $preferred = 30): int
    {
        $tenant = $this->tenant();
        $allowed = app(CustomerRunService::class)->allowedHorizons($tenant);
        if ($request->filled('horizon')) {
            $h = (int) $request->integer('horizon');
            CoreServices::assertHorizon($tenant, $h);

            return $h;
        }
        if ($allowed === []) {
            throw ValidationException::withMessages(['horizon' => 'Su plan no incluye horizontes de comportamiento de compra.']);
        }

        return in_array($preferred, $allowed, true) ? $preferred : max($allowed);
    }
}
