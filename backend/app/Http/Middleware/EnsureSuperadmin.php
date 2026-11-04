<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth\Mfa\MfaException;
use App\Services\Auth\Mfa\MfaPolicy;
use App\Services\Auth\Mfa\MfaStepUp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas de plataforma (/admin/*): solo superadministradores de Predictily.
 *
 * V2.5 · W6-A (ADR-V25-15): el superadministrador debe tener MFA activo cuando la política de la plataforma lo exige (siempre en producción: 403 `mfa_enrollment_required`) y,
 * con MFA activo, las operaciones que MODIFICAN (no las lecturas) exigen un segundo factor fresco (403 `mfa_step_up_required`).
 */
final class EnsureSuperadmin
{
    public function __construct(private readonly MfaPolicy $policy, private readonly MfaStepUp $stepUp) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->is_superadmin) {
            return response()->json(['message' => 'No tiene permisos para realizar esta acción.', 'code' => 'forbidden'], 403);
        }
        if ($this->policy->superadminRequires() && ! $user->hasMfaEnabled()) {
            throw MfaException::enrollmentRequired();
        }
        if ($user->hasMfaEnabled() && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && ! $this->stepUp->isFresh($request)) {
            throw MfaException::stepUpRequired();
        }

        return $next($request);
    }
}
