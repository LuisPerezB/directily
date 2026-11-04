<?php

declare(strict_types=1);

namespace App\Services\Intelligence\SharedGrants;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Primer middleware de las rutas de concesiones: con la bandera `intelligence.shared_forecast_grants.enabled` apagada (por defecto) la ruta
 * «no existe» (404 idéntico al de una ruta inexistente) incluso sin sesión, antes de autenticar y antes de resolver la empresa.
 */
final class EnsureSharedForecastGrantsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! SharedForecastGrantService::isEnabled()) {
            throw new NotFoundHttpException('Ruta no encontrada.');
        }

        return $next($request);
    }
}
