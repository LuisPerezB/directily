<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

use App\Services\Analytics\AccuracyService;
use App\Services\Analytics\DemandEnvelope;
use App\Services\Analytics\RunResolver;

/** Precisión del pronóstico (GET /accuracy/summary): backtesting frente a la referencia y precisión realizada. */
final class ForecastAccuracyTool extends ReadTool
{
    public function __construct(
        private readonly AccuracyService $accuracy,
        private readonly RunResolver $runs,
        private readonly DemandEnvelope $envelopes,
    ) {}

    public function name(): string
    {
        return 'forecast_accuracy';
    }

    public function description(): string
    {
        return 'Precisión ya calculada del pronóstico vigente: error (WAPE) y sesgo del backtesting frente a la referencia, cobertura de los intervalos, '
            .'precisión realizada frente a ventas posteriores y apoyo por horizonte.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    public function rules(): array
    {
        return [];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $run = $this->runs->resolveCompleted(null);
        if ($run === null) {
            return ToolResult::noData('Aún no hay un pronóstico generado para esta empresa, por lo que no hay precisión que consultar.');
        }
        $summary = $this->accuracy->summary($run);
        $backtest = (array) ($summary['backtest'] ?? []);
        // Se omiten listas largas por modelo/sucursal/ventana: el resumen basta para explicar la precisión.
        $data = [
            'run' => $summary['run'],
            'backtest' => array_intersect_key($backtest, array_flip(['selected', 'baseline', 'n_windows', 'evaluation_scope', 'by_horizon', 'by_horizon_total', 'by_demand_class'])),
            'realized' => $summary['realized'],
            'horizon_support' => $summary['horizon_support'],
            'explanation' => $summary['explanation'],
        ];

        return ToolResult::ok($data, $this->envelopes->for($run, null, ['result_type' => 'evaluation']));
    }
}
