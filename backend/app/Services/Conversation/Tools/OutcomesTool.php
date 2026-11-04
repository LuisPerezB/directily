<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

use App\Models\DecisionOutcome;
use App\Services\Conversation\Gateway\DataScope;
use App\Services\Outcomes\OutcomeVocabulary;
use App\Support\Horizons\HorizonCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Resultados medidos de las recomendaciones (GET /decisions/outcomes): qué pasó después de cada recomendación, con la CLASIFICACIÓN HONESTA que les dio el servicio de medición
 * (previsto, observado, asociado temporalmente, incremental estimado, causalmente atribuible) y su contribución (null si el costo no es confiable). Solo lectura de lo ya medido:
 * el modelo no mide, no asocia ni atribuye. Sin información de experimentos ni del grupo de control (aunque quien consulta pueda aprobar: el modelo no la necesita).
 * Es una vista de EQUIPO (alcance `tenant_management`): igual que GET /decisions/outcomes, que EnforceCommercialScope rechaza a quien tiene un alcance comercial
 * restringido mientras los resultados no se acoten por cartera, el gateway no la entrega a un alcance restringido (por cartera: DISEÑADO).
 */
final class OutcomesTool extends ReadTool
{
    public function ability(): string
    {
        return 'decisions.view';
    }

    public function dataScope(): DataScope
    {
        return DataScope::TenantManagement;
    }

    public function isEnabled(): bool
    {
        return (bool) config('conversation.privacy.customer_tools_enabled', true);
    }

    public function name(): string
    {
        return 'outcomes';
    }

    public function description(): string
    {
        return 'Resultados ya medidos de las recomendaciones, con su clasificación: observado, asociado temporalmente a la acción, incremental estimado o causalmente atribuible, '
            .'y la contribución conocida (no ingresos brutos). Una asociación temporal NO prueba que la acción causara la compra. Se puede filtrar por clasificación, tipo y horizonte.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'classification' => ['type' => 'string', 'enum' => OutcomeVocabulary::CLASSIFICATIONS, 'description' => 'Clasificación del resultado (opcional).'],
                'kind' => ['type' => 'string', 'enum' => OutcomeVocabulary::CUSTOMER_KINDS, 'description' => 'Tipo de recomendación (opcional).'],
                'horizon' => $this->horizonSchema(HorizonCatalog::customer()),
                'limit' => $this->limitSchema(),
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'classification' => ['sometimes', 'string', Rule::in(OutcomeVocabulary::CLASSIFICATIONS)],
            'kind' => ['sometimes', 'string', Rule::in(OutcomeVocabulary::CUSTOMER_KINDS)],
            'horizon' => ['sometimes', 'integer', Rule::in(HorizonCatalog::customer())],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('conversation.limits.max_rows_per_tool', 10)],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if ($context->requireScope()->isRestricted()) {
            return ToolResult::scopeDenied('Los resultados medidos son de toda la empresa y su alcance comercial solo permite ver su cartera.');   // defensa en profundidad
        }
        $recommendation = function (Builder $q) use ($arguments): void {
            $q->actionable();   // el grupo de control no se cuenta ni se lista nunca
            if (isset($arguments['kind'])) {
                $q->where('kind', (string) $arguments['kind']);
            }
            if (isset($arguments['horizon'])) {
                $q->where('horizon_days', (int) $arguments['horizon']);
            }
        };
        $base = fn (): Builder => DecisionOutcome::query()
            ->whereHas('recommendation', $recommendation)
            ->when(isset($arguments['classification']), fn (Builder $q) => $q->where('classification', (string) $arguments['classification']));

        $total = $base()->count();
        $byClassification = array_fill_keys(OutcomeVocabulary::CLASSIFICATIONS, 0);
        foreach ($base()->selectRaw('classification, COUNT(*) AS n')->groupBy('classification')->pluck('n', 'classification') as $class => $n) {
            $byClassification[(string) $class] = (int) $n;
        }
        $rows = $base()->with(['recommendation' => fn ($q) => $q->with(['customer:id,external_code', 'product:id,sku,name'])])
            ->orderByDesc('measured_at')->orderBy('id')->limit($this->limit($arguments))->get();

        $data = [
            'total_matches' => $total,
            'by_classification' => $byClassification,
            'outcomes' => $rows->map(function (DecisionOutcome $o): array {
                $rec = $o->recommendation;
                $contribution = (array) (((array) $o->evidence)['contribution'] ?? []);

                return [
                    'id' => (int) $o->id,
                    'recommendation' => $rec === null ? null : [
                        'id' => (int) $rec->id, 'kind' => $rec->kind, 'as_of' => $rec->as_of?->toDateString(), 'horizon_days' => $rec->horizon_days !== null ? (int) $rec->horizon_days : null,
                        'customer_code' => $rec->customer?->external_code,
                        'product' => $rec->product ? ['sku' => $rec->product->sku, 'name' => $rec->product->name] : null,
                        'expected_contribution' => $rec->expected_contribution,
                    ],
                    'action_kind' => $o->action_kind,
                    'action_label' => OutcomeVocabulary::ACTION_LABELS[$o->action_kind] ?? null,
                    'window' => ['from' => $o->window_from?->toDateString(), 'to' => $o->window_to?->toDateString()],
                    'observed_event' => $o->observed_event,
                    'observed_units' => $o->observed_units,
                    'observed_net_amount' => $o->observed_net_amount,
                    'contribution' => $o->contribution,
                    'contribution_status' => $contribution['status'] ?? null,
                    'contact_cost' => $o->contact_cost,
                    'classification' => $o->classification,
                    'classification_label' => OutcomeVocabulary::LABELS[$o->classification] ?? null,
                    'classification_note' => OutcomeVocabulary::NOTES[$o->classification] ?? null,
                    'measured_at' => $o->measured_at?->toIso8601String(),
                ];
            })->values()->all(),
            'classification_notes' => OutcomeVocabulary::NOTES,
        ];
        if ($total === 0) {
            return ToolResult::noData('No hay resultados medidos en su alcance.', null, $data);
        }

        return ToolResult::ok($data, null);
    }

    /** @return array<string, mixed> */
    public function summarize(ToolResult $result): array
    {
        return ['status' => $result->status, 'total_matches' => (int) ($result->data['total_matches'] ?? 0), 'rows' => ['outcomes' => count((array) ($result->data['outcomes'] ?? []))]];
    }
}
