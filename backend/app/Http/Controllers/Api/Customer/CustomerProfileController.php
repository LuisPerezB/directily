<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Customer;

use App\Models\Customer;
use App\Models\CustomerActivity;
use App\Models\CustomerPrediction;
use App\Models\CustomerProductRanking;
use App\Services\Customer\CustomerEnvelope;
use App\Services\Customer\CustomerRunService;
use App\Services\Customer\CustomerS05Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Perfil de un comprador: frecuencia, ranking de productos probables, riesgo de inactividad y cambio de comportamiento. */
class CustomerProfileController extends CustomerController
{
    /**
     * Cantidades de S05 de la predicción de la relación; sin predicción, todo nulo y sin ventana (nunca un valor inventado).
     *
     * @return array<string, mixed>
     */
    private static function s05(?CustomerPrediction $prediction): array
    {
        return $prediction === null
            ? CustomerS05Presenter::fields((object) []) + ['quality_flags' => []]
            : CustomerS05Presenter::fields($prediction) + ['quality_flags' => $prediction->quality_flags ?? []];
    }

    public function show(Request $request, int $id, CustomerRunService $service): JsonResponse
    {
        $tenant = $this->tenant();
        $customer = Customer::query()->with('segment')->findOrFail($id);
        $run = $service->latestPrediction();
        $horizons = $service->allowedHorizons($tenant);
        if ($request->filled('horizon')) {
            $horizons = [$this->horizon($request)];
        }
        $base = [
            'customer' => [
                'id' => (int) $customer->id, 'code' => $customer->external_code, 'type' => $customer->customer_type,
                'is_generic' => (bool) $customer->is_generic, 'segment' => $customer->segment?->name,
            ],
        ];
        if ($run === null) {
            return response()->json(['data' => $base + [
                'status' => 'no_prediction_run', 'activity' => null, 'rankings' => (object) [],
                'fallback' => 'Todavía no hay una predicción de comportamiento de compra.',
            ], 'envelope' => CustomerEnvelope::missing($tenant, 'forecast', 'Aún no hay una predicción de comportamiento de compra completada.')]);
        }
        if (! $request->filled('horizon')) {
            // Solo los horizontes que la corrida sí sirve: un horizonte del plan sin modelo no aparece como un ranking vacío.
            $horizons = array_values(array_intersect($horizons, array_map('intval', (array) $run->horizons)));
        }
        $activity = CustomerActivity::query()->where('core_run_id', $run->id)->where('customer_id', $customer->id)->first();
        $hasPredictions = CustomerPrediction::query()->where('core_run_id', $run->id)->where('customer_id', $customer->id)->exists();
        $rankings = [];
        $rankingSources = [];
        $ranked = [];
        foreach ($horizons as $h) {
            $rankingSources[(string) $h] = $run->model_versions[(string) $h]['ranking_source'] ?? null;
            $ranked[$h] = CustomerProductRanking::query()->where('core_run_id', $run->id)->where('customer_id', $customer->id)
                ->where('horizon_days', $h)->with('product:id,sku,name,unit')->orderBy('rank')->get();
        }
        // V2.5 · W3-D: cantidades de S05 (nº de pedidos, unidades, ventana de recompra, banderas) de la predicción del mismo comprador × producto × horizonte.
        // Solo de los productos del ranking y solo las columnas que se muestran (no se carga toda la fila de cada predicción del comprador).
        $productIds = collect($ranked)->flatten(1)->pluck('product_id')->unique()->values()->all();
        $s05 = $productIds === [] ? collect() : CustomerPrediction::query()
            ->select(['id', 'horizon_days', 'product_id', 'expected_orders', 'quantity_per_order', 'expected_units', 'window_p10_days', 'window_p50_days', 'window_p90_days', 'window_status', 'segment', 'quality_flags'])
            ->where('core_run_id', $run->id)->where('customer_id', $customer->id)->whereIn('horizon_days', $horizons)->whereIn('product_id', $productIds)
            ->get()->keyBy(fn (CustomerPrediction $p) => $p->horizon_days.':'.$p->product_id);
        foreach ($horizons as $h) {
            $rankings[(string) $h] = $ranked[$h]
                ->map(fn (CustomerProductRanking $r) => [
                    'rank' => (int) $r->rank,
                    'ranking_source' => $r->ranking_source, 'ranking_score' => $r->ranking_score,
                    'product' => ['id' => (int) $r->product_id, 'sku' => $r->product?->sku, 'name' => $r->product?->name, 'unit' => $r->product?->unit],
                    'purchase_probability' => $r->purchase_probability, 'expected_quantity' => $r->expected_quantity,
                    'expected_amount' => ['value' => $r->expected_amount, 'currency' => $r->currency, 'basis' => 'supuesto, no venta confirmada'],
                ] + self::s05($s05->get($h.':'.$r->product_id)))->all();
        }
        $status = 'predicted';
        $fallback = null;
        if ($customer->is_generic) {
            $status = 'no_individual_prediction';
            $fallback = 'Código genérico (consumidor final): no representa un comprador recurrente. Su demanda queda en el residual de la reconciliación.';
        } elseif ($activity === null) {
            $status = 'no_individual_prediction';
            $fallback = 'Sin historial de compras suficiente a la fecha: no se inventa una predicción individual. Su demanda queda en el residual de la reconciliación.';
        } elseif (! $hasPredictions) {
            $status = 'no_candidate_relations';
            $fallback = 'El comprador no tiene relaciones comprador×producto activas (con compras previas dentro de la recencia máxima).';
        }

        return response()->json(['data' => $base + [
            'status' => $status,
            'fallback' => $fallback,
            'as_of' => $run->as_of?->toDateString(),
            'activity' => $activity ? [
                'last_purchase_date' => $activity->last_purchase_date?->toDateString(),
                'days_since_last_purchase' => $activity->days_since_last_purchase,
                'n_purchase_days' => (int) $activity->n_purchase_days,
                'purchase_frequency_per_30d' => $activity->purchase_frequency_per_30d,
                'median_interval_days' => $activity->median_interval_days,
                'mean_interval_days' => $activity->mean_interval_days,
                'inactivity' => [
                    'status' => $activity->inactivity_status, 'risk' => $activity->inactivity_risk,
                    'interval_p75_days' => $activity->interval_p75_days, 'interval_p90_days' => $activity->interval_p90_days,
                    'n_intervals' => (int) $activity->n_intervals, 'definition' => CustomerEnvelope::DEFINITIONS['inactivity'],
                ],
                'behavior_change' => [
                    'status' => $activity->behavior_change, 'frequency_ratio' => $activity->frequency_ratio, 'p_value' => $activity->behavior_p_value,
                    'spend_ratio' => $activity->spend_ratio,
                ],
            ] : null,
            // Mapas horizonte → valor: objeto JSON (las claves son los horizontes servidos por la corrida: 7, 14, 15, 30, 90).
            'rankings' => (object) $rankings,
            'ranking_sources' => (object) $rankingSources,
            'ranking_source_note' => CustomerEnvelope::DEFINITIONS['ranking_source'],
        ], 'envelope' => CustomerEnvelope::forRun($run, $tenant), 'definitions' => CustomerEnvelope::DEFINITIONS]);
    }
}
