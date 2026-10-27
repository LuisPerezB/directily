<?php

declare(strict_types=1);

namespace App\Services\Scenarios;

use App\Models\ForecastRun;
use App\Services\Analytics\Support\ForecastNode;
use App\Services\Analytics\Support\SalesQuery;
use Illuminate\Support\Facades\DB;

/**
 * Construye la base calculada de un escenario a partir del pronóstico vigente,
 * los últimos costos conocidos y las recomendaciones de abastecimiento.
 */
class ScenarioBaselineLoader
{
    /** @param array{branch_id?: int|null, category_id?: int|null, product_id?: int|null} $filters */
    public function load(ForecastRun $run, int $horizon, array $filters): ScenarioBaseline
    {
        $tenantId = (int) $run->tenant_id;
        $branchId = $filters['branch_id'] ?? null;
        $categoryId = $filters['category_id'] ?? null;
        $productId = $filters['product_id'] ?? null;

        $productIdsInCategory = $categoryId !== null && $productId === null
            ? DB::table('products')->select('id')->where('tenant_id', $tenantId)->where('category_id', $categoryId)
            : null;

        $forecast = DB::table('forecast_values as fv')
            ->where('fv.tenant_id', $tenantId)
            ->where('fv.forecast_run_id', $run->id)
            ->where('fv.level', $branchId !== null ? ForecastNode::LEVEL_SERIES : ForecastNode::LEVEL_PRODUCT)
            ->where('fv.granularity', 'day')
            ->where('fv.horizon_step', '<=', $horizon)
            ->when($branchId !== null, fn ($q) => $q->where('fv.branch_id', $branchId))
            ->when($productId !== null, fn ($q) => $q->where('fv.product_id', $productId))
            ->when($productIdsInCategory !== null, fn ($q) => $q->whereIn('fv.product_id', $productIdsInCategory))
            ->groupBy('fv.product_id')
            ->selectRaw('fv.product_id, SUM(fv.yhat) AS units, SUM(fv.revenue_yhat) AS revenue')
            ->get();

        $productIds = $forecast->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        $costs = $this->latestUnitCosts($tenantId, $productIds);

        $products = $forecast->map(fn ($r) => [
            'product_id' => (int) $r->product_id,
            'units' => (float) $r->units,
            'revenue' => $r->revenue !== null ? (float) $r->revenue : null,
            'unit_cost' => $costs[(int) $r->product_id] ?? null,
        ])->values()->all();

        $replenishment = DB::table('recommendations')
            ->where('tenant_id', $tenantId)
            ->where('forecast_run_id', $run->id)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
            ->when($productIdsInCategory !== null, fn ($q) => $q->whereIn('product_id', $productIdsInCategory))
            ->get(['product_id', 'branch_id', 'suggested_qty', 'reorder_point', 'available', 'demand_lead_time',
                'safety_stock', 'lead_time_days', 'review_period_days', 'unit_cost', 'risk_level'])
            ->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'branch_id' => (int) $r->branch_id,
                'suggested_qty' => (float) $r->suggested_qty,
                'reorder_point' => (float) $r->reorder_point,
                'available' => $r->available !== null ? (float) $r->available : null,
                'demand_lead_time' => (float) $r->demand_lead_time,
                'safety_stock' => (float) $r->safety_stock,
                'lead_time_days' => (int) $r->lead_time_days,
                'review_period_days' => (int) $r->review_period_days,
                'unit_cost' => $r->unit_cost !== null ? (float) $r->unit_cost : ($costs[(int) $r->product_id] ?? null),
                'risk_level' => $r->risk_level,
            ])->values()->all();

        $score = DB::table('data_quality_reports')
            ->where('tenant_id', $tenantId)
            ->where('scope', 'forecasting_readiness')
            ->latest('id')
            ->value('score');

        return new ScenarioBaseline($horizon, $products, $replenishment, $score !== null ? (float) $score : null);
    }

    /**
     * Último costo unitario conocido por producto: costo histórico de la venta más reciente,
     * luego costo actual del catálogo y, por último, el costo del inventario más reciente.
     *
     * @param  list<int>  $productIds
     * @return array<int, float>
     */
    public function latestUnitCosts(int $tenantId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $fromSales = SalesQuery::validItems($tenantId)
            ->whereIn('si.product_id', $productIds)
            ->whereNotNull('si.unit_cost')
            ->selectRaw('DISTINCT ON (si.product_id) si.product_id, si.unit_cost')
            ->orderBy('si.product_id')
            ->orderByDesc('si.sale_date')
            ->pluck('unit_cost', 'product_id');

        $fromCatalog = DB::table('products')->where('tenant_id', $tenantId)
            ->whereIn('id', $productIds)->whereNotNull('current_cost')
            ->pluck('current_cost', 'id');

        $fromInventory = DB::table('inventory_snapshots')->where('tenant_id', $tenantId)
            ->whereIn('product_id', $productIds)->whereNotNull('unit_cost')
            ->selectRaw('DISTINCT ON (product_id) product_id, unit_cost')
            ->orderBy('product_id')->orderByDesc('snapshot_date')
            ->pluck('unit_cost', 'product_id');

        $costs = [];
        foreach ($productIds as $id) {
            $value = $fromSales[$id] ?? $fromCatalog[$id] ?? $fromInventory[$id] ?? null;
            if ($value !== null) {
                $costs[$id] = (float) $value;
            }
        }

        return $costs;
    }
}
