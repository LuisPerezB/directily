<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Requests\Analytics\DashboardRequest;
use App\Services\Analytics\AccuracyService;
use App\Services\Analytics\DashboardService;
use App\Services\Analytics\RunResolver;
use App\Services\Analytics\Support\Num;
use Illuminate\Http\JsonResponse;

/** Reporte ejecutivo listo para imprimir (JSON; el frontend lo maqueta). */
class ReportController extends AnalyticsController
{
    public function forecastSummary(DashboardRequest $request, DashboardService $dashboard, AccuracyService $accuracy, RunResolver $runs): JsonResponse
    {
        $tenant = $this->tenant();
        $horizon = $request->horizon($this->defaultHorizon());
        $summary = $dashboard->summary($tenant, $horizon, $request->intOrNull('branch_id'));
        $accuracySummary = $accuracy->summary($runs->current());
        $k = $summary['kpis'];

        $highlights = [];
        if ($summary['run'] !== null && $k['forecast_units'] !== null) {
            $line = 'Se estiman '.number_format((float) $k['forecast_units'], 0, ',', '.')." unidades en los próximos {$horizon} días";
            if ($k['forecast_revenue'] !== null) {
                $line .= ' (ingresos estimados de $'.number_format((float) $k['forecast_revenue'], 2, ',', '.').', con precios constantes)';
            }
            if ($k['change_pct'] !== null) {
                $line .= ', '.($k['change_pct'] >= 0 ? 'un aumento' : 'una disminución').' de '.number_format(abs($k['change_pct']) * 100, 1, ',', '.')." % frente a los {$horizon} días previos";
            }
            $highlights[] = $line.'.';
            if (($k['stockout_risk_products'] ?? 0) > 0) {
                $highlights[] = "{$k['stockout_risk_products']} productos tienen riesgo alto de quiebre de stock.";
            }
            if ($k['purchase_investment'] !== null && $k['purchase_investment'] > 0) {
                $highlights[] = 'Las compras sugeridas inmovilizarían aproximadamente $'.number_format((float) $k['purchase_investment'], 2, ',', '.').'.';
            }
        }

        return response()->json(['data' => [
            'title' => 'Resumen de pronóstico comercial',
            'tenant' => ['name' => $tenant->name, 'currency' => $tenant->currency, 'timezone' => $tenant->timezone],
            'generated_at' => now()->toIso8601String(),
            'horizon' => $horizon,
            'run' => $summary['run'],
            'highlights' => $highlights,
            'kpis' => $k,
            'top_increasing' => $summary['top_increasing'],
            'top_decreasing' => $summary['top_decreasing'],
            'stockout_risks' => $summary['stockout_risks'],
            'accuracy' => [
                'backtest_wape' => Num::ratio($k['backtest_wape']),
                'baseline_wape' => Num::ratio($k['baseline_wape']),
                'realized_wape' => Num::ratio($k['realized_wape']),
                'explanation' => $accuracySummary['explanation'],
            ],
            'data_status' => $summary['data_status'],
            'disclaimers' => [
                'Los ingresos estimados suponen precios constantes iguales al último precio neto observado.',
                'Los pronósticos tienen incertidumbre; consulte los rangos en la vista diaria.',
                'Las recomendaciones de compra son sugerencias: Predictily no ejecuta pedidos.',
            ],
        ]]);
    }
}
