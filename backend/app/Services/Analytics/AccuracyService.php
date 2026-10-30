<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\ForecastRun;
use App\Services\Analytics\Support\ForecastNode;
use App\Services\Analytics\Support\ModelCatalog;
use App\Services\Analytics\Support\Num;
use App\Services\Analytics\Support\SalesQuery;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Precisión de los pronósticos: métricas de backtesting (escritas por el motor) y
 * precisión realizada frente a ventas reales posteriores.
 */
class AccuracyService
{
    public function __construct(
        private readonly AccuracyExplainer $explainer,
        private readonly ForecastQuery $forecasts,
        private readonly HistoryQuery $history,
        private readonly HorizonSupport $support,
    ) {}

    /** @return array<string, mixed> */
    public function summary(?ForecastRun $run): array
    {
        $backtest = $run ? $this->backtest($run) : null;
        $realized = $this->realized((int) ($run?->tenant_id ?? app(TenantContext::class)->id()));
        $data = $run ? $this->dataContext((int) $run->tenant_id) : ['has_inventory' => false, 'censored_days' => 0, 'censored_series' => 0];

        return [
            'run' => $run ? [
                'id' => $run->id,
                'version' => $run->version,
                'run_type' => $run->run_type,
                'cutoff_date' => $run->cutoff_date?->toDateString(),
                'completed_at' => $run->completed_at?->toIso8601String(),
            ] : null,
            'backtest' => $backtest,
            'realized' => $realized,
            // Etapa 2 (aditivo): protocolo de evaluación de la ejecución (E2-06) y respaldo de cada horizonte (E2-21).
            'evaluation_manifest' => $run?->evaluation_manifest,
            'horizon_support' => $run ? $this->support->all($run) : null,
            'p50' => $run ? ($run->summary['p50'] ?? null) : null,
            'explanation' => $this->explainer->explain([
                'backtest' => $backtest ?? [],
                'realized' => $realized,
                'data' => $data,
            ]),
        ];
    }

