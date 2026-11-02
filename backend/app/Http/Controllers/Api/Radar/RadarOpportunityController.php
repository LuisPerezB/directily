<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Radar;

use App\Http\Requests\Radar\MaterializeOpportunitiesRequest;
use App\Http\Requests\Radar\RadarFeedbackRequest;
use App\Http\Requests\Radar\RadarFiltersRequest;
use App\Services\Forecasting\Support\CoreServices;
use App\Services\Radar\AgendaFeedbackService;
use App\Services\Radar\AgendaStatusMap;
use App\Services\Radar\CommercialOpportunityService;
use App\Services\Radar\OpportunityPresenter;
use App\Services\Radar\PredictiveSalesAgenda;
use App\Services\Radar\RadarPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Oportunidades del Revenue Radar (V25-AGD-003, -005, -006 y -020): lista con filtros y paginación, detalle con su historial de seguimiento, seguimiento
 * (feedback) auditable e idempotente, y la propuesta de oportunidades al Decision Engine. Predictily no envía mensajes: todo contacto es humano.
 */
final class RadarOpportunityController extends RadarController
{
    public function index(RadarFiltersRequest $request, PredictiveSalesAgenda $agenda): JsonResponse
    {
        $filters = $this->filters($request);
        $page = $agenda->opportunities($filters, $this->perPage($request), (int) ($request->validated('page') ?? 1));
        $runId = collect($page->items())->pluck('source_run_id')->filter()->max();
        $run = $agenda->envelopeRun($runId === null ? null : (int) $runId);

        return JsonResource::collection($page)->additional([
            'envelope' => $this->envelope($run, $filters->horizon),
            'label' => RadarPolicy::ATTRIBUTION_TEXT,
            'definitions' => RadarPolicy::DEFINITIONS,
        ])->response();
    }

    public function show(Request $request, int $id, PredictiveSalesAgenda $agenda): JsonResponse
    {
        $this->tenant();
        $detail = $agenda->detail($id, $this->canSeeControl($request));
        abort_if($detail === null, 404);

        return response()->json([
            'data' => $detail, 'label' => RadarPolicy::ATTRIBUTION_TEXT, 'definitions' => RadarPolicy::DEFINITIONS,
            'status_map' => ['version' => AgendaStatusMap::VERSION, 'table' => AgendaStatusMap::table()],
        ]);
    }

    /** POST /radar/opportunities/{id}/feedback: 201 la primera vez; 200 con `replayed` si es la misma petición (misma clave de idempotencia). */
    public function feedback(RadarFeedbackRequest $request, int $id, AgendaFeedbackService $feedback, PredictiveSalesAgenda $agenda, OpportunityPresenter $presenter): JsonResponse
    {
        $this->tenant();
        $result = $feedback->apply(
            $id, $request->user(), (string) $request->validated('state'), $request->validated('note'),
            (string) $request->validated('idempotency_key'), $request->validated('snoozed_until'),
        );
        $response = response()->json([
            'data' => [
                'opportunity' => $agenda->detail($id, $this->canSeeControl($request)),
                'event' => $presenter->event($result['event'], $result['workflow_sync']),
                'replayed' => $result['replayed'],
            ],
            'status_map' => ['version' => AgendaStatusMap::VERSION],
        ], $result['replayed'] ? 200 : 201);

        return $result['replayed'] ? $response->header('Idempotent-Replayed', 'true') : $response;
    }

    /** POST /radar/opportunities/materialize: propone al Decision Engine las oportunidades de la predicción vigente (idempotente; `forecast.run`). */
    public function materialize(MaterializeOpportunitiesRequest $request, CommercialOpportunityService $service): JsonResponse
    {
        $this->tenant();
        $horizons = $request->validated('horizons');
        // Una persona dispara la propuesta: queda como proponente de lo que se cree (segregación de funciones: no podrá aprobarlo). El comando programado propone como sistema.
        $result = $service->propose(null, $horizons === null ? null : array_map('intval', $horizons), (int) $request->user()->getKey());
        CoreServices::audit('radar.materialized', null, [
            'run_id' => $result->runId, 'horizons' => $result->horizons, 'candidates' => $result->candidates, 'created' => $result->created, 'existing' => $result->existing, 'failed' => $result->failed,
        ]);

        return response()->json(['data' => $result->toArray(), 'definitions' => RadarPolicy::DEFINITIONS]);
    }
}
