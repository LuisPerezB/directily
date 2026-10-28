<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\ForecastRun;
use App\Services\Analytics\Support\ForecastNode;
use App\Services\Analytics\Support\Num;
use App\Services\Analytics\Support\SalesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ranking de productos/categorías: unidades pronosticadas en el horizonte frente a las
 * vendidas en el período histórico equivalente (mismo número de días previos al corte).
 * Todo se agrega en SQL; los datos auxiliares se cargan en bloque (sin N+1).
 */
class ProductForecastQuery
{
    /** Umbral de variación para clasificar la tendencia como subida o bajada. */
    public const TREND_THRESHOLD = 0.05;

    /**
     * Base mínima (unidades del período histórico equivalente) para que un cambio
     * porcentual se considere informativo. Con 1 unidad vendida, pasar a 4 es "+300 %"
     * pero no indica una tendencia: esos productos se marcan como low_base, se ordenan
     * al final por variación y no aparecen entre los que más suben/bajan del dashboard.
     */
    public const MIN_BASE_UNITS = 10;

    /** Ordenamientos permitidos (lista blanca) → expresión SQL. */
    private const SORTS = [
        'change_pct' => '(history_units < '.self::MIN_BASE_UNITS.') ASC, change_pct ASC NULLS LAST',
        '-change_pct' => '(history_units < '.self::MIN_BASE_UNITS.') ASC, change_pct DESC NULLS LAST',
        'forecast_units' => 'forecast_units ASC',
        '-forecast_units' => 'forecast_units DESC',
        'revenue' => 'forecast_revenue ASC NULLS LAST',
        '-revenue' => 'forecast_revenue DESC NULLS LAST',
        'name' => 'name ASC',
    ];

    public static function sorts(): array
    {
        return array_keys(self::SORTS);
    }

    /**
     * @param  array{branch_id?: int|null, category_id?: int|null, search?: string|null, min_history_units?: float|null}  $filters
     */
    public function productsQuery(ForecastRun $run, int $horizon, array $filters, string $sort = '-forecast_units'): Builder
    {
        $tenantId = (int) $run->tenant_id;
        $branchId = $filters['branch_id'] ?? null;
        [$from, $to] = $this->historyWindow($run, $horizon);

        $forecast = DB::table('forecast_values as fv')
            ->where('fv.tenant_id', $tenantId)
            ->where('fv.forecast_run_id', $run->id)
            ->where('fv.level', $branchId !== null ? ForecastNode::LEVEL_SERIES : ForecastNode::LEVEL_PRODUCT)
            ->where('fv.granularity', 'day')
            ->where('fv.horizon_step', '<=', $horizon)
            ->when($branchId !== null, fn ($q) => $q->where('fv.branch_id', $branchId))
            ->selectRaw('fv.product_id, SUM(fv.yhat) AS forecast_units, SUM(fv.revenue_yhat) AS forecast_revenue')
            ->groupBy('fv.product_id');

        $history = SalesQuery::applyFilters(SalesQuery::validItems($tenantId), $tenantId, $branchId, null, null)
            ->whereBetween('si.sale_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('si.product_id, SUM(si.quantity) AS history_units')
            ->groupBy('si.product_id');

        $query = DB::query()
            ->fromSub($forecast, 'f')
            ->join('products as p', function ($join) use ($tenantId) {
                $join->on('p.id', '=', 'f.product_id')->where('p.tenant_id', '=', $tenantId);
            })
            ->leftJoin('product_categories as c', function ($join) use ($tenantId) {
                $join->on('c.id', '=', 'p.category_id')->where('c.tenant_id', '=', $tenantId);
            })
            ->leftJoinSub($history, 'h', 'h.product_id', '=', 'f.product_id')
            ->selectRaw(<<<'SQL'
                p.id AS product_id, p.sku, p.name, p.category_id, c.name AS category_name,
                f.forecast_units, f.forecast_revenue, COALESCE(h.history_units, 0) AS history_units,
                CASE WHEN COALESCE(h.history_units, 0) > 0
                     THEN (f.forecast_units - h.history_units) / h.history_units END AS change_pct
            SQL)
            ->when(($filters['category_id'] ?? null) !== null, fn ($q) => $q->where('p.category_id', $filters['category_id']))
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                $term = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
                $q->where(fn ($w) => $w->where('p.name', 'ilike', $term)->orWhere('p.sku', 'ilike', $term));
            })
            ->when(($filters['min_history_units'] ?? null) !== null, fn ($q) => $q->whereRaw('COALESCE(h.history_units, 0) >= ?', [$filters['min_history_units']]));

