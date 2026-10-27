<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Tenant;
use App\Services\Sales\SalesQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/** Estadísticas de ventas por producto (90 días) calculadas en una sola consulta agregada. */
final class ProductStatsService
{
    public function windowStart(Tenant $tenant, int $days = 90): string
    {
        return Carbon::now($tenant->timezone)->subDays($days)->toDateString();
    }

    /** Subconsulta agregada: product_id, units_90d, revenue_90d, last_sale_date (últimos 365 días). */
    public function subquery(Tenant $tenant, ?array $productIds = null): Builder
    {
        $from90 = $this->windowStart($tenant, 90);
        $from365 = $this->windowStart($tenant, 365);

        $q = SalesQuery::validItems($tenant->id)
            ->where('si.sale_date', '>=', $from365)
            ->groupBy('si.product_id')
            ->selectRaw('si.product_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN si.sale_date >= ? THEN si.quantity END), 0) AS units_90d', [$from90])
            ->selectRaw('COALESCE(SUM(CASE WHEN si.sale_date >= ? THEN si.net_amount END), 0) AS revenue_90d', [$from90])
            ->selectRaw('MAX(si.sale_date) AS last_sale_date');

        if ($productIds !== null) {
            $q->whereIn('si.product_id', $productIds);
        }

        return $q;
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, array{units_90d: float, revenue_90d: float, last_sale_date: ?string}>
     */
    public function forProducts(Tenant $tenant, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $rows = $this->subquery($tenant, $productIds)->get();
        $stats = [];
        foreach ($rows as $row) {
            $stats[(int) $row->product_id] = [
                'units_90d' => round((float) $row->units_90d, 4),
                'revenue_90d' => round((float) $row->revenue_90d, 2),
                'last_sale_date' => $row->last_sale_date,
            ];
        }

        return $stats;
    }
}
