<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Alert;
use App\Models\ForecastRun;
use App\Models\Tenant;
use App\Services\Analytics\Support\ForecastNode;
use App\Services\Analytics\Support\Num;
use App\Services\Analytics\Support\SalesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Resumen del dashboard comercial. Responde:
 * ¿cuánto venderé?, ¿qué sube o baja?, ¿qué podría agotarse?, ¿cuánto debo comprar y
 * cuánto dinero inmovilizaría?, ¿qué tan confiables fueron los pronósticos?
 */
class DashboardService
{
    public function __construct(
        private readonly RunResolver $runs,
        private readonly ForecastQuery $forecasts,
        private readonly HistoryQuery $history,
        private readonly ProductForecastQuery $products,
        private readonly AccuracyService $accuracy,
        private readonly InventoryQuery $inventory,
        private readonly HorizonSupport $support,
    ) {}

    /** @return array<string, mixed> */
    public function summary(Tenant $tenant, int $horizon, ?int $branchId): array
    {
        $tenantId = (int) $tenant->getKey();
        $run = $this->runs->current();
        $node = ForecastNode::fromFilters($branchId, null, null);

        $kpis = [
            'forecast_units' => null, 'forecast_units_lo_80' => null, 'forecast_units_hi_80' => null,
            'forecast_revenue' => null, 'previous_period_units' => null, 'previous_period_revenue' => null,
            'change_pct' => null, 'stockout_risk_products' => null, 'purchase_investment' => null,
            'backtest_wape' => null, 'baseline_wape' => null, 'realized_wape' => null,
        ];
        $topUp = $topDown = $risks = [];

        if ($run !== null) {
            $totals = $this->forecasts->horizonTotals($run, $node, $horizon);
            [$from, $to] = $this->products->historyWindow($run, $horizon);
            $previous = $this->history->totals($tenantId, $node, $from, $to);
            $backtest = $this->accuracy->backtest($run);
            $riskCounts = $this->inventory->riskCounts($run, $branchId);

            $kpis = [
                'forecast_units' => $totals['units'],
                'forecast_units_lo_80' => $totals['lo_80'],
                'forecast_units_hi_80' => $totals['hi_80'],
                'forecast_revenue' => $totals['revenue'],
                'previous_period_units' => Num::units($previous['units']),
                'previous_period_revenue' => Num::money($previous['revenue']),
                'change_pct' => Num::change($totals['units'], $previous['units']),
                'stockout_risk_products' => $riskCounts['high'] + $riskCounts['critical'],
                'purchase_investment' => $this->inventory->purchaseInvestment($run, $branchId),
                'backtest_wape' => $backtest['selected']['wape'] ?? null,
                'baseline_wape' => $backtest['baseline']['wape'] ?? null,
                'realized_wape' => $this->accuracy->realized($tenantId)['wape'] ?? null,
            ];
            $topUp = $this->products->top($run, $horizon, $branchId, 'up')->all();
            $topDown = $this->products->top($run, $horizon, $branchId, 'down')->all();
            $risks = $this->inventory->topRisks($run, $branchId);
        }

        $alerts = Alert::query()->where('status', Alert::STATUS_OPEN)
            ->latest('id')->limit(5)
            ->get(['id', 'type', 'severity', 'title', 'message', 'payload', 'status', 'created_at'])
            ->map(fn (Alert $a) => [
                'id' => $a->id, 'type' => $a->type, 'severity' => $a->severity, 'title' => $a->title,
                'message' => $a->message, 'payload' => $a->payload, 'status' => $a->status,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->all();

        return [
            'horizon' => $horizon,
            'branch_id' => $branchId,
            'run' => $run ? [
                'id' => $run->id,
                'version' => $run->version,
                'generated_at' => $run->completed_at?->toIso8601String(),
                'cutoff_date' => $run->cutoff_date?->toDateString(),
            ] : null,
            'kpis' => $kpis,
            // Etapa 2 (aditivo): respaldo del horizonte mostrado; no se presenta como completo uno sin respaldo.
            'horizon_support' => $this->support->forHorizon($run, $horizon),
            'top_increasing' => $topUp,
            'top_decreasing' => $topDown,
            'stockout_risks' => $risks,
            'alerts' => $alerts,
            'data_status' => $this->dataStatus($tenantId, $run),
        ];
    }

    /** @return array<string, mixed> */
    public function dataStatus(int $tenantId, ?ForecastRun $run): array
    {
        $lastSale = SalesQuery::lastSaleDate($tenantId);
        $hasInventory = DB::table('inventory_snapshots')->where('tenant_id', $tenantId)->exists();
        $readiness = DB::table('data_quality_reports')
            ->where('tenant_id', $tenantId)
            ->where('scope', 'forecasting_readiness')
            ->latest('id')
            ->first(['score', 'issues']);

        $warnings = [];
        if ($lastSale === null) {
            $warnings[] = 'Aún no hay ventas importadas. Importe su historial de ventas para generar pronósticos.';
        }
        if (! $hasInventory) {
            $warnings[] = 'No hay datos de inventario: no se pueden calcular recomendaciones de abastecimiento ni detectar quiebres de stock.';
        }
        if ($lastSale !== null && $run === null) {
            $warnings[] = 'Todavía no hay un pronóstico generado. Solicite uno desde la sección Pronósticos.';
        }
        if ($run !== null && $lastSale !== null && $run->cutoff_date !== null
            && CarbonImmutable::parse($lastSale)->greaterThan(CarbonImmutable::parse($run->cutoff_date->toDateString()))) {
            $warnings[] = "Hay ventas posteriores al último pronóstico (datos hasta {$lastSale}). Genere un nuevo pronóstico para incorporarlas.";
        }
        $lastFailed = ForecastRun::query()->latest('id')->first(['id', 'status', 'version']);
        if ($lastFailed !== null && $lastFailed->status === ForecastRun::STATUS_FAILED) {
            $warnings[] = "La última ejecución de pronóstico (v{$lastFailed->version}) falló. Revise el detalle en Pronósticos.";
        }
        if ($readiness !== null) {
            $issues = json_decode((string) $readiness->issues, true) ?: [];
            foreach (array_slice(array_filter($issues, fn ($i) => in_array($i['severity'] ?? '', ['error', 'warning'], true)), 0, 3) as $issue) {
                if (! empty($issue['message'])) {
                    $warnings[] = (string) $issue['message'];
                }
            }
        }

        return [
            'has_sales' => $lastSale !== null,
            'has_inventory' => $hasInventory,
            'has_forecast' => $run !== null,
            'last_sale_date' => $lastSale,
            'readiness_score' => $readiness?->score !== null ? round((float) $readiness->score, 1) : null,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}
