<?php

declare(strict_types=1);

namespace App\Http\Resources\Decision;

use App\Enums\TenantRole;
use App\Models\DecisionRecommendation;
use App\Models\User;
use App\Services\Decision\DecisionSettings;
use App\Services\Decision\Workflow;
use App\Support\Results\JsonMap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Recomendación del Decision Engine con el contrato completo de §11, más lo que la interfaz necesita para actuar: `version` (para el bloqueo
 * optimista), `requires_approval` y las transiciones disponibles para el usuario. Sin PII: el cliente se identifica por id y código externo.
 * El grupo de experimento solo lo ve quien puede aprobar (los de control no se asignan a vendedores y no deben contaminarse).
 *
 * Contexto de la petición (lo fija el controlador en `decision_ctx`): usuario, rol y parámetros de la empresa.
 *
 * @mixin DecisionRecommendation
 */
class DecisionRecommendationResource extends JsonResource
{
    private const ATTRIBUTION_NOTES = [
        'potential_unproven' => 'Oportunidad potencial, atribución no demostrada: el valor esperado no implica ventas incrementales causadas por la acción.',
        'incremental_estimated' => 'Incremento estimado con evidencia (ver incremental_evidence): es una estimación, no causalidad demostrada.',
        'causally_attributable' => 'Atribuible causalmente por un diseño con grupo de control registrado al generar la recomendación.',
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{user: User, role: TenantRole, settings: DecisionSettings, workflow: Workflow}|null $ctx */
        $ctx = $request->attributes->get('decision_ctx');
        $canSeeExperiment = $ctx !== null && $ctx['role']->can('decisions.approve');
        $attributes = $this->resource->getAttributes();
        $version = array_key_exists('version', $attributes) ? (int) $attributes['version'] : ($ctx !== null ? $ctx['workflow']->versionOf($this->id) : null);

        $data = [
            'id' => $this->id,
            'kind' => $this->kind,
            'as_of' => $this->as_of?->toDateString(),
            'horizon_days' => $this->horizon_days,
            'entity_type' => $this->entity_type,
            'customer' => $this->customer_id === null ? null : ($this->relationLoaded('customer') && $this->customer !== null ? ['id' => $this->customer->id, 'external_code' => $this->customer->external_code] : ['id' => $this->customer_id]),
            'product' => $this->product_id === null ? null : ($this->relationLoaded('product') && $this->product !== null ? ['id' => $this->product->id, 'sku' => $this->product->sku, 'name' => $this->product->name, 'unit' => $this->product->unit] : ['id' => $this->product_id]),
            'branch' => $this->branch_id === null ? null : ($this->relationLoaded('branch') && $this->branch !== null ? ['id' => $this->branch->id, 'code' => $this->branch->code, 'name' => $this->branch->name] : ['id' => $this->branch_id]),
            'source' => ['core_run_id' => $this->source_core_run_id, 'forecast_run_id' => $this->source_forecast_run_id, 'data_snapshot_id' => $this->data_snapshot_id],
            'model_versions' => JsonMap::orEmpty($this->model_versions),
            'probability' => $this->probability,
            'interval' => JsonMap::orEmpty($this->interval),
            'assumptions' => array_values((array) $this->assumptions),
            'constraints' => array_values((array) $this->constraints),
            'availability' => JsonMap::orEmpty($this->availability),
            'currency' => $ctx !== null ? $ctx['settings']->currency() : null,
            'action_cost' => $this->action_cost,
            'expected_gross_value' => $this->expected_gross_value,
            'expected_contribution' => $this->expected_contribution,
            'incremental_estimate' => $this->incremental_estimate,
            'incremental_evidence' => $this->incremental_evidence === null ? null : JsonMap::orEmpty($this->incremental_evidence),
            'attribution_label' => $this->attribution_label,
            'attribution_note' => self::ATTRIBUTION_NOTES[$this->attribution_label] ?? null,
            'score' => $this->score,
            'score_formula_version' => $this->score_formula_version,
            'uncertainty' => JsonMap::orEmpty($this->uncertainty),
            'explanation' => array_values((array) $this->explanation),
            'policy' => $this->policy,
            'priority' => $this->priority,
            'owner_user_id' => $this->owner_user_id,
            'assigned_user_id' => $this->assigned_user_id,
            'status' => $this->status,
            'agenda_status' => $this->agenda_status,
            'proposed_by' => ['kind' => $this->proposed_by_kind, 'user_id' => $this->proposed_by_user_id],
            'expires_at' => $this->expires_at?->toIso8601String(),
            'next_step' => $this->next_step,
            'version' => $version,
            'requires_approval' => $ctx !== null ? $ctx['workflow']->requiresApproval($this->resource, $ctx['settings']) : null,
            'allowed_transitions' => $ctx !== null ? $ctx['workflow']->availableFor($this->resource, $ctx['user'], $ctx['role'], $ctx['settings']) : [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
        if ($canSeeExperiment) {
            $data['experiment_group'] = $this->experiment_group;
        }
        if ($this->relationLoaded('events')) {
            $data['events'] = DecisionEventResource::collection($this->events)->resolve($request);
        }

        return $data;
    }
}
