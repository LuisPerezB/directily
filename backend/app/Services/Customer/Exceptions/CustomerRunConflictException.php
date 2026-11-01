<?php

declare(strict_types=1);

namespace App\Services\Customer\Exceptions;

use App\Models\CoreRun;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Ya existe una corrida de comportamiento de la misma operación en cola o en proceso (HTTP 409). */
final class CustomerRunConflictException extends RuntimeException
{
    public function __construct(public readonly CoreRun $activeRun)
    {
        parent::__construct('Ya hay una corrida de comportamiento de compra ('.$activeRun->operation.') en cola o en ejecución. Espere a que termine.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'customer_run_in_progress',
            'active_run' => [
                'id' => $this->activeRun->id,
                'operation' => $this->activeRun->operation,
                'version' => $this->activeRun->version,
                'status' => $this->activeRun->status,
                'created_at' => $this->activeRun->created_at?->toIso8601String(),
            ],
        ], 409);
    }
}
