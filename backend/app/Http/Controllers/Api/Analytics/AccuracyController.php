<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Requests\Analytics\AccuracyRequest;
use App\Http\Requests\Analytics\ModelIndexRequest;
use App\Http\Resources\Analytics\ModelEvaluationResource;
use App\Http\Resources\Analytics\ModelRegistryResource;
use App\Models\ModelEvaluation;
use App\Models\ModelRegistry;
use App\Services\Analytics\AccuracyService;
use App\Services\Analytics\RunResolver;
use App\Services\Analytics\Support\ForecastNode;
use App\Services\Analytics\Support\ModelStatus;
use App\Support\Results\JsonMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Historial de precisión: backtesting, precisión realizada y registro de modelos. */
class AccuracyController extends AnalyticsController
{
    public function __construct(
        private readonly RunResolver $runs,
        private readonly AccuracyService $accuracy,
    ) {}

    public function summary(AccuracyRequest $request): JsonResponse
    {
        $run = $this->runs->resolveCompleted($request->intOrNull('run_id'));

        $data = $this->accuracy->summary($run);
        // Mapas de la etapa 2 (manifiesto, respaldo por horizonte, P50): objeto JSON aunque estén vacíos o con claves numéricas.
        foreach (['evaluation_manifest', 'horizon_support', 'p50'] as $map) {
            $data[$map] = JsonMap::of($data[$map] ?? null);
        }

        return response()->json(['data' => $data]);
    }

    public function comparison(AccuracyRequest $request): JsonResponse
    {
        $run = $this->runs->resolveCompleted($request->intOrNull('run_id'));
        $filters = $request->nodeFilters();
        $node = ForecastNode::fromFilters($filters['branch_id'], $filters['category_id'], $filters['product_id']);

        return response()->json(['data' => $this->accuracy->comparison($run, $node)]);
    }

    public function evaluations(AccuracyRequest $request): AnonymousResourceCollection
    {
        $run = $this->runs->resolve($request->intOrNull('run_id'));
        $rows = ModelEvaluation::query()
            ->where('forecast_run_id', $run->id)
            ->when($request->validated('evaluation_type'), fn ($q, $v) => $q->where('evaluation_type', $v))
            ->when($request->validated('level'), fn ($q, $v) => $q->where('level', $v))
            ->orderBy('level')->orderBy('level_key')->orderBy('wape')
            ->paginate($this->perPage($request, 50));

        return ModelEvaluationResource::collection($rows);
    }

    public function models(ModelIndexRequest $request): AnonymousResourceCollection
    {
        $status = $request->validated('status') !== null ? ModelStatus::filter((string) $request->validated('status')) : null;
        $models = ModelRegistry::query()
            ->when($status, fn ($q) => $q->where('status', $status['status']))
            ->when($status['role'] ?? $request->validated('role'), fn ($q, $role) => $q->where('role', $role))
            ->when($request->validated('core'), fn ($q, $core) => $q->where('core', $core))
            ->when($request->validated('run_id'), fn ($q, $run) => $q->where('forecast_run_id', (int) $run))
            ->latest('id')
            ->paginate($this->perPage($request, 25));

        return ModelRegistryResource::collection($models);
    }
}
