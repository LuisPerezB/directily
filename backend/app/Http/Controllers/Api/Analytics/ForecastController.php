<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Requests\Analytics\ForecastListRequest;
use App\Http\Requests\Analytics\ForecastSeriesRequest;
use App\Models\ForecastRun;
use App\Services\Analytics\ForecastQuery;
use App\Services\Analytics\HistoryQuery;
use App\Services\Analytics\NodeLabeler;
use App\Services\Analytics\ProductForecastQuery;
use App\Services\Analytics\RunResolver;
use App\Services\Analytics\Support\ForecastNode;
use App\Services\Analytics\Support\Granularity;
use App\Services\Analytics\Support\SalesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/** Pronósticos por nodo de la jerarquía, por producto y por categoría. */
class ForecastController extends AnalyticsController
{
    public function __construct(
        private readonly RunResolver $runs,
        private readonly ForecastQuery $forecasts,
        private readonly HistoryQuery $history,
        private readonly ProductForecastQuery $products,
        private readonly NodeLabeler $labels,
    ) {}

    public function series(ForecastSeriesRequest $request): JsonResponse
    {
        $tenantId = (int) $this->tenant()->getKey();
        $run = $this->runs->resolveCompleted($request->intOrNull('run_id'));
        $filters = $request->nodeFilters();
        $node = ForecastNode::fromFilters($filters['branch_id'], $filters['category_id'], $filters['product_id']);
        $granularity = (string) ($request->validated('granularity') ?? Granularity::DAY);
        $horizon = $request->horizon($this->defaultHorizon());
        $historyDays = (int) ($request->validated('history_days') ?? 90);

        $cutoff = $run?->cutoff_date?->toDateString() ?? SalesQuery::lastSaleDate($tenantId);
        $history = [];
        if ($cutoff !== null) {
            $to = CarbonImmutable::parse($cutoff);
            $history = $this->history->series($tenantId, $node, $granularity, $to->subDays($historyDays - 1), $to);
        }

        $forecast = $run ? $this->forecasts->series($run, $node, $granularity, $horizon) : null;

        return response()->json(['data' => [
            'node' => ['key' => $node->key, 'level' => $node->level, 'label' => $this->labels->label($node)],
            'granularity' => $granularity,
            'horizon' => $horizon,
            'history' => $history,
            'forecast' => $forecast['points'] ?? [],
            'meta' => [
                'run_id' => $run?->id,
                'run_version' => $run?->version,
                'run_type' => $run?->run_type,
                'generated_at' => $run?->completed_at?->toIso8601String(),
                'cutoff_date' => $cutoff,
                'model_family' => $forecast['model_family'] ?? null,
                'models' => (object) ($forecast['models'] ?? []),
                'price_assumption' => 'último precio neto promedio observado (28 días)',
                'intervals_available' => (bool) ($forecast['intervals_available'] ?? false),
                'interval_levels' => [80, 95],
                'has_forecast' => $run !== null,
                'notes' => $this->notes($granularity, $forecast, $node),
            ],
        ]]);
    }

    public function products(ForecastListRequest $request): AnonymousResourceCollection|JsonResponse
    {
        $run = $this->runs->resolveCompleted($request->intOrNull('run_id'));
        $horizon = $request->horizon($this->defaultHorizon());
        if ($run === null) {
            return $this->emptyList($horizon);
        }
        $page = $this->products->products($run, $horizon, [
            'branch_id' => $request->intOrNull('branch_id'),
            'category_id' => $request->intOrNull('category_id'),
            'search' => $request->validated('search'),
        ], (string) ($request->validated('sort') ?? '-forecast_units'), $this->perPage($request), (int) ($request->validated('page') ?? 1));

        return JsonResource::collection($page)->additional([
            'run' => $this->runSummary($run),
            'horizon' => $horizon,
        ]);
    }

    public function categories(ForecastListRequest $request): JsonResponse
    {
        $run = $this->runs->resolveCompleted($request->intOrNull('run_id'));
        $horizon = $request->horizon($this->defaultHorizon());
        if ($run === null) {
            return $this->emptyList($horizon);
        }

        return response()->json([
            'data' => $this->products->categories($run, $horizon, [
                'branch_id' => $request->intOrNull('branch_id'),
                'search' => $request->validated('search'),
            ], (string) ($request->validated('sort') ?? '-forecast_units')),
            'run' => $this->runSummary($run),
            'horizon' => $horizon,
        ]);
    }

    private function emptyList(int $horizon): JsonResponse
    {
        return response()->json([
            'data' => [],
            'meta' => ['current_page' => 1, 'per_page' => 0, 'total' => 0, 'last_page' => 1],
            'run' => null,
            'horizon' => $horizon,
            'message' => 'Aún no hay un pronóstico generado para esta empresa.',
        ]);
    }

    /** @return array<string, mixed> */
    private function runSummary(ForecastRun $run): array
    {
        return [
            'id' => $run->id,
            'version' => $run->version,
            'generated_at' => $run->completed_at?->toIso8601String(),
            'cutoff_date' => $run->cutoff_date?->toDateString(),
        ];
    }

    /** @return list<string> */
    private function notes(string $granularity, ?array $forecast, ForecastNode $node): array
    {
        $notes = [];
        if ($forecast !== null && ! $forecast['intervals_available'] && $granularity !== Granularity::DAY) {
            $notes[] = 'Los rangos de incertidumbre están disponibles en la vista diaria; para semanas o meses se muestra solo el valor esperado.';
        }
        if (! $node->isItemLevel()) {
            $notes[] = 'En niveles agregados, "stockout_share" indica la proporción de productos sin existencias ese período.';
        }

        return $notes;
    }
}
