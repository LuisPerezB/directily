<?php

declare(strict_types=1);

namespace App\Services\Forecasting\Exceptions;

/** El motor rechazó el token de autenticación interno. */
final class EngineAuthenticationException extends EngineException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
