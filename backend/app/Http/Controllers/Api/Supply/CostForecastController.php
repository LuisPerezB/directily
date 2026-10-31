<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Supply;

use App\Http\Resources\Supply\SupplyRunResource;
use App\Models\CoreRun;
use App\Models\CostForecast;
use App\Services\Supply\SupplyRunService;
use App\Support\Results\JsonMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Pronóstico de costos unitarios de compra por producto y proveedor (E2-15). No pronostica cantidades. Compara una
 * referencia simple (último valor) con otros métodos en un holdout temporal y respeta la disponibilidad `known_at`.
 */
class CostForecastController extends SupplyController
{
    public function store(Request $request, SupplyRunService $service): JsonResponse
    {
        $data = $request->validate([
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'historical' => ['sometimes', 'boolean'],
            'null_known_at_policy' => ['sometimes', Rule::in(['strict', 'assume_valid_from'])],
        ]);
        $run = $service->request($this->tenant(), $request->user(), 'cost_forecast', array_filter($data, fn ($v) => $v !== null),
            $request->user()?->currentAccessToken() instanceof PersonalAccessToken ? 'api' : 'user');

        return (new SupplyRunResource($run))->response()->setStatusCode(202);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['run_id' => ['sometimes', 'integer'], 'product_id' => ['sometimes', 'integer'], 'supplier_id' => ['sometimes', 'integer'],
            'quality_status' => ['sometimes', 'string'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $run = $request->filled('run_id')
            ? CoreRun::query()->where('core', 'cost')->where('status', 'completed')->findOrFail((int) $request->input('run_id'))
            : CoreRun::query()->where('core', 'cost')->where('status', 'completed')->orderByDesc('is_current')->orderByDesc('id')->first();
        if ($run === null) {
            return response()->json(['data' => [], 'meta' => ['total' => 0],
                'envelope' => [
                    'tenant_id' => (int) $this->tenant()->id, 'run_id' => null, 'core' => 'cost', 'result_type' => 'forecast',
                    'quality_status' => 'insufficient_data', 'uncertainty_source' => 'unknown', 'assumptions' => [],
                    'limitations' => ['Aún no hay un pronóstico de costos completado. Solicítelo con POST /supply/cost-forecasts.'],
                ]]);
        }
        $page = CostForecast::query()->where('core_run_id', $run->id)->with(['product:id,sku,name,unit', 'supplier:id,code,name'])
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', (int) $request->input('product_id')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', (int) $request->input('supplier_id')))
            ->when($request->filled('quality_status'), fn ($q) => $q->where('quality_status', (string) $request->input('quality_status')))
            ->orderBy('product_id')->orderBy('supplier_id')->paginate($this->perPage($request, 50));

        return response()->json([
            'data' => $page->getCollection()->map(fn (CostForecast $c) => [
                'id' => $c->id, 'product_id' => $c->product_id,
                'product' => $c->product ? ['id' => $c->product->id, 'sku' => $c->product->sku, 'name' => $c->product->name] : null,
                'supplier_id' => $c->supplier_id,
                'supplier' => $c->supplier ? ['id' => $c->supplier->id, 'code' => $c->supplier->code, 'name' => $c->supplier->name] : null,
                'cost_source' => $c->cost_source, 'currency' => $c->currency, 'unit' => $c->unit, 'method' => $c->method,
                'reference_method' => $c->reference_method, 'quality_status' => $c->quality_status, 'n_obs' => $c->n_obs,
                'last_observed_on' => $c->last_observed_on?->toDateString(), 'last_observed_cost' => $c->last_observed_cost,
                'points' => $c->points, 'metrics' => JsonMap::of($c->metrics), 'availability' => JsonMap::of($c->availability), 'limitations' => $c->limitations,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'envelope' => $this->envelope($run, ['result_type' => 'forecast']),
        ]);
    }

    public function runs(Request $request): AnonymousResourceCollection
    {
        return SupplyRunResource::collection(CoreRun::query()->where('core', 'cost')->with('trainingJobs')->latest('id')->paginate($this->perPage($request, 20)));
    }
}
