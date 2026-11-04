<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Radar;

use App\Http\Requests\Radar\RadarAgendaRequest;
use App\Services\Radar\AgendaStatusMap;
use App\Services\Radar\PredictiveSalesAgenda;
use App\Services\Radar\RadarPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/** GET /radar/agenda (V25-AGD-004): agenda de una fecha agrupada por cliente (un cliente no se duplica por SKU), con estado heredado y próxima acción. */
final class RadarAgendaController extends RadarController
{
    public function index(RadarAgendaRequest $request, PredictiveSalesAgenda $agenda): JsonResponse
    {
        $filters = $this->filters($request);
        $result = $agenda->agenda($filters, $request->validated('date'), $this->perPage($request), (int) ($request->validated('page') ?? 1));
        $result['paginator']->setCollection(collect($result['groups']));

        return JsonResource::collection($result['paginator'])->additional([
            'date' => $result['date'],
            'envelope' => $this->envelope($agenda->envelopeRun(), $filters->horizon),
            'label' => RadarPolicy::ATTRIBUTION_TEXT,
            'definitions' => RadarPolicy::DEFINITIONS,
            'status_map' => ['version' => AgendaStatusMap::VERSION, 'table' => AgendaStatusMap::table()],
        ])->response();
    }
}
