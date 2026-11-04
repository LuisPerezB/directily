<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

use App\Models\DecisionRecommendation;
use App\Services\Conversation\Gateway\DataScope;
use App\Services\Outcomes\RoiReport;
use App\Support\Horizons\HorizonCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * ROI y outcomes HONESTOS de la empresa (GET /decisions/roi): oportunidad potencial vs observación vs incremento estimado, con su método, costos y límites. El modelo lo explica,
 * no lo recalcula. Es información de gestión de TODA la empresa: se niega a quien tiene una cartera comercial acotada o un rol sin visión de gestión (alcance `tenant_management`).
 * El informe respeta la redacción de experimentos según el rol de quien consulta; aun así el desglose por grupos y los experimentos no se entregan al modelo.
 */
final class RoiSummaryTool extends ReadTool
{
    /** Bloques del informe que se entregan al modelo (el desglose y los experimentos quedan en la API). */
    private const BLOCKS = ['method', 'filters', 'currency', 'today', 'data_origin', 'summary', 'costs', 'roi', 'limitations'];

    public function __construct(private readonly RoiReport $report) {}

    public function ability(): string
    {
        return 'decisions.view';
    }

    public function dataScope(): DataScope
    {
        return DataScope::TenantManagement;
    }

    public function name(): string
    {
        return 'roi_summary';
    }

    public function description(): string
    {
        return 'ROI honesto ya calculado de las recomendaciones: oportunidad potencial (atribución no demostrada), lo observado después, lo asociado temporalmente a la acción y, solo si hubo un '
            .'experimento de control, el incremento estimado; con sus costos, su método y sus límites. Sin experimento no hay cifra de ROI. Periodo por omisión: los últimos 180 días del corte.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => DecisionRecommendation::KINDS, 'description' => 'Tipo de recomendación (opcional).'],
                'horizon' => $this->horizonSchema(HorizonCatalog::customer()),
                'from' => ['type' => 'string', 'format' => 'date', 'description' => 'Inicio del periodo por fecha de corte, AAAA-MM-DD (opcional).'],
                'to' => ['type' => 'string', 'format' => 'date', 'description' => 'Fin del periodo, AAAA-MM-DD (opcional). El periodo máximo es de 730 días.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'kind' => ['sometimes', 'string', Rule::in(DecisionRecommendation::KINDS)],
            'horizon' => ['sometimes', 'integer', Rule::in(HorizonCatalog::customer())],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        if ($context->requireScope()->isRestricted()) {
            return ToolResult::scopeDenied('El ROI es de toda la empresa y su alcance comercial solo permite ver su cartera.');   // defensa en profundidad
        }
        $report = $this->report->build($context->tenant, [
            'kind' => isset($arguments['kind']) ? [(string) $arguments['kind']] : null,
            'horizon_days' => isset($arguments['horizon']) ? (int) $arguments['horizon'] : null,
            'experiment_key' => null,
            'from' => $arguments['from'] ?? null,
            'to' => $arguments['to'] ?? null,
            'group_by' => null,
        ], CarbonImmutable::now(), $context->role);

        $data = array_intersect_key($report, array_flip(self::BLOCKS));
        // El informe separa potencial, observación e incremento estimado y no los suma: el modelo debe conservar esa separación.
        $data['reading_rule'] = 'Oportunidad potencial, observación e incremento estimado son cosas distintas y no se suman. Sin experimento de control no hay cifra de ROI.';

        return ToolResult::ok($data, null);
    }

    /** @return array<string, mixed> */
    public function summarize(ToolResult $result): array
    {
        $roi = (array) ($result->data['roi'] ?? []);

        return ['status' => $result->status, 'roi_status' => is_string($roi['status'] ?? null) ? $roi['status'] : null, 'period_days' => $result->data['filters']['period_days'] ?? null];
    }
}
