<?php

declare(strict_types=1);

namespace App\Services\DataCore\Quality;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Liberar un registro de cuarentena revalidó los datos y siguen sin ser válidos: HTTP 422 con los hallazgos que quedan. */
final class QuarantineStillInvalidException extends RuntimeException
{
    /** @param list<array<string, mixed>> $findings */
    public function __construct(public readonly array $findings)
    {
        parent::__construct('El registro sigue sin ser válido con los mapeos vigentes: corrija el origen, agregue el mapeo de valores o descártelo.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'quarantine_still_invalid', 'findings' => $this->findings], 422);
    }
}
