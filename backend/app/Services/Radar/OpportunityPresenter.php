<?php

declare(strict_types=1);

namespace App\Services\Radar;

use App\Models\Customer;
use App\Models\CustomerActivity;
use App\Models\CustomerPrediction;
use App\Models\CustomerSegment;
use App\Models\DecisionEvent;
use App\Models\DecisionRecommendation;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Customer\CustomerS05Presenter;
use Illuminate\Support\Collection;

/**
 * Forma de una oportunidad en la API del Radar (V2.5 · W5-A). Convierte filas de `decision_recommendations` (Radar) en la respuesta pública cargando en BLOQUE
 * (sin N+1) lo que la rodea: comprador, producto, categoría, segmento, la predicción del Customer Core que la originó (misma corrida, comprador, producto y
 * horizonte), la ficha de actividad, el vendedor asignado y el último seguimiento de agenda.
 *
 * Reglas de honestidad: las cifras de la ventana de recompra salen solo con estado `estimated` u `overdue` (se reutiliza CustomerS05Presenter); el valor es
 * siempre «oportunidad potencial, atribución no demostrada» mientras el Decision Engine no registre evidencia de uplift; sin costo confiable la contribución
 * es null y el estado de costo lo dice; ningún dato del cliente más allá de su código (nunca el nombre, que sigue cifrado).
 */
final class OpportunityPresenter
{
    /** Entidad de las oportunidades de relación cliente × producto: su valor es un PRONÓSTICO. Las demás (a nivel de cliente) traen un importe observado. */
    public const PAIR_ENTITY = 'customer_product';

