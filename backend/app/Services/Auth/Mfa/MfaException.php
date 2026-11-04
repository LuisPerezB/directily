<?php

declare(strict_types=1);

namespace App\Services\Auth\Mfa;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Errores del segundo factor, con código estable y HTTP propio (V2.5 · W6-A):
 * 422 `mfa_invalid_code` (código o código de recuperación incorrecto; mensaje genérico), 423 `mfa_locked` (bloqueo por fuerza bruta, con `retry_after`),
 * 403 `mfa_step_up_required` (acción sensible sin segundo factor reciente) y `mfa_enrollment_required` (la política exige MFA y aún no está activo),
 * 409 `mfa_already_enabled`, `mfa_not_enabled`, `mfa_not_pending`, `mfa_required_by_policy`, 422 `mfa_challenge_invalid`.
 * Ningún mensaje ni contexto incluye el secreto ni un código.
 */
final class MfaException extends RuntimeException
{
    /** @param array<string, mixed> $extra */
    private function __construct(string $message, public readonly string $errorCode, private readonly int $status, private readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public static function invalidCode(): self
    {
        return new self('El código de verificación no es válido.', 'mfa_invalid_code', 422);
    }

    public static function invalidPassword(): self
    {
        return new self('La contraseña no es correcta.', 'mfa_invalid_password', 422);
    }

    public static function challengeBusy(): self
    {
        return new self('Este inicio de sesión ya se está verificando en otra petición. Reintente.', 'mfa_challenge_busy', 409);
    }

    public static function locked(int $retryAfter): self
    {
        return new self('Demasiados intentos fallidos. Intente de nuevo más tarde.', 'mfa_locked', 423, ['retry_after' => max(1, $retryAfter)]);
    }

    public static function stepUpRequired(): self
    {
        return new self('Esta acción exige verificar su segundo factor de autenticación.', 'mfa_step_up_required', 403, ['mfa_step_up' => true]);
    }

    public static function enrollmentRequired(): self
    {
        return new self('La política de seguridad exige activar el segundo factor de autenticación antes de continuar.', 'mfa_enrollment_required', 403);
    }

    public static function alreadyEnabled(): self
    {
        return new self('El segundo factor ya está activo.', 'mfa_already_enabled', 409);
    }

    public static function notEnabled(): self
    {
        return new self('El segundo factor no está activo.', 'mfa_not_enabled', 409);
    }

    public static function notPending(): self
    {
        return new self('No hay una activación pendiente: inicie la configuración primero.', 'mfa_not_pending', 409);
    }

    public static function requiredByPolicy(): self
    {
        return new self('Una política de seguridad exige mantener activo el segundo factor.', 'mfa_required_by_policy', 409);
    }

    public static function challengeInvalid(): self
    {
        return new self('El desafío de inicio de sesión venció o no es válido. Inicie sesión de nuevo.', 'mfa_challenge_invalid', 422);
    }

    public function render(): JsonResponse
    {
        $response = response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode] + $this->extra, $this->status);
        if (isset($this->extra['retry_after'])) {
            $response->header('Retry-After', (string) $this->extra['retry_after']);
        }

        return $response;
    }
}
