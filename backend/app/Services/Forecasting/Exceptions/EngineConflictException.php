<?php

declare(strict_types=1);

namespace App\Services\Forecasting\Exceptions;

/** La ejecución ya está siendo procesada por otro proceso. */
final class EngineConflictException extends EngineException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
