<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Http\Resources\Core\UserResource;
use App\Models\User;
use App\Services\Auth\Mfa\MfaStepUp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Cierra un inicio de sesión ya verificado (contraseña y, si la persona tiene MFA, también el segundo factor): sesión con cookie en la SPA o token Bearer en un cliente API.
 * El token de inicio de sesión caduca según `security.tokens.default_ttl_days` (antes: 30 días fijos en el código) y, si el acceso pasó por el segundo factor, nace con la
 * marca de «segundo factor fresco» (V2.5 · W6-A).
 */
final class LoginResponder
{
    public function __construct(private readonly MfaStepUp $stepUp, private readonly SessionRevoker $sessions) {}

    /** @return array<string, mixed> */
    public function payload(Request $request, User $user, bool $remember, bool $viaMfa = false): array
    {
        $payload = ['user' => new UserResource($user)];

        if ($request->hasSession()) {
            Auth::guard('web')->login($user, $remember);
            $request->session()->regenerate();
            $this->sessions->stamp($request);   // instante de emisión: permite cerrar las demás sesiones (SessionRevoker)
            if ($viaMfa) {
                $this->stepUp->markSession($request);
            }
        } else {
            $days = max(1, (int) config('security.tokens.default_ttl_days', 30));
            $new = $user->createToken('api-login', ['*'], now()->addDays($days));
            $payload['token'] = $new->plainTextToken;
            $payload['token_type'] = 'Bearer';
            $payload['expires_at'] = $new->accessToken->expires_at?->toIso8601String();
            if ($viaMfa) {
                $this->stepUp->markToken((int) $new->accessToken->getKey());
            }
        }

        return $payload;
    }
}
