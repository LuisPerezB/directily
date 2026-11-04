<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth\SessionRevoker;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sustituye a `AuthenticateSession` de Sanctum en las peticiones con estado de la SPA (`config/sanctum.php` → `middleware.authenticate_session`): además de comparar la contraseña
 * de la sesión, rechaza una sesión web emitida ANTES (o en el mismo instante) de `users.sessions_valid_after` (V2.5 · W6-A, revisión: activar o desactivar el segundo factor y el restablecimiento por la
 * plataforma cierran las demás sesiones, con cualquier controlador de sesión). Una sesión sin marca de emisión (anterior a este control) se trata como emitida en el instante 0.
 */
final class AuthenticateSessionWithRevocation extends AuthenticateSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && ($user = $request->user()) !== null) {
            $after = $user->getAttributes()['sessions_valid_after'] ?? null;
            if ($after !== null && (int) $request->session()->get(SessionRevoker::ISSUED_KEY, 0) <= (int) $after) {
                $guard = $this->auth->guard('web');
                if (method_exists($guard, 'logoutCurrentDevice')) {
                    $guard->logoutCurrentDevice();
                }
                $request->session()->flush();

                throw new AuthenticationException('Unauthenticated.', ['web', 'sanctum']);
            }
        }

        return parent::handle($request, $next);
    }
}
