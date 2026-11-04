<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Radar;

use App\Http\Requests\Radar\RadarFiltersRequest;
use App\Services\Radar\PredictiveSalesAgenda;
use App\Services\Radar\RadarPolicy;
use Illuminate\Http\JsonResponse;

/** GET /radar/summary (V25-AGD-002): oportunidades y valor potencial por horizonte, calidad y fecha de corte. Solo lectura (`forecast.view`). */
final class RadarSummaryController extends RadarController
{
    public function show(RadarFiltersRequest $request, PredictiveSalesAgenda $agenda): JsonResponse
    {
        $filters = $this->filters($request);
        $summary = $agenda->summary($filters);
        $latest = $agenda->envelopeRun();
        $run = $agenda->envelopeRun($summary['run_id']) ?? $latest;
        $pending = $summary['totals']['opportunities'] === 0 && $latest !== null;

        return response()->json([
            'data' => $summary,
            'envelope' => $this->envelope($run, $filters->horizon),
            'definitions' => RadarPolicy::DEFINITIONS,
            'message' => $pending
                ? 'Hay una predicción vigente pero aún no hay oportunidades propuestas: genérelas con POST /radar/opportunities/materialize o con predictily:radar-materialize.'
                : null,
        ]);
    }
}
