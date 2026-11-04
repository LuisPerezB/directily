<?php

/*
|--------------------------------------------------------------------------
| Seguridad Enterprise — /api/v1  (V2.5 · W6-A; ADR-V25-15; §15)
|--------------------------------------------------------------------------
| MFA TOTP (RFC 6238), tokens con alcance y caducidad, roles comerciales, política de seguridad de la empresa y revocación de dispositivos del Bridge.
| Contrato: docs/security/ENTERPRISE_SECURITY.md y docs/openapi/v2.yaml (bloque W6-A). [T] = requiere empresa activa.
|
| Segundo factor (sin empresa):
|   POST   auth/mfa/challenge      completa el inicio de sesión con el desafío de /auth/login y el código (sin sesión; limitada por intentos y bloqueada por fuerza bruta)
|   GET    auth/mfa                estado propio
|   POST   auth/mfa/setup          genera el secreto (se muestra una vez)
|   POST   auth/mfa/confirm        verifica el primer código, activa y entrega los códigos de recuperación (una vez)
|   POST   auth/mfa/step-up        verifica un código y deja el segundo factor fresco para las acciones sensibles
|   POST   auth/mfa/recovery-codes códigos de recuperación nuevos (exige un código vigente)
|   DELETE auth/mfa                desactiva con contraseña y código (409 si una política lo exige)
| Tokens propios (sin empresa): GET/POST auth/tokens, DELETE auth/tokens/{id}, POST auth/tokens/revoke-others.
| Plataforma (superadministrador): POST admin/users/{user}/mfa-reset.
| Empresa [T]: GET/DELETE tenant/tokens (users.manage); PUT tenant/users/{user}/commercial-role (users.manage); GET/PUT tenant/security-policy (settings.manage);
|   La revocación de dispositivos del Bridge es de W6-B (POST bridge/devices/{device}/revoke, `datasources.manage`): efecto inmediato en cada petición del agente y, por ser
|   `datasources.manage` una habilidad sensible, exige un segundo factor fresco a quien tiene MFA (probado en BridgeRevocationSecurityTest).
|   Las habilidades sensibles exigen además un segundo factor fresco a quien tiene MFA.
*/

use App\Http\Controllers\Api\Security\AdminMfaResetController;
use App\Http\Controllers\Api\Security\ApiTokenController;
use App\Http\Controllers\Api\Security\CommercialRoleController;
use App\Http\Controllers\Api\Security\MfaController;
use App\Http\Controllers\Api\Security\SecurityPolicyController;
use App\Http\Controllers\Api\Security\TenantTokenController;
use Illuminate\Support\Facades\Route;

Route::post('auth/mfa/challenge', [MfaController::class, 'challenge'])->middleware('throttle:mfa')->name('security.mfa.challenge');

// Rutas de CUENTA: exigen una sesión o un token de inicio de sesión completo (`account.full`); un token restringido, de conector o ligado a una empresa recibe 403 `token_scope_insufficient`.
Route::middleware(['auth:sanctum', 'account.full', 'throttle:api'])->name('security.')->group(function (): void {
    Route::get('auth/mfa', [MfaController::class, 'status'])->name('mfa.status');
    Route::post('auth/mfa/setup', [MfaController::class, 'setup'])->middleware('throttle:mfa')->name('mfa.setup');
    Route::post('auth/mfa/confirm', [MfaController::class, 'confirm'])->middleware('throttle:mfa')->name('mfa.confirm');
    Route::post('auth/mfa/step-up', [MfaController::class, 'stepUp'])->middleware('throttle:mfa')->name('mfa.step-up');
    Route::post('auth/mfa/recovery-codes', [MfaController::class, 'regenerateRecoveryCodes'])->middleware('throttle:mfa')->name('mfa.recovery-codes');
    Route::delete('auth/mfa', [MfaController::class, 'disable'])->middleware('throttle:mfa')->name('mfa.disable');

    Route::get('auth/tokens', [ApiTokenController::class, 'index'])->name('tokens.index');
    Route::post('auth/tokens', [ApiTokenController::class, 'store'])->middleware('mfa.step_up')->name('tokens.store');
    Route::post('auth/tokens/revoke-others', [ApiTokenController::class, 'revokeOthers'])->name('tokens.revoke-others');
    Route::delete('auth/tokens/{id}', [ApiTokenController::class, 'destroy'])->whereNumber('id')->name('tokens.destroy');

    // Plataforma: restablecer el segundo factor de una persona (motivo obligatorio; segundo factor fresco del superadministrador).
    Route::post('admin/users/{user}/mfa-reset', [AdminMfaResetController::class, 'store'])->middleware('superadmin')->whereNumber('user')->name('admin.mfa-reset');
});

Route::middleware(['auth:sanctum', 'throttle:api', 'tenant'])->name('security.')->group(function (): void {
    Route::middleware('tenant.ability:users.manage')->group(function (): void {
        Route::get('tenant/tokens', [TenantTokenController::class, 'index'])->name('tenant-tokens.index');
        Route::delete('tenant/tokens/{id}', [TenantTokenController::class, 'destroy'])->whereNumber('id')->name('tenant-tokens.destroy');
        Route::put('tenant/users/{user}/commercial-role', [CommercialRoleController::class, 'update'])->whereNumber('user')->name('commercial-role.update');
    });
    Route::get('tenant/security-policy', [SecurityPolicyController::class, 'show'])->middleware('tenant.ability:settings.manage')->name('policy.show');
    Route::put('tenant/security-policy', [SecurityPolicyController::class, 'update'])->middleware('tenant.ability:settings.manage')->name('policy.update');
});
