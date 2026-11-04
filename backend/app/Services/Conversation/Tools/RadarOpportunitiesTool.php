<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

use App\Models\Customer;
use App\Services\Conversation\Gateway\DataScope;
use App\Services\Customer\CustomerEnvelope;
use App\Services\Forecasting\Support\CoreServices;
use App\Services\Plans\PlanLimitService;
use App\Services\Radar\AgendaStatusMap;
use App\Services\Radar\OpportunityPresenter;
use App\Services\Radar\PredictiveSalesAgenda;
use App\Services\Radar\RadarFilters;
use App\Services\Radar\RadarPolicy;
use App\Support\Horizons\HorizonCatalog;
use Illuminate\Validation\Rule;

/**
 * Oportunidades comerciales del Revenue Radar y el Decision Engine (GET /radar/opportunities): lista de trabajo ya materializada en `decision_recommendations`
 * (contacto, recuperación, riesgo de cliente). Solo lectura; las del grupo de CONTROL de un experimento nunca se incluyen ni se revelan. El alcance comercial (W6-A) lo aplica
 * PredictiveSalesAgenda con el CommercialScope que el gateway fija mientras corre la herramienta —la misma regla de la ruta equivalente—, y la habilidad y el plan también. Solo códigos de comprador, nunca nombre, correo ni cédula/RUC.
 * Toda oportunidad es «oportunidad potencial, atribución no demostrada».
 */
final class RadarOpportunitiesTool extends ReadTool
{
    public function __construct(private readonly PredictiveSalesAgenda $agenda, private readonly OpportunityPresenter $presenter) {}

    public function isEnabled(): bool
    {
        return (bool) config('conversation.privacy.customer_tools_enabled', true);
    }

    public function dataScope(): DataScope
    {
        return DataScope::CustomerPortfolio;
    }

    public function name(): string
    {
        return 'radar_opportunities';
    }

    public function description(): string
    {
        return 'Oportunidades comerciales ya generadas (contacto, recuperación y riesgo de cliente) con probabilidad, unidades y valor esperados, contribución solo si el costo es confiable, '
            .'prioridad, estado de seguimiento e inventario. Son oportunidades potenciales con atribución no demostrada, no ventas confirmadas. Se pueden filtrar por tipo, horizonte, estado o código del comprador.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => RadarPolicy::KINDS, 'description' => 'Tipo de oportunidad (opcional).'],
                'horizon' => $this->horizonSchema(HorizonCatalog::customer()),
                'state' => ['type' => 'string', 'enum' => AgendaStatusMap::STATES, 'description' => 'Estado de seguimiento de la agenda (opcional).'],
                'customer_code' => ['type' => 'string', 'maxLength' => 50, 'description' => 'Código externo del comprador (opcional).'],
                'limit' => $this->limitSchema(),
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'kind' => ['sometimes', 'string', Rule::in(RadarPolicy::KINDS)],
            'horizon' => ['sometimes', 'integer', Rule::in(HorizonCatalog::customer())],
            'state' => ['sometimes', 'string', Rule::in(AgendaStatusMap::STATES)],
            'customer_code' => ['sometimes', 'string', 'min:1', 'max:50'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('conversation.limits.max_rows_per_tool', 10)],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $tenant = $context->tenant;
        $context->requireScope();   // solo se ejecuta por el gateway, que fija el alcance comercial que PredictiveSalesAgenda aplica
        app(PlanLimitService::class)->assertCustomer($tenant);   // un plan sin comportamiento de compra (Starter) no consulta el Radar por el asistente
        $horizon = isset($arguments['horizon']) ? (int) $arguments['horizon'] : null;
        if ($horizon !== null) {
            CoreServices::assertHorizon($tenant, $horizon);       // 402: un horizonte fuera del plan no se revela
        }

        // Un código inexistente y uno fuera de la cartera dan EXACTAMENTE la misma respuesta (id 0 = ningún comprador): no se revela qué códigos existen.
        $customerId = isset($arguments['customer_code'])
            ? (int) (Customer::query()->where('external_code', trim((string) $arguments['customer_code']))->value('id') ?? 0)
            : null;
        $filters = new RadarFilters(
            customerId: $customerId,
            horizon: $horizon,
            state: isset($arguments['state']) ? (string) $arguments['state'] : null,
            kind: isset($arguments['kind']) ? (string) $arguments['kind'] : null,
            privileged: false,   // el modelo nunca conoce la pertenencia a un experimento
        );

        $query = $this->agenda->worklist($filters);
        $total = (clone $query)->count();
        $rows = $query->orderByRaw('score DESC NULLS LAST')->orderBy('id')->limit($this->limit($arguments))->get();
        $presented = $this->presenter->present($rows, false, false);

        $runId = $rows->pluck('source_core_run_id')->filter()->max();
        $run = $this->agenda->envelopeRun($runId === null ? null : (int) $runId);
        $envelope = $run === null
            ? CustomerEnvelope::missing($tenant, 'forecast', 'Aún no hay una predicción de comportamiento de compra completada.')
            : CustomerEnvelope::forRun($run, $tenant, $horizon);

        $data = [
            'opportunities' => array_map(fn (array $o): array => self::project($o), $presented),
            'total_matches' => $total,
            'label' => RadarPolicy::ATTRIBUTION_TEXT,
            'definitions' => array_intersect_key(RadarPolicy::DEFINITIONS, array_flip(['kind', 'state', 'status', 'score', 'expected_gross_value', 'expected_contribution', 'attribution'])),
        ];
        if ($presented === []) {
            return ToolResult::noData('No hay oportunidades para ese filtro en su cartera.', $envelope, $data);
        }

        return ToolResult::ok($data, $envelope);
    }

