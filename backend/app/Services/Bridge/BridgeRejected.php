<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Rechazo de una petición del dispositivo Bridge con su estado HTTP y un código estable (`code`) que el agente interpreta:
 * `device_unauthorized`, `device_revoked`, `device_signature_invalid`, `device_clock_skew`, `device_replay`, `checksum_mismatch`, etc.
 * El mensaje nunca incluye secretos ni detalles internos.
 */
final class BridgeRejected extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 422, public readonly array $context = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $response = response()->json(array_filter([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'context' => array_diff_key($this->context, ['retry_after' => 1]) ?: null,
        ], fn ($v) => $v !== null), $this->status);
        if (isset($this->context['retry_after'])) {
            $response->header('Retry-After', (string) (int) $this->context['retry_after']);
        }

        return $response;
    }
}
