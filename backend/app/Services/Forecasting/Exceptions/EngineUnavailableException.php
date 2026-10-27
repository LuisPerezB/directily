<?php

declare(strict_types=1);

namespace App\Services\Forecasting\Exceptions;

/** El motor predictivo no está disponible (error de conexión o timeout). */
final class EngineUnavailableException extends EngineException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
