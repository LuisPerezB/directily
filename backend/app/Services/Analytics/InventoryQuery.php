<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\ForecastRun;
use App\Models\Recommendation;
use App\Services\Analytics\Support\Num;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de inventario y recomendaciones de abastecimiento (escritas por el motor).
 * Las recomendaciones son sugerencias: Predictily nunca ejecuta pedidos.
 */
class InventoryQuery
{
    /** Lista blanca de ordenamientos. */
    private const SORTS = [
        '-stockout_probability' => ['stockout_probability', 'desc'],
        'stockout_probability' => ['stockout_probability', 'asc'],
        'days_of_cover' => ['days_of_cover', 'asc'],
        '-days_of_cover' => ['days_of_cover', 'desc'],
        '-estimated_investment' => ['estimated_investment', 'desc'],
        '-suggested_qty' => ['suggested_qty', 'desc'],
    ];

    public static function sorts(): array
    {
        return array_keys(self::SORTS);
    }

    /** @param array<string, mixed> $filters */
    public function recommendations(ForecastRun $run, array $filters, string $sort, int $perPage, int $page): LengthAwarePaginator
    {
        [$column, $direction] = self::SORTS[$sort] ?? self::SORTS['-stockout_probability'];

        return $this->filtered($run, $filters)
            ->with(['product:id,sku,name,category_id,unit', 'branch:id,code,name'])
            ->orderByRaw("{$column} {$direction} NULLS LAST")
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /** @param array<string, mixed> $filters */
    public function filtered(ForecastRun $run, array $filters): Builder
    {
        return Recommendation::query()
            ->where('forecast_run_id', $run->id)
            ->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($filters['risk_level'] ?? null, fn ($q, $v) => $q->whereIn('risk_level', (array) $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->whereHas('product', fn ($p) => $p->where('category_id', $v)))
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                $term = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
                $q->whereHas('product', fn ($p) => $p->where(fn ($w) => $w->where('name', 'ilike', $term)->orWhere('sku', 'ilike', $term)));
            });
    }

    /** @return array{low: int, medium: int, high: int, critical: int} */
    public function riskCounts(ForecastRun $run, ?int $branchId = null): array
    {
        $counts = Recommendation::query()
            ->where('forecast_run_id', $run->id)
            ->when($branchId, fn ($q, $v) => $q->where('branch_id', $v))
            ->selectRaw('risk_level, COUNT(*) AS n')
            ->groupBy('risk_level')
            ->pluck('n', 'risk_level');

        return [
            'low' => (int) ($counts['low'] ?? 0),
            'medium' => (int) ($counts['medium'] ?? 0),
            'high' => (int) ($counts['high'] ?? 0),
            'critical' => (int) ($counts['critical'] ?? 0),
        ];
    }

    /** Dinero que se inmovilizaría si se compraran todas las cantidades sugeridas. */
    public function purchaseInvestment(ForecastRun $run, ?int $branchId = null): ?float
    {
        $value = Recommendation::query()
            ->where('forecast_run_id', $run->id)
            ->when($branchId, fn ($q, $v) => $q->where('branch_id', $v))
            ->where('suggested_qty', '>', 0)
            ->sum('estimated_investment');

        return Num::money($value);
    }

    /** @return list<array<string, mixed>> */
    public function topRisks(ForecastRun $run, ?int $branchId = null, int $limit = 5): array
    {
        return Recommendation::query()
            ->where('forecast_run_id', $run->id)
            ->when($branchId, fn ($q, $v) => $q->where('branch_id', $v))
            ->whereIn('risk_level', ['high', 'critical'])
            ->with(['product:id,sku,name', 'branch:id,name'])
            ->orderByRaw('stockout_probability DESC NULLS LAST')
            ->orderByRaw('days_of_cover ASC NULLS LAST')
            ->limit($limit)
            ->get()
            ->map(fn (Recommendation $r) => [
                'recommendation_id' => $r->id,
                'product_id' => $r->product_id,
                'branch_id' => $r->branch_id,
                'sku' => $r->product?->sku,
                'product_name' => $r->product?->name,
                'branch_name' => $r->branch?->name,
                'days_of_cover' => Num::units($r->days_of_cover),
                'stockout_probability' => Num::ratio($r->stockout_probability),
                'stockout_date_estimate' => $r->stockout_date_estimate?->toDateString(),
                'risk_level' => $r->risk_level,
                'suggested_qty' => Num::units($r->suggested_qty),
                'estimated_investment' => Num::money($r->estimated_investment),
            ])->all();
    }

    /** @return array<string, mixed> */
    public function summary(int $tenantId, ?ForecastRun $run, ?int $branchId = null): array
    {
        $snapshotDate = DB::table('inventory_snapshots')->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q, $v) => $q->where('branch_id', $v))
            ->max('snapshot_date');

        // Última existencia conocida por serie (sucursal, producto).
        $latest = DB::table('inventory_snapshots as inv')
            ->where('inv.tenant_id', $tenantId)
            ->when($branchId, fn ($q, $v) => $q->where('inv.branch_id', $v))
            ->selectRaw('DISTINCT ON (inv.branch_id, inv.product_id) inv.branch_id, inv.product_id, inv.on_hand, inv.unit_cost')
            ->orderBy('inv.branch_id')->orderBy('inv.product_id')->orderByDesc('inv.snapshot_date');

        $stock = DB::query()->fromSub($latest, 'l')
            ->join('products as p', function ($join) use ($tenantId) {
                $join->on('p.id', '=', 'l.product_id')->where('p.tenant_id', '=', $tenantId);
            })
            ->selectRaw(<<<'SQL'
                COUNT(DISTINCT l.product_id) FILTER (WHERE l.on_hand > 0) AS products_with_stock,
                COUNT(*) FILTER (WHERE l.on_hand <= 0) AS series_out_of_stock,
                COALESCE(SUM(GREATEST(l.on_hand, 0) * COALESCE(l.unit_cost, p.current_cost)), 0) AS stock_value,
                COUNT(*) FILTER (WHERE l.on_hand > 0 AND COALESCE(l.unit_cost, p.current_cost) IS NULL) AS series_without_cost
            SQL)
            ->first();

        $coverage = null;
        if ($run !== null) {
            $coverage = Recommendation::query()->where('forecast_run_id', $run->id)
                ->when($branchId, fn ($q, $v) => $q->where('branch_id', $v))
                ->whereNotNull('days_of_cover')
                ->selectRaw('percentile_cont(0.5) WITHIN GROUP (ORDER BY days_of_cover) AS median')
                ->value('median');
        }

        return [
            'snapshot_date' => $snapshotDate ? substr((string) $snapshotDate, 0, 10) : null,
            'has_inventory' => $snapshotDate !== null,
            'products_with_stock' => (int) ($stock->products_with_stock ?? 0),
            'series_out_of_stock' => (int) ($stock->series_out_of_stock ?? 0),
            'total_on_hand_value' => Num::money($stock->stock_value ?? 0),
            'series_without_cost' => (int) ($stock->series_without_cost ?? 0),
            'risk_counts' => $run ? $this->riskCounts($run, $branchId) : ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0],
            'purchase_investment' => $run ? $this->purchaseInvestment($run, $branchId) : null,
            'coverage_days_median' => $coverage !== null ? Num::units($coverage) : null,
            'run' => $run ? ['id' => $run->id, 'version' => $run->version] : null,
        ];
    }
}
