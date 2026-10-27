<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/** Base de los controladores analíticos (empresa activa y paginación acotada). */
abstract class AnalyticsController extends Controller
{
    protected function tenant(): Tenant
    {
        return app(TenantContext::class)->require();
    }

    protected function perPage(Request $request, int $default = 25): int
    {
        return max(1, min(100, (int) $request->integer('per_page', $default)));
    }

    /** Horizonte por defecto: el configurado por la empresa si es válido, o 30 días. */
    protected function defaultHorizon(): int
    {
        $default = (int) ($this->tenant()->effectiveSettings()['default_horizon_days'] ?? 30);

        return in_array($default, array_map('intval', (array) config('engine.horizons')), true) ? $default : 30;
    }
}
