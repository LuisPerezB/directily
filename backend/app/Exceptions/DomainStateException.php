<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Operación no permitida en el estado actual del recurso (HTTP 409). */
class DomainStateException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'invalid_state', public readonly array $context = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'context' => $this->context ?: null,
        ], fn ($v) => $v !== null), 409);
    }
}