    /**
     * Proyección mínima para el modelo: sin el vendedor asignado (nombre de una persona), sin texto libre del motor (siguiente paso) ni la pertenencia a experimentos.
     *
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private static function project(array $o): array
    {
        $value = (array) ($o['value'] ?? []);
        $inventory = (array) ($o['inventory'] ?? []);

        return [
            'id' => $o['id'], 'kind' => $o['kind'], 'as_of' => $o['as_of'], 'horizon_days' => $o['horizon_days'],
            'customer' => ['code' => $o['customer']['code'] ?? null, 'type' => $o['customer']['type'] ?? null, 'zone' => $o['customer']['zone'] ?? null, 'segment_code' => $o['customer']['segment']['code'] ?? null],
            'product' => $o['product'] === null ? null : ['sku' => $o['product']['sku'] ?? null, 'name' => $o['product']['name'] ?? null, 'unit' => $o['product']['unit'] ?? null],
            'state' => $o['state'], 'status' => $o['status'], 'priority' => $o['priority'], 'score' => $o['score'], 'probability' => $o['probability'],
            'expected_orders' => $o['expected_orders'], 'quantity_per_order' => $o['quantity_per_order'], 'expected_units' => $o['expected_units'], 'window' => $o['window'],
            'value' => [
                'expected_gross_value' => $value['expected_gross_value'] ?? null, 'expected_contribution' => $value['expected_contribution'] ?? null,
                'cost_status' => $value['cost_status'] ?? null, 'basis_code' => $value['basis_code'] ?? null, 'attribution' => ['label' => $value['attribution']['label'] ?? null, 'text' => $value['attribution']['text'] ?? null],
            ],
            'inventory_status' => $inventory['status'] ?? null,
            'expires_at' => $o['expires_at'],
        ];
    }

    /** @return array<string, mixed> */
    public function summarize(ToolResult $result): array
    {
        $summary = parent::summarize($result);
        $summary['total_matches'] = (int) ($result->data['total_matches'] ?? 0);

        return $summary;
    }
}
