<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Radar;

use App\Http\Controllers\Api\Customer\CustomerPredictionController;
use App\Http\Requests\Customer\CustomerPredictionsRequest;
use App\Http\Requests\Radar\CustomerHorizonRequest;
use App\Models\CoreRun;
use App\Models\Customer;
use App\Services\Customer\CustomerEnvelope;
use App\Services\Customer\CustomerRunService;
use App\Services\Radar\CustomerCommercialProfile;
use Illuminate\Http\JsonResponse;

/**
 * Fachadas V2.2 de la ficha del comprador (V25-AGD-010 a -013). `predictions` NO duplica lógica: delega en el controlador de /customer/predictions con el
 * comprador fijado; `demand`, `products` y `overview` leen lo persistido por el Customer Core y las ventas vigentes (CustomerCommercialProfile). Un comprador
 * de otra empresa responde 404. Sin PII innecesaria: el nombre sigue cifrado y no sale.
 */
final class CustomerFichaController extends RadarController
{
    /** GET /customers/{id}/predictions ≡ GET /customer/predictions?customer_id={id} (misma respuesta y envoltura). */
    public function predictions(int $id, CustomerPredictionController $predictions, CustomerRunService $runs): JsonResponse
    {
        $this->tenant();
        Customer::query()->findOrFail($id);   // 404: comprador de otra empresa o inexistente
        request()->merge(['customer_id' => $id]);
        $response = $predictions->index(app(CustomerPredictionsRequest::class), $runs);

        return $response->header('X-Equivalent-Of', "/api/v1/customer/predictions?customer_id={$id}");
    }

    public function demand(CustomerHorizonRequest $request, int $id, CustomerCommercialProfile $profile, CustomerRunService $runs): JsonResponse
    {
        $this->tenant();
        $customer = $profile->load($id);
        $horizon = $this->horizon($request);
        $run = $this->run($request, $runs);
        if ($run !== null && ! in_array($horizon, array_map('intval', (array) $run->horizons), true)) {
            return $this->horizonNotAvailable($run, $horizon);
        }

        return response()->json($profile->demand($customer, $run, $horizon) + ['definitions' => array_intersect_key(CustomerEnvelope::DEFINITIONS, array_flip(['committed_quantity', 'expected_quantity', 'expected_amount', 'expected_orders', 'expected_units']))]);
    }

    public function products(CustomerHorizonRequest $request, int $id, CustomerCommercialProfile $profile, CustomerRunService $runs): JsonResponse
    {
        $this->tenant();
        $customer = $profile->load($id);
        $horizon = $this->horizon($request);
        $run = $this->run($request, $runs);
        if ($run !== null && ! in_array($horizon, array_map('intval', (array) $run->horizons), true) && $request->validated('kind') === 'probable') {
            return $this->horizonNotAvailable($run, $horizon);
        }

        return response()->json([
            'data' => ['customer' => ['id' => (int) $customer->id, 'code' => $customer->external_code], 'horizon_days' => $horizon]
                + $profile->products($customer, $run, $horizon, $request->validated('kind'), (int) ($request->validated('limit') ?? 10)),
            'envelope' => $run === null ? null : CustomerEnvelope::forRun($run, $this->tenant(), $horizon),
        ]);
    }

    /** GET /customers/{id}/overview: ficha comercial (compras, frecuencia, SKU habituales, evolución mensual, ventana futura y seguimiento). */
    public function overview(CustomerHorizonRequest $request, int $id, CustomerCommercialProfile $profile, CustomerRunService $runs): JsonResponse
    {
        $this->tenant();
        $customer = $profile->load($id);
        $horizon = $this->horizon($request);
        $run = $this->run($request, $runs);

        return response()->json($profile->overview($customer, $run, $horizon));
    }

    private function run(CustomerHorizonRequest $request, CustomerRunService $runs): ?CoreRun
    {
        return $request->filled('run_id')
            ? CoreRun::query()->where('core', 'customer')->where('operation', 'predict')->where('status', 'completed')->findOrFail((int) $request->integer('run_id'))
            : $runs->latestPrediction();
    }

    /** Abstención explícita (ADR-V25-02), igual que /customer/predictions: nunca una cifra inventada. */
    private function horizonNotAvailable(CoreRun $run, int $horizon): JsonResponse
    {
        $reasons = (array) ($run->summary['horizon_support'][(string) $horizon]['reasons'] ?? []);

        return response()->json([
            'message' => "La corrida {$run->id} no tiene predicciones a {$horizon} días (abstención por datos insuficientes o modelo ausente).",
            'code' => 'horizon_not_available', 'available_horizons' => $run->horizons, 'quality_status' => 'insufficient_data', 'horizon' => $horizon,
            'reason_code' => $reasons[0]['reason_code'] ?? 'unknown_legacy_run', 'reasons' => array_values($reasons),
        ], 422);
    }
}
