<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Requests\Analytics\AccuracyRequest;
use App\Http\Resources\Analytics\ModelEvaluationResource;
use App\Http\Resources\Analytics\ModelRegistryResource;
use App\Models\ModelEvaluation;
use App\Models\ModelRegistry;
use App\Services\Analytics\AccuracyService;
use App\Services\Analytics\RunResolver;
use App\Services\Analytics\Support\ForecastNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return response()->json(['data' => $this->accuracy->summary($run)]);
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

    public function models(Request $request): AnonymousResourceCollection
    {
        $models = ModelRegistry::query()
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', (string) $v))
            ->latest('id')
            ->paginate($this->perPage($request, 25));

        return ModelRegistryResource::collection($models);
    }
}
