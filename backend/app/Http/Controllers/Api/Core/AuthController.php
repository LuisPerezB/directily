<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireFullLogin;
use App\Http\Requests\Core\ChangePasswordRequest;
use App\Http\Requests\Core\LoginRequest;
use App\Http\Requests\Core\RegisterRequest;
use App\Http\Resources\Core\TenantSummaryResource;
use App\Http\Resources\Core\UserResource;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AuthService;
use App\Services\Auth\LoginResponder;
use App\Services\Auth\Mfa\MfaChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Autenticación. En la SPA (dominio "stateful" de Sanctum) usa sesión con cookies httpOnly.
 * Sin sesión (clientes API) devuelve además un token Bearer personal (no ligado a empresa).
 *
 * V2.5 · W6-A: si la persona tiene el segundo factor activo, una contraseña correcta NO inicia sesión: responde 202 `mfa_required` con un desafío de un solo uso y corta vida;
 * la sesión o el token se emiten al completar POST /auth/mfa/challenge.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AuditLogger $audit,
        private readonly LoginResponder $responder,
        private readonly MfaChallenge $challenges,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->auth->register($request->validated());

        return response()->json(['data' => $this->loginPayload($request, $user, false)], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->auth->verifyCredentials($request->string('email')->toString(), (string) $request->input('password'));
        if ($user->hasMfaEnabled()) {
            $this->audit->log('auth.mfa_challenge_issued', $user, [], null, $user->id);

            return response()->json([
                'message' => 'Ingrese el código de su aplicación autenticadora para completar el inicio de sesión.',
                'code' => 'mfa_required',
                'data' => [
                    'mfa_required' => true,
                    'challenge' => $this->challenges->issue((int) $user->id, $request->boolean('remember')),
                    'expires_in' => $this->challenges->ttl(),
                    'methods' => ['totp', 'recovery_code'],
                ],
            ], 202);
        }
        $this->auth->completeLogin($user);

        return response()->json(['data' => $this->loginPayload($request, $user, $request->boolean('remember'))]);
    }

    public function logout(Request $request): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && $token->exists) {
            $token->delete();
        }
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        $this->audit->log('auth.logout', $user, [], null, $user?->id);

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();
        $query = $user->tenants()->orderBy('tenants.name');
        // V2.5 · W6-A: un token restringido o ligado a una empresa solo ve SU empresa (no revela las demás de la persona); sin empresa propia no ve ninguna.
        if (! RequireFullLogin::isFullLogin($token)) {
            $query->where('tenants.id', $token->tenant_id ?? 0);
        }
        $tenants = $query->get();

        return response()->json(['data' => [
            'user' => new UserResource($user),
            'tenants' => TenantSummaryResource::collection($tenants),
        ]]);
    }

    public function changePassword(ChangePasswordRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['password' => $request->input('password')])->save();
        // Revoca tokens personales existentes salvo los ligados a conectores.
        $user->tokens()->whereNull('tenant_id')->delete();
        $this->audit->log('auth.password_changed', $user);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function loginPayload(Request $request, User $user, bool $remember): array
    {
        return $this->responder->payload($request, $user, $remember);
    }
}
