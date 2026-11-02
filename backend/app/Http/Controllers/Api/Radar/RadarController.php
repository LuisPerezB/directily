<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Radar;

use App\Http\Controllers\Api\Customer\CustomerController;
use App\Http\Requests\Radar\RadarFiltersRequest;
use App\Models\CoreRun;
use App\Services\Customer\CustomerEnvelope;
use App\Services\Forecasting\Support\CoreServices;
use App\Services\Radar\RadarFilters;
use Illuminate\Http\Request;

/**
 * Base de los controladores del Revenue Radar (V2.5 · W5-A): empresa activa con el plan de Customer (402 `features.customer` en Starter), paginación acotada
 * y horizonte autorizado por el plan. El Radar es una fachada sobre el Customer Core: hereda sus reglas de plan.
 */
abstract class RadarController extends CustomerController
{
    protected function filters(RadarFiltersRequest $request): RadarFilters
    {
        $tenant = $this->tenant();
        $filters = $request->filters()->withPrivileged($this->canSeeControl($request));
        if ($filters->horizon !== null) {
            CoreServices::assertHorizon($tenant, $filters->horizon);   // 402: un horizonte fuera del plan no se revela
        }

        return $filters;
    }

    /** Solo quien puede aprobar conoce el experimento: ve a qué grupo pertenece una oportunidad y cuántas se retienen en control. */
    protected function canSeeControl(Request $request): bool
    {
        $role = $request->attributes->get('tenant_role');

        return $role !== null && $role->can('decisions.approve');
    }

    /** @return array<string, mixed> */
    protected function envelope(?CoreRun $run, ?int $horizon, string $missingReason = 'Aún no hay una predicción de comportamiento de compra completada.'): array
    {
        $tenant = $this->tenant();

        return $run === null ? CustomerEnvelope::missing($tenant, 'forecast', $missingReason) : CustomerEnvelope::forRun($run, $tenant, $horizon);
    }
}
