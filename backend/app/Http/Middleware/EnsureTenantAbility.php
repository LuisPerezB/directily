<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\TenantRole;
use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso en rutas: ->middleware('tenant.ability:forecast.run')
 * Requiere ResolveTenant previo. Con tokens Bearer reales, además del rol del usuario
 * el token debe incluir la habilidad (o '*').
 */
final class EnsureTenantAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        /** @var TenantRole|null $role */
        $role = $request->attributes->get('tenant_role');
        $token = $request->user()?->currentAccessToken();
        $isRealToken = $token instanceof PersonalAccessToken && $token->exists;

        foreach ($abilities as $ability) {
            $roleAllows = $role !== null && $role->can($ability);
            $tokenAllows = ! $isRealToken || $token->can($ability);
            if (! $roleAllows || ! $tokenAllows) {
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