    /**
     * @param  Collection<int, DecisionRecommendation>  $recs
     * @return list<array<string, mixed>>
     */
    public function present(Collection $recs, bool $detail = false, bool $privileged = false): array
    {
        if ($recs->isEmpty()) {
            return [];
        }
        $customerIds = $recs->pluck('customer_id')->filter()->unique()->values()->all();
        $productIds = $recs->pluck('product_id')->filter()->unique()->values()->all();
        $customers = Customer::withTrashed()->whereIn('id', $customerIds)->get(['id', 'external_code', 'customer_type', 'segment_id', 'commercial_zone'])->keyBy('id');
        $segments = CustomerSegment::query()->whereIn('id', $customers->pluck('segment_id')->filter()->unique()->values()->all())->get(['id', 'code', 'name'])->keyBy('id');
        $products = Product::withTrashed()->whereIn('id', $productIds)->get(['id', 'sku', 'name', 'unit', 'category_id'])->keyBy('id');
        $categories = ProductCategory::query()->whereIn('id', $products->pluck('category_id')->filter()->unique()->values()->all())->get(['id', 'name'])->keyBy('id');
        $predictions = $this->predictions($recs, $customerIds, $productIds);
        $activities = $this->activities($recs, $customerIds);
        $feedback = $this->latestFeedback($recs);
        $users = User::query()->whereIn('id', $recs->pluck('assigned_user_id')->filter()->unique()->values()->all())->get(['id', 'name'])->keyBy('id');
        $history = $detail ? $this->history($recs) : collect();
        $syncs = $detail ? $this->syncs($recs) : collect();

        return $recs->map(function (DecisionRecommendation $r) use ($customers, $segments, $products, $categories, $predictions, $activities, $feedback, $users, $history, $syncs, $detail, $privileged) {
            $customer = $customers->get($r->customer_id);
            $segment = $customer?->segment_id !== null ? $segments->get($customer->segment_id) : null;
            $product = $r->product_id !== null ? $products->get($r->product_id) : null;
            $category = $product?->category_id !== null ? $categories->get($product->category_id) : null;
            $prediction = $r->product_id !== null && $r->source_core_run_id !== null
                ? $predictions->get("{$r->source_core_run_id}:{$r->customer_id}:{$r->product_id}:{$r->horizon_days}") : null;
            $activity = $r->source_core_run_id !== null ? $activities->get("{$r->source_core_run_id}:{$r->customer_id}") : null;
            $last = $feedback->get($r->id);
            $state = AgendaStatusMap::effective($r->agenda_status);
            $s05 = $prediction !== null ? CustomerS05Presenter::fields($prediction) : $this->s05FromRecommendation($r);
            $cost = $this->cost($r);

            $item = [
                'id' => (int) $r->id,
                'kind' => $r->kind,
                'entity_type' => $r->entity_type,
                'as_of' => $r->as_of?->toDateString(),
                'horizon_days' => (int) $r->horizon_days,
                'source_run_id' => $r->source_core_run_id !== null ? (int) $r->source_core_run_id : null,
                'customer' => [
                    'id' => (int) $r->customer_id, 'code' => $customer?->external_code, 'type' => $customer?->customer_type,
                    'zone' => $customer?->commercial_zone,
                    'segment' => $segment ? ['id' => (int) $segment->id, 'code' => $segment->code, 'name' => $segment->name] : null,
                ],
                'product' => $r->product_id === null ? null : [
                    'id' => (int) $r->product_id, 'sku' => $product?->sku, 'name' => $product?->name, 'unit' => $product?->unit,
                    'category' => $category ? ['id' => (int) $category->id, 'name' => $category->name] : null,
                ],
                'state' => $state,
                'status' => $r->status,
                'priority' => (int) $r->priority,
                'score' => $r->score,
                'score_formula_version' => $r->score_formula_version,
                'policy' => $r->policy,
                'probability' => $r->probability,
                'expected_orders' => $s05['expected_orders'],
                'quantity_per_order' => $s05['quantity_per_order'],
                'expected_units' => $s05['expected_units'],
                'window' => $s05['window'],
                'model_segment' => $s05['segment'],
                'activity' => $activity ? [
                    'inactivity_status' => $activity->inactivity_status, 'inactivity_risk' => $activity->inactivity_risk, 'behavior_change' => $activity->behavior_change,
                    'days_since_last_purchase' => $activity->days_since_last_purchase, 'median_interval_days' => $activity->median_interval_days,
                ] : null,
                'value' => [
                    'expected_gross_value' => $r->expected_gross_value, 'expected_contribution' => $r->expected_contribution, 'cost_status' => $cost['status'],
                    'cost_reasons' => $cost['reasons'], 'action_cost' => $r->action_cost, 'incremental_estimate' => $r->incremental_estimate,
                    'attribution' => ['label' => $r->attribution_label, 'text' => $this->attributionText($r->attribution_label)],
                ] + $this->basis($r),
                'inventory' => $this->inventory($r),
                'owner_user_id' => $r->owner_user_id !== null ? (int) $r->owner_user_id : null,
                'assigned_user' => $r->assigned_user_id !== null ? ['id' => (int) $r->assigned_user_id, 'name' => $users->get($r->assigned_user_id)?->name] : null,
                'expires_at' => $r->expires_at?->toIso8601String(),
                'next_step' => $r->next_step,
                'follow_up' => [
                    'last_feedback_at' => $last?->created_at?->toIso8601String(),
                    'snoozed_until' => $state === 'pospuesto' ? ($last?->payload['snoozed_until'] ?? null) : null,
                ],
            ];
            if ($privileged) {
                $item['experiment_group'] = $r->experiment_group;   // solo con decisions.approve: ni el grupo de tratamiento ni el de control se revelan a los demás
            }
            if ($detail) {
                $item += [
                    'explanation' => $r->explanation ?? [],
                    'assumptions' => $r->assumptions ?? [],
                    'constraints' => $r->constraints ?? [],
                    'uncertainty' => $r->uncertainty ?? [],
                    'model_versions' => $r->model_versions ?? [],
                    'interval' => $r->interval ?? [],
                    'source' => ['core_run_id' => $r->source_core_run_id, 'forecast_run_id' => $r->source_forecast_run_id, 'data_snapshot_id' => $r->data_snapshot_id],
                    'allowed_feedback' => AgendaStatusMap::acceptsFeedback($r) && $r->experiment_group !== 'control' ? AgendaStatusMap::allowedFrom($r->agenda_status) : [],
                    'history' => ($history->get($r->id) ?? collect())->map(fn (DecisionEvent $e) => $this->event($e, $e->event === 'feedback' ? $syncs->get($e->id) : null))->values()->all(),
                ];
            }

            return $item;
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>|null  $sync  resultado de la sincronización con el workflow de un evento de seguimiento (el comentario que lo sigue)
     * @return array<string, mixed>
     */
    public function event(DecisionEvent $e, ?array $sync = null): array
    {
        $payload = (array) ($e->payload ?? []);

        return [
            'id' => (int) $e->id, 'event' => $e->event, 'at' => $e->created_at?->toIso8601String(),
            'from_agenda_status' => $payload['from_agenda_status'] ?? null, 'agenda_status' => $e->agenda_status,
            'from_status' => $e->from_status, 'to_status' => $e->to_status,
            'actor_kind' => $e->actor_kind, 'actor_user_id' => $e->actor_user_id !== null ? (int) $e->actor_user_id : null,
            'note' => $e->reason,
            'interest' => $payload['interest'] ?? null, 'snoozed_until' => $payload['snoozed_until'] ?? null,
            'implied_workflow_status' => $payload['implied_workflow_status'] ?? null,
        ] + ($sync === null ? [] : [
            'workflow_sync' => $sync['workflow_sync'] ?? null, 'workflow_sync_reason' => $sync['workflow_sync_reason'] ?? null,
            'workflow_status_after' => $sync['workflow_status_after'] ?? null, 'workflow_event_id' => $sync['workflow_event_id'] ?? null,
        ]);
    }

    public function attributionText(?string $label): string
    {
        return match ($label) {
            'causally_attributable' => 'Efecto atribuible causalmente (grupo de control registrado)',
            'incremental_estimated' => 'Efecto incremental estimado, con evidencia declarada',
            default => RadarPolicy::ATTRIBUTION_TEXT,
        };
    }

    /**
     * @param  list<int>  $customerIds
     * @param  list<int>  $productIds
     * @return Collection<string, CustomerPrediction>
     */
    private function predictions(Collection $recs, array $customerIds, array $productIds): Collection
    {
        $pairs = $recs->filter(fn (DecisionRecommendation $r) => $r->product_id !== null && $r->source_core_run_id !== null);
        if ($pairs->isEmpty()) {
            return collect();
        }

        // Por TUPLA exacta (corrida, comprador, producto, horizonte), no por listas independientes: cuatro `whereIn` separados devuelven el producto cruzado.
        $tuples = $pairs->map(fn (DecisionRecommendation $r) => [(int) $r->source_core_run_id, (int) $r->customer_id, (int) $r->product_id, (int) $r->horizon_days])
            ->unique(fn (array $t) => implode(':', $t))->values();
        $out = collect();
        foreach ($tuples->chunk(300) as $chunk) {
            $query = CustomerPrediction::query()->select(['id', 'core_run_id', 'customer_id', 'product_id', 'horizon_days', 'expected_orders', 'quantity_per_order', 'expected_units',
                'window_p10_days', 'window_p50_days', 'window_p90_days', 'window_status', 'segment'])
                ->whereRaw('(core_run_id, customer_id, product_id, horizon_days) IN ('.implode(',', array_fill(0, $chunk->count(), '(?, ?, ?, ?)')).')', $chunk->flatten()->all());
            $out = $out->merge($query->get()->keyBy(fn (CustomerPrediction $p) => "{$p->core_run_id}:{$p->customer_id}:{$p->product_id}:{$p->horizon_days}"));
        }

        return $out;
    }

    /**
     * @param  list<int>  $customerIds
     * @return Collection<string, CustomerActivity>
     */
    private function activities(Collection $recs, array $customerIds): Collection
    {
        $runs = $recs->where('entity_type', 'customer')->pluck('source_core_run_id')->filter()->unique()->values()->all();
        if ($runs === []) {
            return collect();
        }

        return CustomerActivity::query()
            ->select(['id', 'core_run_id', 'customer_id', 'inactivity_status', 'inactivity_risk', 'behavior_change', 'days_since_last_purchase', 'median_interval_days'])
            ->whereIn('core_run_id', $runs)->whereIn('customer_id', $customerIds)
            ->get()->keyBy(fn (CustomerActivity $a) => "{$a->core_run_id}:{$a->customer_id}");
    }

    /** @return Collection<int, DecisionEvent> último evento de feedback por recomendación */
    private function latestFeedback(Collection $recs): Collection
    {
        return DecisionEvent::query()->whereIn('recommendation_id', $recs->pluck('id')->all())->where('event', 'feedback')
            ->orderByDesc('id')->get(['id', 'recommendation_id', 'created_at', 'payload'])
            ->groupBy('recommendation_id')->map(fn (Collection $events) => $events->first());
    }

    /**
     * Historial por recomendación, del más antiguo al más reciente: transiciones, asignaciones y seguimientos. Los comentarios del sistema (refresco de insumos y
     * resultado de la sincronización con el workflow) no son pasos del historial: la sincronización se muestra en el seguimiento que la originó.
     *
     * @return Collection<int, Collection<int, DecisionEvent>>
     */
    private function history(Collection $recs): Collection
    {
        return DecisionEvent::query()->whereIn('recommendation_id', $recs->pluck('id')->all())->where('event', '<>', 'comment')->orderBy('id')->get()->groupBy('recommendation_id');
    }

    /** @return Collection<int, array<string, mixed>> resultado de la sincronización con el workflow por id de evento de seguimiento */
    private function syncs(Collection $recs): Collection
    {
        return DecisionEvent::query()->whereIn('recommendation_id', $recs->pluck('id')->all())->where('event', 'comment')->orderBy('id')->get()
            ->filter(fn (DecisionEvent $e) => ($e->payload['kind'] ?? null) === 'workflow_sync')
            ->mapWithKeys(fn (DecisionEvent $e) => [(int) ($e->payload['feedback_event_id'] ?? 0) => (array) $e->payload]);
    }

    /**
     * Base del valor, por tipo de oportunidad (nunca una sola frase para todas): la de una relación cliente × producto es un PRONÓSTICO (unidades esperadas × último precio
     * neto); la de un cliente (recuperación y riesgo a nivel de cliente) es un importe OBSERVADO (caída del gasto neto entre dos ventanas) que no se suma al pronóstico.
     *
     * @return array{basis_code: string, basis: string}
     */
    private function basis(DecisionRecommendation $r): array
    {
        return $r->entity_type !== self::PAIR_ENTITY
            ? ['basis_code' => 'observed_spend_decline', 'basis' => 'importe observado: caída del gasto neto del cliente entre las dos ventanas de comportamiento; no es un pronóstico, una contribución ni una venta causada por la acción, y no se suma al valor pronosticado']
            : ['basis_code' => 'forecast_units_x_last_net_price', 'basis' => 'supuesto: unidades esperadas × último precio neto; no es una venta confirmada ni causada por el contacto'];
    }

    /**
     * Estado del costo que sustenta la contribución, tal como lo declaró el Decision Engine al valorar la oportunidad (`uncertainty.cost`): reliable | unreliable |
     * unknown (la recomendación no trae esa declaración) y los motivos cuando no es confiable. La contribución es del motor; aquí solo se lee.
     *
     * @return array{status: string, reasons: list<string>}
     */
    private function cost(DecisionRecommendation $r): array
    {
        $cost = (array) (($r->uncertainty ?? [])['cost'] ?? []);
        if (array_key_exists('reliable', $cost)) {
            return ['status' => $cost['reliable'] === true ? 'reliable' : 'unreliable', 'reasons' => array_values(array_map('strval', (array) ($cost['reasons'] ?? [])))];
        }

        return ['status' => $r->expected_contribution !== null ? 'reliable' : 'unknown', 'reasons' => []];
    }

    /** @return array<string, mixed> */
    private function inventory(DecisionRecommendation $r): array
    {
        $availability = (array) ($r->availability ?? []);
        if ($r->product_id === null) {
            return ['status' => 'not_applicable', 'reason' => 'riesgo a nivel de cliente: sin producto no hay disponibilidad que evaluar'];
        }

        return $availability === [] ? ['status' => 'unknown', 'reason' => 'sin disponibilidad registrada'] : $availability;
    }

    /**
     * Sin la predicción de origen a mano (borrada o de riesgo de cliente), la ventana guardada al proponer; con la misma regla de honestidad.
     *
     * @return array<string, mixed>
     */
    private function s05FromRecommendation(DecisionRecommendation $r): array
    {
        $w = (array) (($r->interval ?? [])['window_days'] ?? []);

        return CustomerS05Presenter::fields((object) [
            'window_status' => $w['status'] ?? null, 'window_p10_days' => $w['p10'] ?? null, 'window_p50_days' => $w['p50'] ?? null, 'window_p90_days' => $w['p90'] ?? null,
        ]);
    }
}
