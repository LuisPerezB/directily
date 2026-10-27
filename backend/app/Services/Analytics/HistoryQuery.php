<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Services\Analytics\Support\ForecastNode;
use App\Services\Analytics\Support\Granularity;
use App\Services\Analytics\Support\SalesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Historial de ventas agregado en SQL por nodo y granularidad, con días sin ventas
 * completados en 0 y marca de quiebre de stock (venta potencialmente censurada).
 */
class HistoryQuery
{
    /**
     * @return list<array{date: string, units: float, revenue: float, stockout: bool, stockout_share: float|null}>
     */
    public function series(int $tenantId, ForecastNode $node, string $granularity, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($to->lessThan($from)) {
            return [];
        }
        $trunc = Granularity::truncSql($granularity, 'si.sale_date');
        $aggregated = SalesQuery::applyNode(SalesQuery::validItems($tenantId), $tenantId, $node)
            ->whereBetween('si.sale_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("{$trunc} AS period, SUM(si.quantity) AS units, SUM(si.net_amount) AS revenue")
            ->groupByRaw('1');

        $step = match ($granularity) {
            Granularity::WEEK => '1 week',
            Granularity::MONTH => '1 month',
            default => '1 day',
        };
        $start = Granularity::periodStart($granularity, $from)->toDateString();
        $end = Granularity::periodStart($granularity, $to)->toDateString();

        $rows = DB::query()
            ->fromRaw('generate_series(?::date, ?::date, ?::interval) AS g(period)', [$start, $end, $step])
            ->leftJoinSub($aggregated, 'a', fn ($join) => $join->on('a.period', '=', DB::raw('g.period::date')))
            ->selectRaw('g.period::date AS period, COALESCE(a.units, 0) AS units, COALESCE(a.revenue, 0) AS revenue')
            ->orderBy('period')
            ->get();

        $stockouts = $this->stockoutsByPeriod($tenantId, $node, $granularity, $from, $to);

        return $rows->map(function ($row) use ($stockouts) {
            $period = substr((string) $row->period, 0, 10);
            $flag = $stockouts[$period] ?? null;

            return [
                'date' => $period,
                'units' => round((float) $row->units, 4),
                'revenue' => round((float) $row->revenue, 2),
                'stockout' => (bool) ($flag['stockout'] ?? false),
                'stockout_share' => $flag['share'] ?? null,
            ];
        })->values()->all();
    }

    /** Suma de unidades e ingresos en un rango (para comparaciones de período). */
    public function totals(int $tenantId, ForecastNode $node, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = SalesQuery::applyNode(SalesQuery::validItems($tenantId), $tenantId, $node)
            ->whereBetween('si.sale_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(si.quantity), 0) AS units, COALESCE(SUM(si.net_amount), 0) AS revenue')
            ->first();

        return ['units' => (float) ($row->units ?? 0), 'revenue' => (float) ($row->revenue ?? 0)];
    }

    /**
     * Quiebres de stock por período según inventory_snapshots.
     * Serie/producto: verdadero si algún día hubo existencias <= 0.
     * Niveles agregados: proporción de series sin stock (promedio de los días del período).
     *
     * @return array<string, array{stockout: bool, share: float|null}>
     */
    public function stockoutsByPeriod(int $tenantId, ForecastNode $node, string $granularity, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = DB::table('inventory_snapshots as inv')
            ->where('inv.tenant_id', $tenantId)
            ->whereBetween('inv.snapshot_date', [$from->toDateString(), $to->toDateString()]);
        $this->applyInventoryFilters($query, $tenantId, $node);

        $daily = $query
            ->selectRaw('inv.snapshot_date AS day, COUNT(*) AS n, SUM(CASE WHEN inv.on_hand <= 0 THEN 1 ELSE 0 END) AS out_n')
            ->groupBy('inv.snapshot_date')
            ->get();

        $periods = [];
        foreach ($daily as $row) {
            $day = CarbonImmutable::parse((string) $row->day);
            $key = Granularity::periodStart($granularity, $day)->toDateString();
            $share = (int) $row->n > 0 ? ((int) $row->out_n) / ((int) $row->n) : 0.0;
            $periods[$key]['shares'][] = $share;
            $periods[$key]['any'] = ($periods[$key]['any'] ?? false) || (int) $row->out_n > 0;
        }

        $result = [];
        foreach ($periods as $key => $data) {
            $avg = array_sum($data['shares']) / max(1, count($data['shares']));
            $result[$key] = [
                'stockout' => $node->isItemLevel() ? $data['any'] : false,
                'share' => $node->isItemLevel() ? null : round($avg, 4),
            ];
        }

        return $result;
    }

    private function applyInventoryFilters(Builder $query, int $tenantId, ForecastNode $node): void
    {
        if ($node->branchId !== null) {
            $query->where('inv.branch_id', $node->branchId);
        }
        if ($node->productId !== null) {
            $query->where('inv.product_id', $node->productId);
        } elseif ($node->categoryId !== null) {
            $query->whereExists(function (Builder $q) use ($tenantId, $node) {
                $q->selectRaw('1')->from('products as pf')
                    ->whereColumn('pf.id', 'inv.product_id')
                    ->where('pf.tenant_id', $tenantId)
                    ->where('pf.category_id', $node->categoryId);
            });
        }
    }
}
