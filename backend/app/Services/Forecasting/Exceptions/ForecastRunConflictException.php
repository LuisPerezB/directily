<?php

declare(strict_types=1);

namespace App\Services\Forecasting\Exceptions;

use App\Models\ForecastRun;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Ya existe una ejecución en cola o en proceso para la empresa (HTTP 409). */
final class ForecastRunConflictException extends RuntimeException
{
    public function __construct(public readonly ForecastRun $activeRun)
    {
        parent::__construct('Ya hay un pronóstico en cola o en ejecución para esta empresa. Espere a que termine.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'forecast_run_in_progress',
            'active_run' => [
                'id' => $this->activeRun->id,
                'version' => $this->activeRun->version,
                'status' => $this->activeRun->status,
                'created_at' => $this->activeRun->created_at?->toIso8601String(),
            ],
        ], 409);
    }
}
