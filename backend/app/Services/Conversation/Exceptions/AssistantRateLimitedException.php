<?php

declare(strict_types=1);

namespace App\Services\Conversation\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Se superó la tasa por usuario o el tope diario por empresa. Se renderiza como 429. */
final class AssistantRateLimitedException extends RuntimeException
{
    public function __construct(public readonly string $scope, public readonly int $retryAfterSeconds)
    {
        parent::__construct($scope === 'daily'
            ? 'Se alcanzó el límite diario de consultas al asistente de su empresa.'
            : 'Demasiadas consultas al asistente. Espere un momento e intente de nuevo.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->scope === 'daily' ? 'assistant_quota_exceeded' : 'assistant_rate_limited',
            'retry_after' => $this->retryAfterSeconds,
        ], 429)->header('Retry-After', (string) max(1, $this->retryAfterSeconds));
    }
}
