<?php

declare(strict_types=1);

namespace App\Services\Forecasting\Exceptions;

/** Datos insuficientes para generar el pronóstico. */
final class InsufficientDataException extends EngineException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
