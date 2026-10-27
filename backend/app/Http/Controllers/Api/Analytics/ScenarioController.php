<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Requests\Analytics\SimulateScenarioRequest;
use App\Http\Requests\Analytics\StoreScenarioRequest;
use App\Http\Resources\Analytics\ScenarioResource;
use App\Models\Scenario;
use App\Services\Forecasting\Support\CoreServices;
use App\Services\Scenarios\ScenarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** Simulador de escenarios (cálculo determinista sobre supuestos explícitos). */
class ScenarioController extends AnalyticsController
{
    public function __construct(private readonly ScenarioService $scenarios)
    {
    }

    public function simulate(SimulateScenarioRequest $request): JsonResponse
    {
        $this->assertFeature();

        return response()->json(['data' => $this->scenarios->simulate(
            (int) $request->validated('horizon_days'),
            $request->scenarioFilters(),
            $request->assumptions(),
            $request->intOrNull('run_id'),
        )]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return ScenarioResource::collection(
            Scenario::query()->latest('id')->paginate($this->perPage($request, 20)),
        );
    }

    public function store(StoreScenarioRequest $request): JsonResponse
    {
        $this->assertFeature();
        $horizon = (int) $request->validated('horizon_days');
        $filters = $request->scenarioFilters();
        $assumptions = $request->assumptions();
        $results = $this->scenarios->simulate($horizon, $filters, $assumptions, $request->intOrNull('run_id'));

        $scenario = Scenario::query()->create([
            'user_id' => $request->user()?->getKey(),
            'forecast_run_id' => $results['base_run']['id'] ?? null,
            'name' => (string) $request->validated('name'),
            'horizon_days' => $horizon,
            'filters' => $filters,
            'assumptions' => $assumptions,
            'results' => $results,
        ]);
        CoreServices::audit('scenario.saved', $scenario, ['horizon_days' => $horizon]);

        return (new ScenarioResource($scenario))->response()->setStatusCode(201);
    }

    public function show(int $id): ScenarioResource
    {
        return new ScenarioResource(Scenario::query()->findOrFail($id));
    }

    public function destroy(int $id): Response
    {
        $scenario = Scenario::query()->findOrFail($id);
        $scenario->delete();
        CoreServices::audit('scenario.deleted', null, ['scenario_id' => $id]);

        return response()->noContent();
    }

    private function assertFeature(): void
    {
        if (! CoreServices::planAllows($this->tenant(), 'scenarios')) {
            abort(response()->json([
                'message' => 'Su plan no incluye el simulador de escenarios.',
                'code' => 'plan_limit_exceeded',
                'limit' => 'scenarios',
            ], 402));
        }
    }
}
