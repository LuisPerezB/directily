<?php

declare(strict_types=1);

namespace App\Services\Forecasting\Exceptions;

/** El motor no encontró la ejecución o la empresa indicada. */
final class EngineNotFoundException extends EngineException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
