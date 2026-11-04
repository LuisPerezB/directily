<?php

declare(strict_types=1);

namespace App\Http\Resources\Decision;

use App\Enums\TenantRole;
use App\Models\DecisionOutcome;
use App\Services\Decision\ExperimentRedaction;
use App\Services\Outcomes\OutcomeVocabulary;
use App\Support\Results\JsonMap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resultado medido de una recomendación (V2.5 · W5-C): acción, evento observable, contribución (NULL con su motivo si el costo no es confiable), clasificación honesta
 * y la evidencia completa de la medición (ventana, corte de conocimiento, huella de las ventas leídas). Sin PII: el cliente se identifica por id y código externo.
 * El grupo de experimento solo lo ve quien aprueba (las unidades de control no se revelan a quien contacta): para los demás se omite también de la evidencia.
 *
 * @mixin DecisionOutcome
 */
class DecisionOutcomeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TenantRole|null $role */
        $role = $request->attributes->get('tenant_role');
        $canSeeExperiment = ExperimentRedaction::canSee($role);
        $rec = $this->relationLoaded('recommendation') ? $this->recommendation : null;
        $evidence = (array) $this->evidence;
        $contribution = (array) ($evidence['contribution'] ?? []);
        if (! $canSeeExperiment) {
            // Sin información del experimento para quien no aprueba (ExperimentRedaction de W5-B): ni grupo, ni clave, ni la estimación por grupos (`itt`), ni la unidad.
            unset($evidence['itt'], $evidence['unit']);
            $evidence = ExperimentRedaction::strip($evidence);
        }

        $data = [
            'id' => $this->id,
            'recommendation' => $rec === null ? ['id' => $this->recommendation_id] : [
                'id' => $rec->id, 'kind' => $rec->kind, 'status' => $rec->status, 'as_of' => $rec->as_of?->toDateString(), 'horizon_days' => $rec->horizon_days,
                'entity_type' => $rec->entity_type, 'priority' => $rec->priority, 'assigned_user_id' => $rec->assigned_user_id,
                'customer' => $rec->customer_id === null ? null : ($rec->relationLoaded('customer') && $rec->customer !== null ? ['id' => $rec->customer->id, 'external_code' => $rec->customer->external_code] : ['id' => $rec->customer_id]),
                'product' => $rec->product_id === null ? null : ($rec->relationLoaded('product') && $rec->product !== null ? ['id' => $rec->product->id, 'sku' => $rec->product->sku, 'name' => $rec->product->name] : ['id' => $rec->product_id]),
                'expected_contribution' => $rec->expected_contribution,
                'attribution_label_at_generation' => $rec->attribution_label,
            ],
            'action_kind' => $this->action_kind,
            'action_label' => OutcomeVocabulary::ACTION_LABELS[$this->action_kind] ?? null,
            'action_at' => $this->action_at?->toIso8601String(),
            'window' => ['from' => $this->window_from?->toDateString(), 'to' => $this->window_to?->toDateString(), 'knowledge_cutoff' => $evidence['window']['knowledge_cutoff'] ?? null],
            'observed_event' => $this->observed_event,
            'observed_ref' => $this->observed_ref,
            'observed_units' => $this->observed_units,
            'observed_net_amount' => $this->observed_net_amount,
            'contribution' => $this->contribution,
            'contribution_status' => $contribution['status'] ?? null,
            'contribution_reasons' => array_values((array) ($contribution['reasons'] ?? [])),
            'after_action' => $this->afterAction($evidence),
            'contact_cost' => $this->contact_cost,
            'classification' => $this->classification,
            'classification_label' => OutcomeVocabulary::LABELS[$this->classification] ?? null,
            'classification_note' => OutcomeVocabulary::NOTES[$this->classification] ?? null,
            'method' => $this->method,
            'evidence' => JsonMap::orEmpty($evidence),
            'measured_at' => $this->measured_at?->toIso8601String(),
        ];
        if ($canSeeExperiment) {
            $data['experiment_group'] = $rec?->experiment_group;
        }

        return $data;
    }

    /**
     * Lo posterior a la acción registrada (compras emitidas después, netas de las devoluciones posteriores), con su contribución: es lo ÚNICO asociable a la acción. NULL si
     * no hubo una acción registrada dentro de la ventana. La ventana completa está en `observed_*` y `contribution`.
     *
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>|null
     */
    private function afterAction(array $evidence): ?array
    {
        $a = $evidence['after_action'] ?? null;
        if (! is_array($a)) {
            return null;
        }

        return [
            'units' => $a['units'] ?? null, 'net_amount' => $a['net_amount'] ?? null, 'contribution' => $a['contribution'] ?? null, 'contribution_status' => $a['contribution_status'] ?? null,
            'contribution_reasons' => array_values((array) ($a['contribution_reasons'] ?? [])), 'purchase_lines' => $a['purchase_lines'] ?? null,
        ];
    }
}
