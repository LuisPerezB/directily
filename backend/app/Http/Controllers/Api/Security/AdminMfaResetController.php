<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Security\MfaResetRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\Mfa\MfaService;
use App\Services\Auth\SessionRevoker;
use Illuminate\Http\JsonResponse;

/**
 * Restablecimiento del segundo factor por la PLATAFORMA (superadministrador; V2.5 · W6-A). Es el único camino cuando se perdieron el dispositivo y los códigos de
 * recuperación. Lo hace la plataforma y no el administrador de una empresa porque el segundo factor es de la PERSONA, no de la empresa: una persona puede ser propietaria de
 * otra empresa, y un administrador no debe poder quitarle la protección allí. Exige motivo (verificación de identidad), segundo factor fresco del superadministrador (la
 * ruta de plataforma ya lo pide) y revoca los tokens de inicio de sesión de la persona. Queda en la auditoría de la plataforma.
 */
final class AdminMfaResetController extends Controller
{
    public function __construct(private readonly MfaService $mfa, private readonly AuditLogger $audit, private readonly SessionRevoker $sessions) {}

    /** POST /admin/users/{user}/mfa-reset */
    public function store(MfaResetRequest $request, int $user): JsonResponse
    {
        $target = User::query()->findOrFail($user);
        $this->mfa->clear($target);
        $revoked = $target->tokens()->whereNull('tenant_id')->where('name', 'not like', 'datasource:%')->delete();
        $this->sessions->revokeAll($target);   // y TODAS las sesiones web y cookies «recordarme» de la persona
        $this->audit->log('mfa.reset_by_platform', $target, ['reason' => $request->string('reason')->toString(), 'revoked_login_tokens' => $revoked], null, $request->user()->getKey());

        return response()->json(['data' => ['user_id' => $target->getKey(), 'mfa_enabled' => false, 'revoked_login_tokens' => $revoked, 'revoked_sessions' => true]]);
    }
}
