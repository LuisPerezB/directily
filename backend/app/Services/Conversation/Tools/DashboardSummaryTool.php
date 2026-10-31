<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

use App\Services\Analytics\DashboardService;
use App\Services\Analytics\DemandEnvelope;
use App\Services\Analytics\RunResolver;
use App\Support\Tenancy\TenantRule;
use Illuminate\Validation\Rule;

/** Resumen del panel comercial (GET /dashboard/summary): pronóstico del horizonte, cambios, riesgos de stock, alertas. */
final class DashboardSummaryTool extends ReadTool
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly RunResolver $runs,
        private readonly DemandEnvelope $envelopes,
    ) {}

    public function name(): string
    {
        return 'dashboard_summary';
    }

    public function description(): string
    {
        return 'Resumen del panel comercial con resultados YA calculados: unidades e ingresos pronosticados en el horizonte, cambio frente al período previo, '
            .'productos que más suben o bajan, riesgos de quiebre de stock, inversión de compra sugerida, error de backtesting y alertas abiertas.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'horizon' => $this->horizonSchema(array_map('intval', (array) config('engine.horizons'))),
                'branch_id' => ['type' => 'integer', 'description' => 'Identificador de sucursal de la empresa (opcional).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'horizon' => ['sometimes', 'integer', Rule::in(array_map('intval', (array) config('engine.horizons')))],
            'branch_id' => ['sometimes', 'integer', TenantRule::exists('branches')],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $horizon = $this->horizon($arguments, $context->tenant, array_map('intval', (array) config('engine.horizons')));
        $branchId = isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null;

        $data = $this->dashboard->summary($context->tenant, $horizon, $branchId);
        // Las cargas libres de las alertas no aportan cifras al modelo.
        $data['alerts'] = array_map(fn (array $a) => array_diff_key($a, ['payload' => true]), (array) $data['alerts']);

        $run = $this->runs->current();
        if ($run === null) {
            return ToolResult::noData('Aún no hay un pronóstico generado para esta empresa.', null, ['horizon' => $horizon, 'data_status' => $data['data_status']]);
        }

        return ToolResult::ok($data, $this->envelopes->for($run, $horizon, ['entity_keys' => $branchId !== null ? ['branch_id'] : []]));
    }
}
