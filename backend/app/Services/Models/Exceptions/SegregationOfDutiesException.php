<?php

declare(strict_types=1);

namespace App\Services\Models\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Segregación de funciones (ADR-V25-07): quien publicó o disparó el entrenamiento de un modelo no puede aprobarlo ni
 * rechazarlo (HTTP 403, `code = approval_segregation_of_duties`). Es una decisión de autorización, no un conflicto de estado.
 */
final class SegregationOfDutiesException extends RuntimeException
{
    /** @param list<string> $roles funciones que impiden decidir (published_by, training_job.user_id, ...), sin identificadores */
    public function __construct(string $message, public readonly array $roles = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'approval_segregation_of_duties',
            'context' => ['roles' => $this->roles],
        ], 403);
    }
}
