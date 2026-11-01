<?php

declare(strict_types=1);

namespace App\Services\Supply\Exceptions;

use App\Models\CoreRun;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Ya existe una corrida del mismo core y operación en cola o en proceso (HTTP 409). */
final class SupplyRunConflictException extends RuntimeException
{
    public function __construct(public readonly CoreRun $activeRun)
    {
        parent::__construct('Ya hay una corrida de '.$activeRun->core.'/'.$activeRun->operation.' en cola o en ejecución. Espere a que termine.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'supply_run_in_progress',
            'active_run' => [
                'id' => $this->activeRun->id,
                'core' => $this->activeRun->core,
                'operation' => $this->activeRun->operation,
                'version' => $this->activeRun->version,
                'status' => $this->activeRun->status,
                'created_at' => $this->activeRun->created_at?->toIso8601String(),
            ],
        ], 409);
    }
}
