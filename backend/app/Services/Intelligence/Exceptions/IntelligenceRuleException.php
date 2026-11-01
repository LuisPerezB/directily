<?php

declare(strict_types=1);

namespace App\Services\Intelligence\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Regla de negocio de Factor Intelligence incumplida. `status` 409 = estado incompatible, 422 = datos que no cumplen la regla,
 * 403 = segregación de funciones. `errorCode` es estable y lo consumen la API y las pruebas.
 */
final class IntelligenceRuleException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 422, public readonly array $context = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'context' => $this->context ?: null,
        ], fn ($v) => $v !== null), $this->status);
    }
}
