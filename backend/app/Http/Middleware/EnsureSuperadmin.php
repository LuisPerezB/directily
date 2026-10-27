<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Rutas de plataforma (/admin/*): solo superadministradores de Predictily. */
final class EnsureSuperadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_superadmin) {
            return response()->json(['message' => 'No tiene permisos para realizar esta acción.', 'code' => 'forbidden'], 403);
        }

        return $next($request);
    }
}