        return DB::query()->fromSub($query, 'ranked')
            ->orderByRaw(self::SORTS[$sort] ?? self::SORTS['-forecast_units'])
            ->orderBy('product_id');
    }

    /** Página de productos enriquecida (modelo, clase de demanda, WAPE de backtest). */
    public function products(ForecastRun $run, int $horizon, array $filters, string $sort, int $perPage, int $page): LengthAwarePaginator
    {
        $paginator = $this->productsQuery($run, $horizon, $filters, $sort)->paginate($perPage, ['*'], 'page', $page);
        $items = collect($paginator->items());
        $paginator->setCollection($this->enrichProducts($run, $items, $filters['branch_id'] ?? null, $horizon));

        return $paginator;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function top(ForecastRun $run, int $horizon, ?int $branchId, string $direction, int $limit = 5): Collection
    {
        $sort = $direction === 'up' ? '-change_pct' : 'change_pct';
        $rows = $this->productsQuery($run, $horizon, ['branch_id' => $branchId, 'min_history_units' => self::MIN_BASE_UNITS], $sort)
            ->when($direction === 'up', fn ($q) => $q->where('change_pct', '>', self::TREND_THRESHOLD))
            ->when($direction === 'down', fn ($q) => $q->where('change_pct', '<', -self::TREND_THRESHOLD))
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'product_id' => (int) $r->product_id,
            'sku' => $r->sku,
            'name' => $r->name,
            'category' => $r->category_name,
            'history_units' => Num::units($r->history_units),
            'forecast_units' => Num::units($r->forecast_units),
            'change_pct' => Num::ratio($r->change_pct),
        ])->values();
    }

    /**
     * @param  array{branch_id?: int|null, search?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function categories(ForecastRun $run, int $horizon, array $filters, string $sort = '-forecast_units'): array
    {
        $tenantId = (int) $run->tenant_id;
        $branchId = $filters['branch_id'] ?? null;
        [$from, $to] = $this->historyWindow($run, $horizon);

        $forecast = DB::table('forecast_values as fv')
            ->where('fv.tenant_id', $tenantId)
            ->where('fv.forecast_run_id', $run->id)
            ->where('fv.level', $branchId !== null ? ForecastNode::LEVEL_BRANCH_CATEGORY : ForecastNode::LEVEL_CATEGORY)
            ->where('fv.granularity', 'day')
            ->where('fv.horizon_step', '<=', $horizon)
            ->when($branchId !== null, fn ($q) => $q->where('fv.branch_id', $branchId))
            ->selectRaw('fv.category_id, SUM(fv.yhat) AS forecast_units, SUM(fv.revenue_yhat) AS forecast_revenue')
            ->groupBy('fv.category_id');

        $history = SalesQuery::applyFilters(SalesQuery::validItems($tenantId), $tenantId, $branchId, null, null)
            ->join('products as hp', function ($join) use ($tenantId) {
                $join->on('hp.id', '=', 'si.product_id')->where('hp.tenant_id', '=', $tenantId);
            })
            ->whereBetween('si.sale_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('hp.category_id, SUM(si.quantity) AS history_units')
            ->groupBy('hp.category_id');

        $inner = DB::query()
            ->fromSub($forecast, 'f')
            ->leftJoin('product_categories as c', function ($join) use ($tenantId) {
                $join->on('c.id', '=', 'f.category_id')->where('c.tenant_id', '=', $tenantId);
            })
            ->leftJoinSub($history, 'h', 'h.category_id', '=', 'f.category_id')
            ->selectRaw(<<<'SQL'
                f.category_id, COALESCE(c.name, 'Sin categoría') AS name,
                f.forecast_units, f.forecast_revenue, COALESCE(h.history_units, 0) AS history_units,
                CASE WHEN COALESCE(h.history_units, 0) > 0
                     THEN (f.forecast_units - h.history_units) / h.history_units END AS change_pct
            SQL)
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                $q->where('c.name', 'ilike', '%'.addcslashes((string) $filters['search'], '%_\\').'%');
            });

        // Subconsulta para poder ordenar por expresiones sobre alias (p. ej. base mínima).
        $rows = DB::query()->fromSub($inner, 'ranked')
            ->orderByRaw(self::SORTS[$sort] ?? self::SORTS['-forecast_units'])
            ->get();

        return $rows->map(fn ($r) => [
            'category' => ['id' => $r->category_id !== null ? (int) $r->category_id : null, 'name' => $r->name],
            'history_units' => Num::units($r->history_units),
            'forecast_units' => Num::units($r->forecast_units),
            'change_pct' => Num::ratio($r->change_pct),
            'trend' => self::trend($r->change_pct !== null ? (float) $r->change_pct : null, (float) $r->history_units, (float) $r->forecast_units),
            'forecast_revenue' => Num::money($r->forecast_revenue),
        ])->values()->all();
    }

    /** @return list<string> */
    public static function evaluationKeys(int $productId, ?int $branchId): array
    {
        return $branchId !== null
            ? ["b{$branchId}-p{$productId}", "series:{$branchId}:{$productId}"]
            : ["p{$productId}", "product:{$productId}"];
    }

    public static function trend(?float $changePct, float $historyUnits, float $forecastUnits): string
    {
        if ($changePct === null) {
            return $historyUnits <= 0 && $forecastUnits > 0 ? 'up' : 'flat';
        }

        return match (true) {
            $changePct > self::TREND_THRESHOLD => 'up',
            $changePct < -self::TREND_THRESHOLD => 'down',
            default => 'flat',
        };
    }

    /**
     * Ventana histórica equivalente: los H días que terminan en el corte de la ejecución.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function historyWindow(ForecastRun $run, int $horizon): array
    {
        $cutoff = $run->cutoff_date
            ? CarbonImmutable::parse($run->cutoff_date->toDateString())
            : CarbonImmutable::parse(SalesQuery::lastSaleDate((int) $run->tenant_id) ?? now()->toDateString());

        return [$cutoff->subDays($horizon - 1), $cutoff];
    }

    /**
     * @param  Collection<int, object>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function enrichProducts(ForecastRun $run, Collection $items, ?int $branchId, ?int $horizon = null): Collection
    {
        $productIds = $items->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        if ($productIds === []) {
            return collect();
        }
        $tenantId = (int) $run->tenant_id;

        // Modelo predominante de las series del producto (la de mayor volumen pronosticado).
        $models = DB::table('forecast_values')
            ->where('tenant_id', $tenantId)
            ->where('forecast_run_id', $run->id)
            ->where('level', ForecastNode::LEVEL_SERIES)
            ->where('granularity', 'day')
            ->where('horizon_step', 1)
            ->whereIn('product_id', $productIds)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('DISTINCT ON (product_id) product_id, model_family')
            ->orderBy('product_id')
            ->orderByDesc('yhat')
            ->pluck('model_family', 'product_id');

        $classes = DB::table('forecasting_series')
            ->where('tenant_id', $tenantId)
            ->whereIn('product_id', $productIds)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('DISTINCT ON (product_id) product_id, demand_class')
            ->orderBy('product_id')
            ->orderByDesc('total_units')
            ->pluck('demand_class', 'product_id');

        // El motor puede usar "p45"/"b3-p45" (unique_id) o el node_key ("product:45"/"series:3:45").
        $keys = [];
        foreach ($productIds as $id) {
            array_push($keys, ...self::evaluationKeys($id, $branchId));
        }
        $wapes = DB::table('model_evaluations')
            ->where('tenant_id', $tenantId)
            ->where('forecast_run_id', $run->id)
            ->where('evaluation_type', 'backtest')
            ->where('level', $branchId !== null ? 'series' : 'product')
            ->where('is_selected', true)
            ->whereIn('level_key', $keys)
            ->pluck('wape', 'level_key');

        // Intervalo del TOTAL del horizonte por producto: el motor lo publica (conformal sobre la
        // suma) en filas granularity='horizon' del nivel product. Por sucursal (nivel serie) no existe.
        $horizonIntervals = $branchId === null && $horizon !== null
            ? DB::table('forecast_values')
                ->where('tenant_id', $tenantId)
                ->where('forecast_run_id', $run->id)
                ->where('level', 'product')
                ->where('granularity', 'horizon')
                ->where('period_days', $horizon)
                ->whereIn('product_id', $productIds)
                ->get(['product_id', 'lo_80', 'hi_80'])
                ->keyBy('product_id')
            : collect();

        return $items->map(function ($r) use ($models, $classes, $wapes, $branchId, $horizonIntervals) {
            $pid = (int) $r->product_id;
            $wape = null;
            foreach (self::evaluationKeys($pid, $branchId) as $key) {
                $wape ??= $wapes[$key] ?? null;
            }
            $change = $r->change_pct !== null ? (float) $r->change_pct : null;

            return [
                'product' => [
                    'id' => $pid,
                    'sku' => $r->sku,
                    'name' => $r->name,
                    'category' => $r->category_id !== null ? ['id' => (int) $r->category_id, 'name' => $r->category_name] : null,
                ],
                'history_units' => Num::units($r->history_units),
                'forecast_units' => Num::units($r->forecast_units),
                'change_pct' => Num::ratio($change),
                'low_base' => (float) $r->history_units < self::MIN_BASE_UNITS,
                'trend' => self::trend($change, (float) $r->history_units, (float) $r->forecast_units),
                'forecast_revenue' => Num::money($r->forecast_revenue),
                // Intervalo del total del horizonte (no se deriva sumando cuantiles diarios).
                'lo_80' => Num::units($horizonIntervals->get($pid)?->lo_80),
                'hi_80' => Num::units($horizonIntervals->get($pid)?->hi_80),
                'model_family' => $models[$pid] ?? null,
                'demand_class' => $classes[$pid] ?? null,
                'wape' => Num::ratio($wape),
            ];
        })->values();
    }
}