    /** @return array<string, mixed> */
    public function backtest(ForecastRun $run): array
    {
        $rows = DB::table('model_evaluations')
            ->where('tenant_id', $run->tenant_id)
            ->where('forecast_run_id', $run->id)
            ->where('evaluation_type', 'backtest')
            ->whereIn('level', ['overall', 'horizon', 'horizon_total', 'demand_class', 'branch'])
            ->get();

        $overall = $rows->where('level', 'overall');
        $selected = $this->pickSelected($overall);
        $baseline = $this->pickBaseline($overall);

        $byHorizon = $rows->where('level', 'horizon')->groupBy(fn ($r) => (string) ($r->horizon_days ?? $r->level_key))
            ->map(function (Collection $group) {
                $sel = $this->pickSelected($group);
                $base = $this->pickBaseline($group);
                $first = $group->first();

                return [
                    'horizon_days' => $first->horizon_days !== null ? (int) $first->horizon_days : null,
                    'level_key' => $first->level_key,
                    'wape' => Num::ratio($sel?->wape),
                    'baseline_wape' => Num::ratio($base?->wape),
                    'n_obs' => (int) ($sel->n_obs ?? 0),
                ];
            })->sortBy(fn ($r) => $r['horizon_days'] ?? PHP_INT_MAX)->values()->all();

        $byClass = $rows->where('level', 'demand_class')->groupBy('level_key')
            ->map(function (Collection $group, $class) {
                $sel = $this->pickSelected($group);
                $base = $this->pickBaseline($group);

                return [
                    'demand_class' => $class,
                    'label' => ModelCatalog::demandClass($class),
                    'model_family' => $sel?->model_family,
                    'model_label' => ModelCatalog::label($sel?->model_family),
                    'wape' => Num::ratio($sel?->wape),
                    'baseline_wape' => Num::ratio($base?->wape),
                    'n_obs' => (int) ($sel->n_obs ?? 0),
                ];
            })->values()->all();

        $branchNames = DB::table('branches')->where('tenant_id', $run->tenant_id)->pluck('name', 'id');
        $byBranch = $rows->where('level', 'branch')->groupBy('level_key')
            ->map(function (Collection $group, $key) use ($branchNames) {
                $sel = $this->pickSelected($group);
                $branchId = (int) preg_replace('/\D+/', '', (string) $key);

                return [
                    'branch_id' => $branchId ?: null,
                    'branch_name' => $branchNames[$branchId] ?? null,
                    'wape' => Num::ratio($sel?->wape),
                    'mae' => Num::units($sel?->mae),
                    'bias' => Num::ratio($sel?->bias),
                    'n_obs' => (int) ($sel->n_obs ?? 0),
                ];
            })->values()->all();

        $models = $overall->map(fn ($r) => [
            'model_family' => $r->model_family,
            'label' => ModelCatalog::label($r->model_family),
            'wape' => Num::ratio($r->wape),
            'mae' => Num::units($r->mae),
            'rmse' => Num::units($r->rmse),
            'bias' => Num::ratio($r->bias),
            'coverage_80' => Num::ratio($r->coverage_80),
            'is_baseline' => (bool) $r->is_baseline,
            'is_selected' => (bool) $r->is_selected,
        ])->sortBy(fn ($r) => $r['wape'] ?? INF)->values()->all();

        return [
            'selected' => $selected ? $this->metrics($selected) : null,
            'baseline' => $baseline ? $this->metrics($baseline) : null,
            'n_windows' => (int) ($selected->n_windows ?? $overall->max('n_windows') ?? 0),
            // 'holdout' = período final reservado (no eligió el modelo ni calibró sus rangos);
            // 'selection_windows' = mismas ventanas que eligieron el modelo (optimista); null = run antiguo.
            'evaluation_scope' => $this->scope($selected ?? $baseline),
            'cutoffs' => $this->jsonList($selected?->cutoffs ?? $baseline?->cutoffs ?? null),
            'by_horizon' => $byHorizon,
            // Etapa 2: error del TOTAL de H días (7/14/30/60/90) por serie y ventana, junto al del naive estacional.
            'by_horizon_total' => $this->horizonTotals($rows),
            'by_demand_class' => $byClass,
            'by_branch' => $byBranch,
            'models' => $models,
            'selection_windows' => $this->selectionWindows($run),
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function horizonTotals(Collection $rows): array
    {
        return $rows->where('level', 'horizon_total')->groupBy(fn ($r) => (string) $r->horizon_days)
            ->map(function (Collection $group) {
                $sel = $this->pickSelected($group);
                $base = $this->pickBaseline($group);
                $metrics = $this->decode($sel?->metrics);

                return [
                    'horizon_days' => (int) ($sel->horizon_days ?? $base->horizon_days ?? 0),
                    'wape' => Num::ratio($sel?->wape),
                    'baseline_wape' => Num::ratio($base?->wape),
                    'bias' => Num::ratio($sel?->bias),
                    'mae' => Num::units($sel?->mae),
                    'money' => $metrics['money'] ?? null,
                    'n_obs' => (int) ($sel->n_obs ?? 0),
                ];
            })->sortBy('horizon_days')->values()->all();
    }

    /** @return array<string, mixed> */
    private function decode(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : $json;

        return is_array($value) ? $value : [];
    }

    /**
     * Métricas secundarias de las ventanas que eligieron el modelo (evaluation_type =
     * 'selection_windows'). Solo existen cuando el motor reservó un período final independiente.
     *
     * @return array<string, mixed>|null
     */
    private function selectionWindows(ForecastRun $run): ?array
    {
        $overall = DB::table('model_evaluations')
            ->where('tenant_id', $run->tenant_id)
            ->where('forecast_run_id', $run->id)
            ->where('evaluation_type', 'selection_windows')
            ->where('level', 'overall')
            ->get();
        $selected = $this->pickSelected($overall);
        $baseline = $this->pickBaseline($overall);
        if ($selected === null && $baseline === null) {
            return null;
        }

        return [
            'selected' => $selected ? $this->metrics($selected) : null,
            'baseline' => $baseline ? $this->metrics($baseline) : null,
            'n_windows' => (int) (($selected ?? $baseline)->n_windows ?? 0),
            'cutoffs' => $this->jsonList(($selected ?? $baseline)->cutoffs ?? null),
        ];
    }

    private function scope(?object $row): ?string
    {
        $extra = is_string($row?->extra ?? null) ? json_decode($row->extra, true) : ($row?->extra ?? null);
        $scope = is_array($extra) ? ($extra['evaluation_scope'] ?? null) : null;

        return in_array($scope, ['holdout', 'selection_windows'], true) ? $scope : null;
    }

    /** @return list<string> */
    private function jsonList(mixed $value): array
    {
        $list = is_string($value) ? json_decode($value, true) : $value;

        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }

    /**
     * Precisión realizada (fuera de muestra) a partir de forecast_actuals, excluyendo
     * días censurados por quiebre de stock.
     *
     * @return array<string, mixed>|null
     */
    public function realized(int $tenantId, int $lookbackRuns = 10): ?array
    {
        $level = DB::table('forecast_actuals')->where('tenant_id', $tenantId)
            ->whereIn('level', ['series', 'product', 'total'])
            ->selectRaw("MIN(CASE level WHEN 'series' THEN 1 WHEN 'product' THEN 2 ELSE 3 END) AS rank")
            ->value('rank');
        if ($level === null) {
            return null;
        }
        $levelName = [1 => 'series', 2 => 'product', 3 => 'total'][(int) $level];

        $byRun = DB::table('forecast_actuals as fa')
            ->join('forecast_runs as r', function ($join) {
                $join->on('r.id', '=', 'fa.forecast_run_id')->on('r.tenant_id', '=', 'fa.tenant_id');
            })
            ->where('fa.tenant_id', $tenantId)
            ->where('fa.level', $levelName)
            ->where('fa.is_censored', false)
            ->groupBy('fa.forecast_run_id', 'r.version', 'r.cutoff_date')
            ->selectRaw('fa.forecast_run_id, r.version, r.cutoff_date, SUM(fa.abs_error) AS abs_err, SUM(fa.actual) AS actual, SUM(fa.yhat - fa.actual) AS err, COUNT(*) AS n')
            ->orderByDesc('fa.forecast_run_id')
            ->limit($lookbackRuns)
            ->get();
        if ($byRun->isEmpty()) {
            return null;
        }

        $absErr = (float) $byRun->sum('abs_err');
        $actual = (float) $byRun->sum('actual');
        $n = (int) $byRun->sum('n');

        return [
            'level' => $levelName,
            'wape' => $actual > 0 ? Num::ratio($absErr / $actual) : null,
            'mae' => $n > 0 ? Num::units($absErr / $n) : null,
            'bias' => $actual > 0 ? Num::ratio((float) $byRun->sum('err') / $actual) : null,
            'n_obs' => $n,
            'by_run' => $byRun->map(fn ($r) => [
                'run_id' => (int) $r->forecast_run_id,
                'version' => (int) $r->version,
                'cutoff_date' => $r->cutoff_date ? substr((string) $r->cutoff_date, 0, 10) : null,
                'wape' => (float) $r->actual > 0 ? Num::ratio((float) $r->abs_err / (float) $r->actual) : null,
                'n_obs' => (int) $r->n,
            ])->values()->all(),
        ];
    }

    /**
     * Pronóstico de una ejecución pasada frente a lo realmente vendido.
     *
     * @return array<string, mixed>
     */
    public function comparison(ForecastRun $run, ForecastNode $node): array
    {
        $forecast = $this->forecasts->series($run, $node, 'day', (int) $run->horizon_days)['points'];
        $tenantId = (int) $run->tenant_id;
        $lastSale = SalesQuery::lastSaleDate($tenantId);
        $points = [];
        $metrics = ['abs_err' => 0.0, 'actual' => 0.0, 'err' => 0.0, 'n' => 0];

        if ($forecast !== [] && $lastSale !== null) {
            $from = CarbonImmutable::parse($forecast[0]['date']);
            $to = CarbonImmutable::parse(end($forecast)['date']);
            $observedTo = $to->min(CarbonImmutable::parse($lastSale));
            $actuals = [];
            $stockouts = [];
            if ($observedTo->greaterThanOrEqualTo($from)) {
                foreach ($this->history->series($tenantId, $node, 'day', $from, $observedTo) as $h) {
                    $actuals[$h['date']] = $h['units'];
                    $stockouts[$h['date']] = $h['stockout'];
                }
            }
            foreach ($forecast as $p) {
                $actual = $actuals[$p['date']] ?? null;
                $censored = (bool) ($stockouts[$p['date']] ?? false);
                $points[] = [
                    'date' => $p['date'],
                    'yhat' => $p['yhat'],
                    'lo_80' => $p['lo_80'],
                    'hi_80' => $p['hi_80'],
                    'actual' => $actual,
                    'censored' => $censored,
                ];
                if ($actual !== null && ! $censored) {
                    $metrics['abs_err'] += abs($p['yhat'] - $actual);
                    $metrics['actual'] += $actual;
                    $metrics['err'] += $p['yhat'] - $actual;
                    $metrics['n']++;
                }
            }
        } else {
            foreach ($forecast as $p) {
                $points[] = ['date' => $p['date'], 'yhat' => $p['yhat'], 'lo_80' => $p['lo_80'], 'hi_80' => $p['hi_80'], 'actual' => null, 'censored' => false];
            }
        }

        return [
            'run' => ['id' => $run->id, 'version' => $run->version, 'cutoff_date' => $run->cutoff_date?->toDateString()],
            'node' => ['key' => $node->key, 'level' => $node->level],
            'points' => $points,
            'metrics' => [
                'wape' => $metrics['actual'] > 0 ? Num::ratio($metrics['abs_err'] / $metrics['actual']) : null,
                'mae' => $metrics['n'] > 0 ? Num::units($metrics['abs_err'] / $metrics['n']) : null,
                'bias' => $metrics['actual'] > 0 ? Num::ratio($metrics['err'] / $metrics['actual']) : null,
                'n_obs' => $metrics['n'],
            ],
        ];
    }

    /** @return array{has_inventory: bool, censored_days: int, censored_series: int} */
    public function dataContext(int $tenantId): array
    {
        $hasInventory = DB::table('inventory_snapshots')->where('tenant_id', $tenantId)->exists();
        $censored = DB::table('forecasting_series')->where('tenant_id', $tenantId)
            ->selectRaw('COALESCE(SUM(censored_days), 0) AS days, COUNT(*) FILTER (WHERE censored_days > 0) AS series')
            ->first();

        return [
            'has_inventory' => $hasInventory,
            'censored_days' => (int) ($censored->days ?? 0),
            'censored_series' => (int) ($censored->series ?? 0),
        ];
    }

    private function pickSelected(Collection $rows): ?object
    {
        $selected = $rows->where('is_selected', true);
        if ($selected->isEmpty()) {
            return null;
        }

        return $selected->first(fn ($r) => ModelCatalog::isComposite($r->model_family))
            ?? $selected->sortByDesc('n_obs')->first();
    }

    private function pickBaseline(Collection $rows): ?object
    {
        $baselines = $rows->where('is_baseline', true);
        if ($baselines->isEmpty()) {
            return null;
        }

        return $baselines->first(fn ($r) => $r->model_family === 'seasonal_naive')
            ?? $baselines->sortBy(fn ($r) => $r->wape ?? INF)->first();
    }

    /** @return array<string, mixed> */
    private function metrics(object $r): array
    {
        $extra = $this->decode($r->metrics ?? null);

        return [
            // Etapa 2 (aditivo): MASE/RMSSE con escala de entrenamiento, pinball de P10/P50/P90 (P50 null si no se
            // publicó), amplitud media de los intervalos, métricas en dinero aparte de las de unidades y muestra.
            'mase' => Num::ratio($extra['mase'] ?? null),
            'rmsse' => Num::ratio($extra['rmsse'] ?? null),
            'pinball' => [
                'p10' => Num::units($extra['pinball_p10'] ?? null),
                'p50' => Num::units($extra['pinball_p50'] ?? null),
                'p90' => Num::units($extra['pinball_p90'] ?? null),
            ],
            'interval_width' => [
                '80' => Num::units($extra['interval_width_80'] ?? null),
                '95' => Num::units($extra['interval_width_95'] ?? null),
            ],
            'money' => $extra['money'] ?? null,
            'sample' => $extra['sample'] ?? null,
            'model_family' => $r->model_family,
            'label' => ModelCatalog::label($r->model_family),
            'wape' => Num::ratio($r->wape),
            'mae' => Num::units($r->mae),
            'rmse' => Num::units($r->rmse),
            'bias' => Num::ratio($r->bias),
            'coverage_80' => Num::ratio($r->coverage_80),
            'coverage_95' => Num::ratio($r->coverage_95),
            'n_obs' => (int) $r->n_obs,
        ];
    }
}
