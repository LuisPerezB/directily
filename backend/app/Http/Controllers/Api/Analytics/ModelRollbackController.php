<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Requests\Analytics\RollbackModelRequest;
use App\Services\Forecasting\DemandRollbackService;
use Illuminate\Http\JsonResponse;

/** Reversión del modelo servido de la demanda a una ejecución de producción anterior. */
class ModelRollbackController extends AnalyticsController
{
    public function demand(RollbackModelRequest $request, DemandRollbackService $service): JsonResponse
    {
        $result = $service->rollback($this->tenant(), $request->user(), (int) $request->validated('run_id'));

        return response()->json(['data' => $result]);
    }
}
