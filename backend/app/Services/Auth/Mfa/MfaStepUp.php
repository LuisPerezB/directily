<?php

declare(strict_types=1);

namespace App\Services\Auth\Mfa;

use App\Models\PersonalAccessToken;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Segundo factor «fresco» (V2.5 · W6-A): quien tiene MFA activo debe haberlo verificado hace menos de `security.mfa.step_up_ttl_seconds` para las acciones sensibles.
 * El inicio de sesión con MFA cuenta como verificación. La marca vive donde vive la autenticación, y desaparece con ella:
 * - SPA con sesión: en la sesión (se destruye al cerrar sesión).
 * - Token Bearer real: en la caché, atada al ID del token (revocar el token borra su derecho a la marca: ya no autentica).
 * - Sin ninguno de los dos (solo ocurre con `Sanctum::actingAs` en pruebas): en la caché, atada a la persona.
 * Una marca perdida (caché vaciada) falla cerrado: se pide el código otra vez.
 */
final class MfaStepUp
{
    private const SESSION_KEY = 'mfa_fresh_at';

    public function ttl(): int
    {
        return max(1, (int) config('security.mfa.step_up_ttl_seconds', 600));
    }

    /** ¿Esta autenticación ya verificó el segundo factor dentro de la ventana? */
    public function isFresh(Request $request): bool
    {
        $at = $this->freshAt($request);

        return $at !== null && $at->addSeconds($this->ttl())->isFuture();
    }

    /**
     * Para las acciones de la clase «aprobar» que un controlador decide EN LÍNEA con `$role->can('models.approve')` (validar un modelo ajeno a la publicación, restaurar un aprobado que nunca
     * se sirvió = promoverlo): el rol debe tener la habilidad Y, si la persona tiene MFA activo, un segundo factor fresco. Sin esto esas rutas, que solo exigen `forecast.run`, serían una
     * puerta lateral para promover modelos con un segundo factor vencido.
     */
    public function approveClassAllowed(Request $request, bool $roleCan): bool
    {
        if (! $roleCan) {
            return false;
        }
        $user = $request->user();

        return $user === null || ! $user->hasMfaEnabled() || $this->isFresh($request);
    }

    public function freshUntil(Request $request): ?CarbonImmutable
    {
        $at = $this->freshAt($request);
        $until = $at?->addSeconds($this->ttl());

        return $until !== null && $until->isFuture() ? $until : null;
    }

    /** Marca la autenticación de la petición como verificada ahora. */
    public function mark(Request $request): void
    {
        $now = now()->getTimestamp();
        $token = $this->token($request);
        if ($token !== null) {
            Cache::put($this->tokenKey((int) $token->getKey()), $now, $this->ttl());
        } elseif ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $now);
        } else {
            Cache::put($this->transientKey($request), $now, $this->ttl());
        }
    }

    /** Marca un token recién emitido (inicio de sesión de un cliente API con MFA). */
    public function markToken(int $tokenId): void
    {
        Cache::put($this->tokenKey($tokenId), now()->getTimestamp(), $this->ttl());
    }

    /** Marca la sesión de la petición (inicio de sesión de la SPA con MFA). */
    public function markSession(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, now()->getTimestamp());
        }
    }

    private function freshAt(Request $request): ?CarbonImmutable
    {
        $token = $this->token($request);
        $value = match (true) {
            $token !== null => Cache::get($this->tokenKey((int) $token->getKey())),
            $request->hasSession() => $request->session()->get(self::SESSION_KEY),
            default => Cache::get($this->transientKey($request)),
        };

        return is_numeric($value) ? CarbonImmutable::createFromTimestamp((int) $value) : null;
    }

    private function token(Request $request): ?PersonalAccessToken
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken && $token->exists ? $token : null;
    }

    private function tokenKey(int $tokenId): string
    {
        return 'mfa:fresh:token:'.$tokenId;
    }

    private function transientKey(Request $request): string
    {
        return 'mfa:fresh:transient:'.($request->user()?->getAuthIdentifier() ?? 'anon');
    }
}
