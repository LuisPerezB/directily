<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Radar;

use App\Http\Controllers\Api\Analytics\AnalyticsController;
use App\Http\Controllers\Api\Analytics\ForecastController;
use App\Http\Requests\Analytics\ForecastListRequest;
use App\Http\Requests\Analytics\ForecastSeriesRequest;
use App\Http\Requests\Radar\DemandForecastsRequest;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;

/**
 * GET /demand/forecasts (V25-AGD-014): alias V2.2 de los pronósticos agregados. NO duplica controladores: según `level` delega en /forecasts/products (por defecto),
 * /forecasts/categories o /forecasts/series con la misma validación y el mismo cuerpo, y lo declara en `X-Equivalent-Of`.
 */
final class DemandForecastController extends AnalyticsController
{
    public function index(DemandForecastsRequest $request, ForecastController $forecasts): JsonResponse
    {
        [$method, $form, $path] = match ($request->validated('level') ?? 'product') {
            'series' => ['series', ForecastSeriesRequest::class, '/forecasts/series'],
            'category' => ['categories', ForecastListRequest::class, '/forecasts/categories'],
            default => ['products', ForecastListRequest::class, '/forecasts/products'],
        };
        $response = $forecasts->{$method}(app($form));
        $response = $response instanceof Responsable ? $response->toResponse(request()) : $response;

        return $response->header('X-Equivalent-Of', "/api/v1{$path}");
    }
}
