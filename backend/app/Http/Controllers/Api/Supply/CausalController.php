<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Supply;

use App\Services\Forecasting\Exceptions\EngineException;
use App\Services\Forecasting\Exceptions\InsufficientDataException;
use App\Services\Forecasting\Support\CoreServices;
use App\Services\Supply\SupplyEngineClient;
use App\Support\Results\JsonMap;
use App\Support\Results\ResultEnvelope;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contrato causal (E2-16, ADR-V2-11): verifica los requisitos para publicar un efecto causal del precio sobre la demanda.
 * Ningún estimador está habilitado: la respuesta es `insufficient_evidence` y la simulación condicionada sigue disponible.
 */
class CausalController extends SupplyController
{
    public function check(Request $request, SupplyEngineClient $engine): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer', TenantRule::exists('products')],
            'branch_id' => ['nullable', 'integer', TenantRule::exists('branches')],
            'as_of' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'treatment' => ['nullable', 'string', 'max:100'],
            'outcome' => ['nullable', 'string', 'max:100'],
            'unit_of_analysis' => ['nullable', 'string', 'max:100'],
            'estimand' => ['nullable', 'string', 'max:100'],
            'horizon_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'population' => ['nullable', 'string', 'max:200'],
            'price_change_pct' => ['nullable', 'numeric', 'min:-0.95', 'max:5'],
            'confounders_controlled' => ['sometimes', 'array'],
            'confounders_controlled.*' => ['string', 'max:60'],
            'unobserved_confounders_acknowledged' => ['sometimes', 'array'],
            'unobserved_confounders_acknowledged.*' => ['string', 'max:60'],
            'identification_assumptions_acknowledged' => ['sometimes', 'array'],
            'identification_assumptions_acknowledged.*' => ['string', 'max:60'],
        ]);
        try {
            $result = $engine->causalCheck((int) $this->tenant()->id, array_filter($data, fn ($v) => $v !== null));
        } catch (InsufficientDataException $e) {
            abort(422, $e->getMessage());
        } catch (EngineException $e) {
            abort(503, 'El motor no pudo verificar los requisitos causales: '.$e->getMessage());
        }
        CoreServices::audit('supply.causal_checked', null, ['verdict' => $result['data']['verdict'] ?? null]);
        $payload = $result['data'] ?? null;
        unset($result['data']);

        // La envoltura viene del motor ya decodificada: sus mapas (model_version, target_definition) se emiten como objeto.
        return response()->json(['data' => JsonMap::of($payload), 'envelope' => ResultEnvelope::normalize($result)]);
    }
}
