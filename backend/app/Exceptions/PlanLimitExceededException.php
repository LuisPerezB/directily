<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Se lanza cuando una operación excede el plan contratado. Se renderiza como HTTP 402. */
final class PlanLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $limit,
        public readonly int|float|null $current,
        public readonly int|float|null $max,
        ?string $message = null,
    ) {
        parent::__construct($message ?? "Se alcanzó el límite del plan ({$limit}).");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'plan_limit_exceeded',
            'limit' => $this->limit,
            'current' => $this->current,
            'max' => $this->max,
        ], 402);
    }
}
