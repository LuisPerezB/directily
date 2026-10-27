<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\TenantRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso en rutas: ->middleware('tenant.ability:forecast.run')
 * Requiere ResolveTenant previo.
 */
final class EnsureTenantAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        /** @var TenantRole|null $role */
        $role = $request->attributes->get('tenant_role');
        foreach ($abilities as $ability) {
            if ($role === null || ! $role->can($ability)) {
                return response()->json([
                    'message' => 'No tiene permisos para realizar esta acción.',
                    'code' => 'forbidden',
                    'required_ability' => $ability,
                ], 403);
            }
        }

        return $next($request);
    }
}
