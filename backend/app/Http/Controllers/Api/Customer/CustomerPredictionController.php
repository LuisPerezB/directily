<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Http\Requests\Customer\CustomerPredictionsRequest;
use App\Http\Resources\Customer\CustomerPredictionResource;
use App\Models\CoreRun;
use App\Models\CustomerPrediction;
use App\Services\Customer\CustomerEnvelope;
use App\Services\Customer\CustomerRunService;
use Illuminate\Http\JsonResponse;

/** Predicciones de compra por comprador × producto (corrida de predicción vigente o la indicada). */
class CustomerPredictionController extends CustomerController
{
    private const ORDER = [
        'probability' => 'purchase_probability', 'expected_quantity' => 'expected_quantity',
        'expected_amount' => 'expected_amount', 'quantity_if_purchase' => 'quantity_if_purchase',
    ];

    public function index(CustomerPredictionsRequest $request, CustomerRunService $service): JsonResponse
    {
        $tenant = $this->tenant();
        $horizon = $this->horizon($request);
        $run = $request->filled('run_id')
            ? CoreRun::query()->where('core', 'customer')->where('operation', 'predict')->where('status', 'completed')->findOrFail((int) $request->integer('run_id'))
            : $service->latestPrediction();
        if ($run === null) {
            return $this->empty($tenant, 'Aún no hay una predicción de comportamiento de compra completada. Entrene y prediga desde POST /customer/runs.');
        }
        if (! in_array($horizon, array_map('intval', (array) $run->horizons), true)) {
            return response()->json([
                'message' => "La corrida {$run->id} no tiene predicciones a {$horizon} días (abstención por datos insuficientes o modelo ausente).",
                'code' => 'horizon_not_available',
                'available_horizons' => $run->horizons,
            ], 422);
        }
        $column = self::ORDER[$request->query('order_by', 'probability')] ?? 'purchase_probability';
        $direction = $request->query('direction', 'desc') === 'asc' ? 'asc' : 'desc';
        $page = CustomerPrediction::query()
            ->where('core_run_id', $run->id)->where('horizon_days', $horizon)
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', (int) $request->integer('customer_id')))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', (int) $request->integer('product_id')))
            ->when($request->filled('min_probability'), fn ($q) => $q->where('purchase_probability', '>=', (float) $request->input('min_probability')))
            ->with(['customer:id,external_code', 'product:id,sku,name,unit'])
            ->orderBy($column, $direction)->orderBy('id')
            ->paginate($this->perPage($request));

        return CustomerPredictionResource::collection($page)->additional([
            'envelope' => CustomerEnvelope::forRun($run, $tenant, $horizon),
            'definitions' => CustomerEnvelope::DEFINITIONS,
        ])->response();
    }

    private function empty($tenant, string $reason): JsonResponse
    {
        return response()->json([
            'data' => [], 'meta' => ['current_page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1],
            'envelope' => CustomerEnvelope::missing($tenant, 'forecast', $reason), 'definitions' => CustomerEnvelope::DEFINITIONS,
        ]);
    }
}
