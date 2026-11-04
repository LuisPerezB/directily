<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

use App\Models\DecisionRecommendation;
use App\Services\Conversation\Gateway\DataScope;
use App\Services\Radar\OpportunityPresenter;
use App\Services\Radar\RadarPolicy;

/**
 * Explicación de UNA recomendación del Decision Engine (GET /decisions/{id}): insumos, supuestos, restricciones, incertidumbre, versión de la fórmula del puntaje y de los
 * modelos de origen, tal como el motor los persistió. El modelo solo los redacta; no recalcula. Una recomendación inexistente, de otra empresa, fuera de la cartera del
 * usuario (alcance comercial de W6-A) o del grupo de control de un experimento (si no puede aprobar) da la MISMA respuesta: no se revela qué identificadores existen.
 */
final class ExplainRecommendationTool extends ReadTool
{
    public function ability(): string
    {
        return 'decisions.view';
    }

    public function dataScope(): DataScope
    {
        return DataScope::CustomerPortfolio;
    }

    public function isEnabled(): bool
    {
        return (bool) config('conversation.privacy.customer_tools_enabled', true);
    }

    public function name(): string
    {
        return 'explain_recommendation';
    }

    public function description(): string
    {
        return 'Explicación de una recomendación del Decision Engine por su identificador: tipo, estado, prioridad, puntaje y la versión de su fórmula, probabilidad, valor esperado, '
            .'insumos que la justifican, supuestos, restricciones e incertidumbre, tal como se guardaron. Es una oportunidad potencial con atribución no demostrada.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recommendation_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identificador de la recomendación (de decisions_summary o radar_opportunities).'],
            ],
            'required' => ['recommendation_id'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        // Sin «exists»: un identificador inexistente y uno ajeno no deben distinguirse.
        return ['recommendation_id' => ['required', 'integer', 'min:1']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $scope = $context->requireScope();
        $notVisible = ToolResult::noData('No hay una recomendación con ese identificador en su alcance.');
        $query = DecisionRecommendation::query()->whereKey((int) $arguments['recommendation_id']);
        $scope->constrainRecommendations($query);
        if (! ($context->role?->can('decisions.approve') ?? false)) {
            $query->actionable();   // el grupo de control no se revela a quien no aprueba
        }
        $rec = $query->with(['customer:id,external_code', 'product:id,sku,name,unit'])->first();
        if ($rec === null) {
            return $notVisible;
        }

        return ToolResult::ok([
            'recommendation' => [
                'id' => (int) $rec->id, 'kind' => $rec->kind, 'entity_type' => $rec->entity_type, 'status' => $rec->status, 'priority' => (int) $rec->priority,
                'as_of' => $rec->as_of?->toDateString(), 'horizon_days' => $rec->horizon_days !== null ? (int) $rec->horizon_days : null,
                'customer' => $rec->customer ? ['code' => $rec->customer->external_code] : null,
                'product' => $rec->product ? ['sku' => $rec->product->sku, 'name' => $rec->product->name, 'unit' => $rec->product->unit] : null,
                'score' => $rec->score, 'score_formula_version' => $rec->score_formula_version, 'policy' => $rec->policy,
                'probability' => $rec->probability, 'interval' => (object) ($rec->interval ?? []),
                'expected_gross_value' => $rec->expected_gross_value, 'expected_contribution' => $rec->expected_contribution, 'action_cost' => $rec->action_cost,
                'attribution' => ['label' => $rec->attribution_label, 'text' => $this->attributionText($rec->attribution_label)],
                'explanation' => array_values((array) ($rec->explanation ?? [])),
                'assumptions' => array_values((array) ($rec->assumptions ?? [])),
                'constraints' => array_values((array) ($rec->constraints ?? [])),
                'uncertainty' => (object) ($rec->uncertainty ?? []),
                'availability' => (object) ($rec->availability ?? []),
                'model_versions' => (object) ($rec->model_versions ?? []),
                'source' => ['core_run_id' => $rec->source_core_run_id, 'forecast_run_id' => $rec->source_forecast_run_id],
            ],
            'definitions' => array_intersect_key(RadarPolicy::DEFINITIONS, array_flip(['score', 'expected_gross_value', 'expected_contribution', 'attribution'])),
        ], null);
    }

    private function attributionText(?string $label): string
    {
        return app(OpportunityPresenter::class)->attributionText($label);
    }

    /** @return array<string, mixed> */
    public function summarize(ToolResult $result): array
    {
        $rec = (array) ($result->data['recommendation'] ?? []);

        return ['status' => $result->status, 'found' => $rec !== [], 'kind' => $rec['kind'] ?? null, 'recommendation_status' => $rec['status'] ?? null];
    }
}
