<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Radar;

use App\Http\Controllers\Api\Analytics\AnalyticsController;
use App\Http\Requests\Radar\SupplyReadinessRequest;
use App\Services\Forecasting\Support\CoreServices;
use App\Services\Radar\SupplyReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/** GET /supply/readiness (V25-AGD-015): disponibilidad al corte, cobertura y si el horizonte es atendible, por producto. Solo lectura (`forecast.view`). */
final class SupplyReadinessController extends AnalyticsController
{
    public function index(SupplyReadinessRequest $request, SupplyReadiness $readiness): JsonResponse
    {
        $tenant = $this->tenant();
        $horizon = $request->validated('horizon') !== null ? (int) $request->validated('horizon') : $this->defaultHorizon();
        CoreServices::assertHorizon($tenant, $horizon);   // 402: un horizonte fuera del plan no se revela
        $asOf = $request->validated('as_of') ?? CarbonImmutable::now($tenant->timezone ?: 'America/Guayaquil')->toDateString();
        $result = $readiness->readiness(
            ['product_id' => $request->intOrNull('product_id'), 'category_id' => $request->intOrNull('category_id')],
            $horizon, $asOf, $this->perPage($request), (int) ($request->validated('page') ?? 1),
        );

        return JsonResource::collection($result['paginator'])->additional($result['meta'])->response();
    }
}
