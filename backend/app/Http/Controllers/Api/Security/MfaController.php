<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Security\MfaChallengeRequest;
use App\Http\Requests\Security\MfaCodeRequest;
use App\Http\Requests\Security\MfaDisableRequest;
use App\Http\Requests\Security\MfaPasswordRequest;
use App\Http\Requests\Security\MfaVerifyRequest;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\LoginResponder;
use App\Services\Auth\Mfa\MfaChallenge;
use App\Services\Auth\Mfa\MfaException;
use App\Services\Auth\Mfa\MfaPolicy;
use App\Services\Auth\Mfa\MfaService;
use App\Services\Auth\Mfa\MfaStepUp;
use App\Services\Auth\SessionRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Segundo factor TOTP (V2.5 · W6-A; ADR-V25-15). Rutas bajo /auth/mfa. Ninguna respuesta ni log incluye un secreto o un código salvo el secreto de la activación (una vez) y los
 * códigos de recuperación recién generados (una vez). Contrato: docs/security/ENTERPRISE_SECURITY.md §MFA y docs/openapi/v2.yaml.
 */
final class MfaController extends Controller
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly MfaStepUp $stepUp,
        private readonly MfaChallenge $challenges,
        private readonly MfaPolicy $policy,
        private readonly AuthService $auth,
        private readonly LoginResponder $responder,
        private readonly SessionRevoker $sessions,
    ) {}

    /** GET /auth/mfa: estado propio (activo, pendiente, códigos de recuperación restantes, bloqueo, obligatoriedad y frescura del segundo factor). */
    public function status(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => $this->statusPayload($request, $user)]);
    }

    /** POST /auth/mfa/setup: con la contraseña actual, genera el secreto (se muestra UNA vez). 409 si ya está activo. */
    public function setup(MfaPasswordRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $this->mfa->confirmPassword($user, $request->string('password')->toString(), 'enrolment');

        return response()->json(['data' => $this->mfa->begin($user)], 201);
    }

    /** POST /auth/mfa/confirm: verifica el primer código y activa; entrega los códigos de recuperación (una vez). */
    public function confirm(MfaCodeRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $this->mfa->confirmPassword($user, $request->string('password')->toString(), 'enrolment');
        $codes = $this->mfa->confirm($user, $request->string('code')->toString());
        $this->stepUp->mark($request);
        // Los tokens de inicio de sesión y las sesiones web anteriores se emitieron sin segundo factor: se revocan (el token o la sesión actual se conserva).
        $revoked = $this->revokeOtherLoginTokens($request, $user);
        $this->sessions->revokeOthers($user, $request);

        return response()->json(['data' => [
            'enabled' => true,
            'recovery_codes' => $codes,
            'recovery_codes_note' => 'Guárdelos ahora en un lugar seguro: no se vuelven a mostrar. Cada código sirve una sola vez.',
            'revoked_login_tokens' => $revoked,
            'revoked_other_sessions' => true,
        ]]);
    }

    /** POST /auth/mfa/challenge (sin sesión): completa el inicio de sesión con el desafío de /auth/login y el código. */
    public function challenge(MfaChallengeRequest $request): JsonResponse
    {
        $token = $request->string('challenge')->toString();
        // Candado sobre el desafío: verificar el código y consumirlo es atómico frente a peticiones paralelas con el mismo desafío (una sola sesión por desafío).
        $lock = $this->challenges->lock($token);
        if ($lock === null) {
            throw MfaException::challengeBusy();
        }
        try {
            $data = $this->challenges->peek($token);
            $user = $data !== null ? User::query()->find($data['user_id']) : null;
            if ($data === null || $user === null || ! $user->hasMfaEnabled()) {
                throw MfaException::challengeInvalid();
            }
            $method = $this->mfa->verify($user, $request->input('code'), $request->input('recovery_code'), 'login');
            $this->challenges->consume($token);
            $this->auth->completeLogin($user, true);

            $payload = $this->responder->payload($request, $user, $data['remember'], true) + ['mfa_method' => $method];
            if ($method === 'recovery') {
                $payload['recovery_codes_remaining'] = $this->mfa->status($user)['recovery_codes_remaining'];
            }
        } finally {
            $lock->release();
        }

        return response()->json(['data' => $payload]);
    }

    /** POST /auth/mfa/step-up: verifica el código y deja el segundo factor «fresco» (ventana `security.mfa.step_up_ttl_seconds`). */
    public function stepUp(MfaVerifyRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $method = $this->mfa->verify($user, $request->input('code'), $request->input('recovery_code'), 'step_up');
        $this->stepUp->mark($request);

        return response()->json(['data' => ['verified' => true, 'method' => $method, 'fresh_until' => $this->stepUp->freshUntil($request)?->toIso8601String()]]);
    }

    /** POST /auth/mfa/recovery-codes: códigos nuevos (los anteriores dejan de valer). Exige un código TOTP vigente. */
    public function regenerateRecoveryCodes(MfaCodeRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $this->mfa->confirmPassword($user, $request->string('password')->toString(), 'recovery_regenerate');
        $codes = $this->mfa->regenerateRecoveryCodes($user, $request->string('code')->toString());

        return response()->json(['data' => ['recovery_codes' => $codes, 'recovery_codes_note' => 'Guárdelos ahora: no se vuelven a mostrar y los anteriores ya no sirven.']]);
    }

    /** DELETE /auth/mfa: desactiva el segundo factor con contraseña y código. 409 si una política lo exige. */
    public function disable(MfaDisableRequest $request): JsonResponse
    {
        $user = $this->user($request);
        if (! $user->hasMfaEnabled()) {
            throw MfaException::notEnabled();
        }
        if ($this->policy->requiredFor($user)) {
            throw MfaException::requiredByPolicy();
        }
        $this->mfa->disable($user, $request->string('password')->toString(), $request->input('code'), $request->input('recovery_code'));
        $revoked = $this->revokeOtherLoginTokens($request, $user);
        $this->sessions->revokeOthers($user, $request);

        return response()->json(['data' => ['enabled' => false, 'revoked_login_tokens' => $revoked, 'revoked_other_sessions' => true]]);
    }

    /** @return array<string, mixed> */
    private function statusPayload(Request $request, User $user): array
    {
        return $this->mfa->status($user) + [
            'required_by_policy' => $this->policy->requiredFor($user),
            'step_up' => ['fresh' => $this->stepUp->isFresh($request), 'fresh_until' => $this->stepUp->freshUntil($request)?->toIso8601String(), 'ttl_seconds' => $this->stepUp->ttl()],
        ];
    }

    private function revokeOtherLoginTokens(Request $request, User $user): int
    {
        $current = $user->currentAccessToken();

        return $user->tokens()->whereNull('tenant_id')->where('name', 'not like', 'datasource:%')->when($current instanceof PersonalAccessToken && $current->exists, fn ($q) => $q->whereKeyNot($current->getKey()))->delete();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
