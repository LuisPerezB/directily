<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Requests\Analytics\DashboardRequest;
use App\Http\Requests\Analytics\RecommendationIndexRequest;
use App\Http\Requests\Analytics\UpdateRecommendationRequest;
use App\Http\Resources\Analytics\RecommendationResource;
use App\Models\Recommendation;
use App\Services\Analytics\InventoryQuery;
use App\Services\Analytics\RunResolver;
use App\Services\Forecasting\Support\CoreServices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Inventario y recomendaciones de abastecimiento. Actualizar el estado de una
 * recomendación solo deja registro: Predictily no ejecuta pedidos ni compromete fondos.
 */
class InventoryController extends AnalyticsController
{
    public function __construct(
        private readonly RunResolver $runs,
        private readonly InventoryQuery $inventory,
    ) {
    }

    public function recommendations(RecommendationIndexRequest $request): AnonymousResourceCollection|JsonResponse
    {
        $run = $this->runs->resolveCompleted($request->intOrNull('run_id'));
        if ($run === null) {
            return response()->json([
                'data' => [],
                'meta' => ['current_page' => 1, 'per_page' => 0, 'total' => 0, 'last_page' => 1],
                'run' => null,
                'message' => 'Aún no hay un pronóstico generado; las recomendaciones se calculan junto con él.',
            ]);
        }

        $page = $this->inventory->recommendations(
            $run,
            $request->filters(),
            (string) ($request->validated('sort') ?? '-stockout_probability'),
            $this->perPage($request),
            (int) ($request->validated('page') ?? 1),
        );

        return RecommendationResource::collection($page)->additional([
            'run' => ['id' => $run->id, 'version' => $run->version, 'cutoff_date' => $run->cutoff_date?->toDateString()],
            'disclaimer' => 'Sugerencias calculadas con el pronóstico, el inventario y los plazos de abastecimiento registrados. Predictily no realiza pedidos.',
        ]);
    }

    public function update(UpdateRecommendationRequest $request, int $id): RecommendationResource
    {
        $recommendation = Recommendation::query()->findOrFail($id);
        $status = (string) $request->validated('status');
        $previous = $recommendation->status;

        $recommendation->forceFill([
            'status' => $status,
            'acknowledged_by' => $status === 'open' ? null : $request->user()?->getKey(),
            'acknowledged_at' => $status === 'open' ? null : now(),
        ])->save();

        CoreServices::audit('recommendation.status_changed', $recommendation, ['from' => $previous, 'to' => $status]);

        return new RecommendationResource($recommendation->load(['product:id,sku,name,category_id,unit', 'branch:id,code,name']));
    }

    public function summary(DashboardRequest $request): JsonResponse
    {
        $run = $this->runs->current();

        return response()->json([
            'data' => $this->inventory->summary((int) $this->tenant()->getKey(), $run, $request->intOrNull('branch_id')),
        ]);
    }
}
