<?php

declare(strict_types=1);

namespace App\Services\Forecasting\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Error base al comunicarse con el motor predictivo.
 */
class EngineException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $errorCode = null,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** Indica si tiene sentido reintentar la operación más tarde. */
    public function isRetryable(): bool
    {
        return true;
    }
}
