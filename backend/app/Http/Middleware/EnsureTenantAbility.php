<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\TenantRole;
use App\Models\PersonalAccessToken;
use App\Services\Auth\Mfa\MfaException;
use App\Services\Auth\Mfa\MfaStepUp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso en rutas: ->middleware('tenant.ability:forecast.run')
 * Requiere ResolveTenant previo. Con tokens Bearer reales, además del rol del usuario
 * el token debe incluir la habilidad (o '*').
 *
 * V2.5 · W6-A (ADR-V25-15): si la habilidad es SENSIBLE (config `security.mfa.sensitive_abilities`: ajustes, usuarios, fuentes y tokens, aprobación de modelos y decisiones,
 * exportaciones, eliminación…) y la persona tiene MFA activo, además exige un segundo factor FRESCO (403 `mfa_step_up_required`; se resuelve con POST /auth/mfa/step-up). La
 * comprobación va DESPUÉS de la de habilidad: quien no tiene la habilidad recibe 403 `forbidden`, no una pista sobre el segundo factor.
 */
final class EnsureTenantAbility
{
    public function __construct(private readonly MfaStepUp $stepUp) {}

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

        $user = $request->user();
        if ($user !== null && array_intersect($abilities, (array) config('security.mfa.sensitive_abilities', [])) !== [] && $user->hasMfaEnabled() && ! $this->stepUp->isFresh($request)) {
            throw MfaException::stepUpRequired();
        }

        return $next($request);
    }
}
