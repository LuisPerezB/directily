<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\ForecastRun;
use App\Services\Analytics\Support\ForecastNode;
use App\Services\Analytics\Support\Granularity;
use Illuminate\Support\Facades\DB;

/**
 * Lectura de forecast_values (escritos por el motor) por nodo, granularidad y horizonte.
 *
 * - Si el motor precalculó la granularidad pedida para el nodo, se usan esos valores con
 *   sus intervalos (calibrados por el motor).
 * - Si no, se agregan los valores diarios (yhat e ingresos) y los intervalos se devuelven
 *   null: sumar cuantiles diarios NO produce un intervalo válido del período.
 */
class ForecastQuery
{
    /**
     * @return array{points: list<array<string, mixed>>, intervals_available: bool, models: array<string, int>, model_family: string|null}
     */
    public function series(ForecastRun $run, ForecastNode $node, string $granularity, int $horizon): array
    {
        $base = DB::table('forecast_values')
            ->where('tenant_id', $run->tenant_id)
            ->where('forecast_run_id', $run->id)
            ->where('node_key', $node->key);

        $points = [];
        $intervals = false;

        if ($granularity === Granularity::DAY) {
            $rows = (clone $base)->where('granularity', 'day')
                ->where('horizon_step', '<=', $horizon)
                ->orderBy('target_date')
                ->get(['target_date', 'yhat', 'lo_80', 'hi_80', 'lo_95', 'hi_95', 'p50', 'revenue_yhat', 'period_days', 'quality_status', 'case_label']);
            $points = $rows->map(fn ($r) => $this->point($r))->all();
            $intervals = $rows->contains(fn ($r) => $r->lo_80 !== null);
        } else {
            $limitDate = $run->cutoff_date?->copy()->addDays($horizon)->toDateString();
            $pre = (clone $base)->where('granularity', $granularity)
                ->when($limitDate, fn ($q) => $q->where('target_date', '<=', $limitDate))
                ->orderBy('target_date')
                ->get(['target_date', 'yhat', 'lo_80', 'hi_80', 'lo_95', 'hi_95', 'p50', 'revenue_yhat', 'period_days', 'quality_status', 'case_label']);
            if ($pre->isNotEmpty()) {
                $points = $pre->map(fn ($r) => $this->point($r))->all();
                $intervals = $pre->contains(fn ($r) => $r->lo_80 !== null);
            } else {
                $trunc = Granularity::truncSql($granularity, 'target_date');
                $rows = (clone $base)->where('granularity', 'day')
                    ->where('horizon_step', '<=', $horizon)
                    ->selectRaw("{$trunc} AS target_date, SUM(yhat) AS yhat, SUM(revenue_yhat) AS revenue_yhat, COUNT(*) AS period_days")
                    ->groupByRaw('1')
                    ->orderByRaw('1')
                    ->get();
                $points = $rows->map(fn ($r) => [
                    'date' => substr((string) $r->target_date, 0, 10),
                    'yhat' => round((float) $r->yhat, 4),
                    'lo_80' => null, 'hi_80' => null, 'lo_95' => null, 'hi_95' => null,
                    'revenue' => $r->revenue_yhat !== null ? round((float) $r->revenue_yhat, 2) : null,
                    'period_days' => (int) $r->period_days,
                ])->all();
            }
        }

        $models = DB::table('forecast_values')
            ->where('tenant_id', $run->tenant_id)
            ->where('forecast_run_id', $run->id)
            ->where('granularity', 'day')
            ->where('horizon_step', 1)
            ->where('level', ForecastNode::LEVEL_SERIES)
            ->whereNotNull('model_family')
            ->when($node->branchId !== null, fn ($q) => $q->where('branch_id', $node->branchId))
            ->when($node->productId !== null, fn ($q) => $q->where('product_id', $node->productId))
            ->when($node->productId === null && $node->categoryId !== null, fn ($q) => $q->whereIn(
                'product_id',
                DB::table('products')->select('id')->where('tenant_id', $run->tenant_id)->where('category_id', $node->categoryId),
            ))
            ->selectRaw('model_family, COUNT(*) AS n')
            ->groupBy('model_family')
            ->orderByDesc('n')
            ->pluck('n', 'model_family')
            ->map(fn ($n) => (int) $n)
            ->all();

        $nodeModel = (clone $base)->where('granularity', 'day')->whereNotNull('model_family')->value('model_family');

        return [
            'points' => array_values($points),
            'intervals_available' => $intervals,
            'models' => $models,
            'model_family' => $nodeModel ?? (count($models) === 1 ? array_key_first($models) : null),
        ];
    }

    /**
     * Totales del horizonte para un nodo (unidades e ingresos) y, si el motor los publicó
     * como granularidad "horizon" (opcional), sus intervalos.
     *
     * @return array{units: float|null, revenue: float|null, lo_80: float|null, hi_80: float|null}
     */
    public function horizonTotals(ForecastRun $run, ForecastNode $node, int $horizon): array
    {
        $row = DB::table('forecast_values')
            ->where('tenant_id', $run->tenant_id)
            ->where('forecast_run_id', $run->id)
            ->where('node_key', $node->key)
            ->where('granularity', 'day')
            ->where('horizon_step', '<=', $horizon)
            ->selectRaw('COUNT(*) AS n, SUM(yhat) AS units, SUM(revenue_yhat) AS revenue')
            ->first();

        $interval = DB::table('forecast_values')
            ->where('tenant_id', $run->tenant_id)
            ->where('forecast_run_id', $run->id)
            ->where('node_key', $node->key)
            ->where('granularity', 'horizon')
            ->where('period_days', $horizon)
            ->first(['lo_80', 'hi_80']);

        $hasRows = $row !== null && (int) $row->n > 0;

        return [
            'units' => $hasRows ? round((float) $row->units, 4) : null,
            'revenue' => $hasRows && $row->revenue !== null ? round((float) $row->revenue, 2) : null,
            'lo_80' => $interval?->lo_80 !== null ? round((float) $interval->lo_80, 4) : null,
            'hi_80' => $interval?->hi_80 !== null ? round((float) $interval->hi_80, 4) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function point(object $r): array
    {
        return [
            'date' => substr((string) $r->target_date, 0, 10),
            'yhat' => round((float) $r->yhat, 4),
            'lo_80' => $r->lo_80 !== null ? round((float) $r->lo_80, 4) : null,
            'hi_80' => $r->hi_80 !== null ? round((float) $r->hi_80, 4) : null,
            'lo_95' => $r->lo_95 !== null ? round((float) $r->lo_95, 4) : null,
            'hi_95' => $r->hi_95 !== null ? round((float) $r->hi_95, 4) : null,
            // Etapa 2 (aditivo): P50 solo si el motor la evaluó (null si no); respaldo y caso especial de la fila.
            'p50' => ($r->p50 ?? null) !== null ? round((float) $r->p50, 4) : null,
            'quality_status' => $r->quality_status ?? null,
            'case_label' => $r->case_label ?? null,
            'revenue' => $r->revenue_yhat !== null ? round((float) $r->revenue_yhat, 2) : null,
            'period_days' => (int) ($r->period_days ?? 1),
        ];
    }
}
