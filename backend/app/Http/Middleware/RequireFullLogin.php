<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas de CUENTA (alias `account.full`; V2.5 · W6-A, revisión): tokens propios, segundo factor, crear/listar empresas y cambio de contraseña. Hablan de la PERSONA, no de una
 * empresa, así que ni las habilidades de un token ni su empresa son una frontera para ellas: sin esta puerta, un token de solo lectura (o de conector) podría emitir un token con más
 * habilidades, activar su propio autenticador o revocar los demás tokens de la persona.
 *
 * Pasa: una sesión de la SPA (o la autenticación transitoria de las pruebas) y un token de INICIO DE SESIÓN completo (`api-login`: habilidades `['*']` y sin empresa).
 * No pasa (403 `token_scope_insufficient`): un token con habilidades acotadas, ligado a una empresa (conector o personal) o cualquier otro que no sea de inicio de sesión. Los
 * dispositivos del Bridge no usan Sanctum y no llegan aquí.
 */
final class RequireFullLogin
{
    public static function isFullLogin(?object $token): bool
    {
        if (! $token instanceof PersonalAccessToken || ! $token->exists) {
            return true;   // sesión de la SPA o autenticación transitoria: no es un token de API
        }

        return $token->tenant_id === null && in_array('*', (array) $token->abilities, true);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::isFullLogin($request->user()?->currentAccessToken())) {
            return response()->json([
                'message' => 'Esta acción es de la cuenta y exige un inicio de sesión completo: un token restringido, de conector o ligado a una empresa no puede hacerla.',
                'code' => 'token_scope_insufficient',
            ], 403);
        }

        return $next($request);
    }
}
