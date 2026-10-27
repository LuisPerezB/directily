<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Http\Requests\Core\ChangePasswordRequest;
use App\Http\Requests\Core\LoginRequest;
use App\Http\Requests\Core\RegisterRequest;
use App\Http\Resources\Core\TenantSummaryResource;
use App\Http\Resources\Core\UserResource;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Autenticación. En la SPA (dominio "stateful" de Sanctum) usa sesión con cookies httpOnly.
 * Sin sesión (clientes API) devuelve además un token Bearer personal (no ligado a empresa).
 */
final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth, private readonly AuditLogger $audit) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->auth->register($request->validated());

        return response()->json(['data' => $this->loginPayload($request, $user, false)], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->auth->attempt($request->string('email')->toString(), (string) $request->input('password'));

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
        $tenants = $user->tenants()->orderBy('tenants.name')->get();

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
        $payload = ['user' => new UserResource($user)];

        if ($request->hasSession()) {
            Auth::guard('web')->login($user, $remember);
            $request->session()->regenerate();
        } else {
            $payload['token'] = $user->createToken('api-login', ['*'], now()->addDays(30))->plainTextToken;
            $payload['token_type'] = 'Bearer';
        }

        return $payload;
    }
}
