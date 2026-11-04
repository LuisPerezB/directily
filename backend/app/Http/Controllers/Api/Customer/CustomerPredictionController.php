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
        // V2.5 · W3-D (aditivo): S05.
        'expected_orders' => 'expected_orders', 'expected_units' => 'expected_units',
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
            // Abstención explícita (ADR-V25-02): estado `insufficient_data` + motivo en `reason_code`; nunca un número fabricado.
            // El motivo sale de `summary.horizon_support` (corridas posteriores a W2-E). Una corrida anterior no lo guardó: no se adivina la causa
            // (no se asume «sin modelo»); se informa `unknown_legacy_run`.
            $reasons = (array) ($run->summary['horizon_support'][(string) $horizon]['reasons'] ?? []);

            return response()->json([
                'message' => "La corrida {$run->id} no tiene predicciones a {$horizon} días (abstención por datos insuficientes o modelo ausente).",
                'code' => 'horizon_not_available',
                'available_horizons' => $run->horizons,
                'quality_status' => 'insufficient_data',
                'horizon' => $horizon,
                'reason_code' => $reasons[0]['reason_code'] ?? 'unknown_legacy_run',
                'reasons' => array_values($reasons),
            ], 422);
        }
        $column = self::ORDER[$request->query('order_by', 'probability')] ?? 'purchase_probability';
        $direction = $request->query('direction', 'desc') === 'asc' ? 'asc' : 'desc';
        $page = CustomerPrediction::query()
            ->where('core_run_id', $run->id)->where('horizon_days', $horizon)
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', (int) $request->integer('customer_id')))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', (int) $request->integer('product_id')))
            ->when($request->filled('min_probability'), fn ($q) => $q->where('purchase_probability', '>=', (float) $request->input('min_probability')))
            ->when($request->filled('window_status'), fn ($q) => $q->where('window_status', (string) $request->input('window_status')))
            ->when($request->filled('segment'), fn ($q) => $q->where('segment', (string) $request->input('segment')))
            ->with(['customer:id,external_code', 'product:id,sku,name,unit'])
            // Las columnas nuevas de S05 son nulas en filas sin estimación: nunca encabezan un orden descendente.
            ->when(in_array($column, ['expected_orders', 'expected_units'], true),
                fn ($q) => $q->orderByRaw("{$column} {$direction} NULLS LAST"),
                fn ($q) => $q->orderBy($column, $direction))
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return CustomerPredictionResource::collection($page)->additional([
            'envelope' => CustomerEnvelope::forRun($run, $tenant, $horizon),
            'ranking_source' => $run->model_versions[(string) $horizon]['ranking_source'] ?? null,
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
