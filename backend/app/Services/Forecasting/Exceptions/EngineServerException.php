<?php

declare(strict_types=1);

namespace App\Services\Forecasting\Exceptions;

/** Error interno del motor predictivo. */
final class EngineServerException extends EngineException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
