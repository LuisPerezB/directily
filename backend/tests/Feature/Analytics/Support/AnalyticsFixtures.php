<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics\Support;

use App\Models\ForecastRun;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;

/**
 * Datos deterministas para pruebas analíticas (insertados directamente, como lo haría el
 * motor). Catálogo: 2 sucursales, 2 categorías, 3 productos.
 *
 * Venta diaria de la serie (b, p) = base[p] × factor[b] + 2 los sábados y domingos.
 * Pronóstico diario = promedio histórico diario × growth[p] (p1 sube 20%, p2 baja 20%, p3 estable).
 */
trait AnalyticsFixtures
{
    use InteractsWithTenants;

    /** @var array<string, float> */
    protected array $base = ['p1' => 10.0, 'p2' => 4.0, 'p3' => 1.0];

    /** @var array<string, float> */
    protected array $growth = ['p1' => 1.2, 'p2' => 0.8, 'p3' => 1.0];

    /** @var array<string, float> */
    protected array $prices = ['p1' => 8.0, 'p2' => 25.0, 'p3' => 3.5];

    /** @var array<string, float|null> */
    protected array $costs = ['p1' => 6.0, 'p2' => 18.0, 'p3' => null];

    /** @return array{branches: array<string, int>, categories: array<string, int>, products: array<string, int>, product_category: array<string, string>} */
    protected function seedCatalog(Tenant $tenant): array
    {
        $now = now();
        $tid = $tenant->id;
        $branches = [];
        foreach (['b1' => ['001', 'Matriz Quito'], 'b2' => ['002', 'Sucursal Guayaquil']] as $key => [$code, $name]) {
            $branches[$key] = (int) DB::table('branches')->insertGetId([
                'tenant_id' => $tid, 'code' => $code, 'name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $categories = [];
        foreach (['c1' => 'Cementos', 'c2' => 'Herramientas'] as $key => $name) {
            $categories[$key] = (int) DB::table('product_categories')->insertGetId([
                'tenant_id' => $tid, 'name' => $name, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $productCategory = ['p1' => 'c1', 'p2' => 'c1', 'p3' => 'c2'];
        $names = ['p1' => 'Cemento Portland 50kg', 'p2' => 'Varilla 12mm', 'p3' => 'Martillo de uña'];
        $products = [];
        foreach ($names as $key => $name) {
            $products[$key] = (int) DB::table('products')->insertGetId([
                'tenant_id' => $tid, 'category_id' => $categories[$productCategory[$key]], 'sku' => strtoupper($key).'-SKU',
                'name' => $name, 'unit' => 'unidad', 'is_active' => true, 'current_price' => $this->prices[$key],
                'current_cost' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return ['branches' => $branches, 'categories' => $categories, 'products' => $products, 'product_category' => $productCategory];
    }

    protected function branchFactor(string $branch): float
    {
        return $branch === 'b1' ? 1.0 : 0.5;
    }

    protected function dailyQty(string $branch, string $product, CarbonImmutable $day): float
    {
        return $this->base[$product] * $this->branchFactor($branch) + ($day->isWeekend() ? 2.0 : 0.0);
    }

    protected function avgDailyQty(string $branch, string $product): float
    {
        return $this->base[$product] * $this->branchFactor($branch) + 4.0 / 7.0;
    }

    /** Ventas válidas diarias + una factura anulada y una nota de crédito que deben excluirse. */
    protected function seedSales(Tenant $tenant, array $cat, string $cutoff, int $days = 120): void
    {
        $end = CarbonImmutable::parse($cutoff);
        $start = $end->subDays($days - 1);
        $tid = $tenant->id;
        $now = now();
        $seq = 1;
        $items = [];
        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            foreach ($cat['branches'] as $bKey => $branchId) {
                $saleId = DB::table('sales')->insertGetId([
                    'tenant_id' => $tid, 'branch_id' => $branchId, 'document_type' => 'invoice',
                    'document_number' => sprintf('%03d-001-%09d', $bKey === 'b1' ? 1 : 2, $seq++),
                    'status' => 'completed', 'issued_at' => $day->setTime(12, 0), 'sale_date' => $day->toDateString(),
                    'currency' => 'USD', 'source_hash' => str_repeat('a', 64), 'created_at' => $now, 'updated_at' => $now,
                ]);
                $line = 1;
                foreach ($cat['products'] as $pKey => $productId) {
                    $qty = $this->dailyQty($bKey, $pKey, $day);
                    $items[] = [
                        'tenant_id' => $tid, 'sale_id' => $saleId, 'line_number' => $line++, 'product_id' => $productId,
                        'branch_id' => $branchId, 'sale_date' => $day->toDateString(), 'quantity' => $qty,
                        'unit_price' => $this->prices[$pKey], 'discount_amount' => 0, 'net_amount' => $qty * $this->prices[$pKey],
                        'tax_amount' => 0, 'unit_cost' => $this->costs[$pKey], 'is_return' => false,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
        }
        // Factura anulada (no es demanda) y nota de crédito (devolución reportada aparte).
        foreach ([['invoice', 'voided', 'ANUL-1'], ['credit_note', 'completed', 'NC-1']] as [$type, $status, $number]) {
            $saleId = DB::table('sales')->insertGetId([
                'tenant_id' => $tid, 'branch_id' => $cat['branches']['b1'], 'document_type' => $type, 'document_number' => $number,
                'status' => $status, 'issued_at' => $end->setTime(15, 0), 'sale_date' => $end->toDateString(), 'currency' => 'USD',
                'source_hash' => str_repeat('b', 64), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $items[] = [
                'tenant_id' => $tid, 'sale_id' => $saleId, 'line_number' => 1, 'product_id' => $cat['products']['p1'],
                'branch_id' => $cat['branches']['b1'], 'sale_date' => $end->toDateString(), 'quantity' => 500,
                'unit_price' => 8, 'discount_amount' => 0, 'net_amount' => 4000, 'tax_amount' => 0, 'unit_cost' => 6,
                'is_return' => $type === 'credit_note', 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($items, 500) as $chunk) {
            DB::table('sale_items')->insert($chunk);
        }
    }

    /** Inventario: p1 sin stock en b1 el último día; resto con stock. */
    protected function seedInventory(Tenant $tenant, array $cat, string $date, int $days = 3): void
    {
        $end = CarbonImmutable::parse($date);
        $rows = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $end->subDays($i);
            foreach ($cat['branches'] as $bKey => $branchId) {
                foreach ($cat['products'] as $pKey => $productId) {
                    $onHand = ($bKey === 'b1' && $pKey === 'p1' && $i === 0) ? 0 : 50;
                    $rows[] = [
                        'tenant_id' => $tenant->id, 'branch_id' => $branchId, 'product_id' => $productId,
                        'snapshot_date' => $day->toDateString(), 'on_hand' => $onHand, 'reserved' => 0, 'in_transit' => 0,
                        'unit_cost' => $pKey === 'p3' ? null : $this->costs[$pKey], 'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            }
        }
        DB::table('inventory_snapshots')->insert($rows);
    }

    /**
     * Ejecución completada con valores de todos los niveles (día) y, opcionalmente,
     * semanas precalculadas con intervalos para el total.
     *
     * @param  array{version?: int, run_type?: string, is_current?: bool, horizon?: int, weekly_total?: bool, with_evaluations?: bool, with_recommendations?: bool}  $opts
     */
    protected function seedForecastRun(Tenant $tenant, array $cat, string $cutoff, array $opts = []): ForecastRun
    {
        $tid = $tenant->id;
        $horizon = $opts['horizon'] ?? 90;
        $cutoffDate = CarbonImmutable::parse($cutoff);
        $version = $opts['version'] ?? ((int) DB::table('forecast_runs')->where('tenant_id', $tid)->max('version') + 1);
        $isCurrent = $opts['is_current'] ?? true;
        if ($isCurrent) {
            DB::table('forecast_runs')->where('tenant_id', $tid)->update(['is_current' => false]);
        }
        $runId = (int) DB::table('forecast_runs')->insertGetId([
            'tenant_id' => $tid, 'version' => $version, 'run_type' => $opts['run_type'] ?? 'production', 'status' => 'completed',
            'triggered_by' => 'user', 'horizon_days' => $horizon, 'frequency' => 'D', 'cutoff_date' => $cutoff,
            'data_from' => $cutoffDate->subDays(119)->toDateString(), 'data_to' => $cutoff, 'feature_set_version' => 'fs-test-v1',
            'strategy' => 'auto', 'options' => json_encode(['horizons' => [7, 30, 90]]),
            'selection' => json_encode(['smooth' => ['model_family' => 'lightgbm_global'], 'fallback' => ['model_family' => 'seasonal_naive']]),
            'summary' => json_encode(['series_count' => 6]), 'is_current' => $isCurrent,
            'started_at' => now()->subMinutes(5), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $jobId = (int) DB::table('training_jobs')->insertGetId([
            'tenant_id' => $tid, 'forecast_run_id' => $runId, 'job_type' => 'forecast_pipeline', 'status' => 'succeeded',
            'progress' => 100, 'attempts' => 1, 'triggered_by' => 'user', 'params' => '{}', 'queued_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $lgbm = (int) DB::table('model_registry')->insertGetId([
            'tenant_id' => $tid, 'version' => "t{$tid}-r{$runId}-lightgbm_global", 'model_family' => 'lightgbm_global',
            'strategy_level' => 'B', 'status' => 'production', 'series_count' => 6, 'training_job_id' => $jobId,
            'forecast_run_id' => $runId, 'trained_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $values = [];
        $agg = [];
        foreach ($cat['branches'] as $bKey => $branchId) {
            foreach ($cat['products'] as $pKey => $productId) {
                $categoryId = $cat['categories'][$cat['product_category'][$pKey]];
                $daily = $this->avgDailyQty($bKey, $pKey) * $this->growth[$pKey];
                for ($step = 1; $step <= $horizon; $step++) {
                    $date = $cutoffDate->addDays($step)->toDateString();
                    $revenue = $daily * $this->prices[$pKey];
                    $values[] = $this->valueRow($tid, $runId, "series:{$branchId}:{$productId}", 'series', $branchId, $productId, $categoryId, $date, $step, $daily, $revenue, true, 'lightgbm_global', $lgbm, $this->prices[$pKey]);
                    foreach ([
                        ['total', 'total', null, null, null],
                        ["branch:{$branchId}", 'branch', $branchId, null, null],
                        ["category:{$categoryId}", 'category', null, $categoryId, null],
                        ["branch_category:{$branchId}:{$categoryId}", 'branch_category', $branchId, $categoryId, null],
                        ["product:{$productId}", 'product', null, $categoryId, $productId],
                    ] as [$key, $level, $b, $c, $p]) {
                        $agg[$key][$date] ??= ['level' => $level, 'b' => $b, 'c' => $c, 'p' => $p, 'step' => $step, 'y' => 0.0, 'r' => 0.0];
                        $agg[$key][$date]['y'] += $daily;
                        $agg[$key][$date]['r'] += $revenue;
                    }
                }
            }
        }
        foreach ($agg as $key => $dates) {
            foreach ($dates as $date => $a) {
                $values[] = $this->valueRow($tid, $runId, $key, $a['level'], $a['b'], $a['p'], $a['c'], $date, $a['step'], $a['y'], $a['r'], true, null, null, null);
            }
        }
        if ($opts['weekly_total'] ?? false) {
            $weeks = [];
            foreach ($agg['total'] as $date => $a) {
                $week = CarbonImmutable::parse($date)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
                $weeks[$week] = ($weeks[$week] ?? 0) + $a['y'];
            }
            $i = 1;
            foreach ($weeks as $week => $y) {
                $row = $this->valueRow($tid, $runId, 'total', 'total', null, null, null, $week, $i++, $y, null, true, null, null, null);
                $row['granularity'] = 'week';
                $row['period_days'] = 7;
                $values[] = $row;
            }
        }
        foreach (array_chunk($values, 1000) as $chunk) {
            DB::table('forecast_values')->insert($chunk);
        }

        if ($opts['with_evaluations'] ?? true) {
            $this->seedEvaluations($tid, $runId, $lgbm, $cat);
        }
        if ($opts['with_recommendations'] ?? true) {
            $this->seedRecommendations($tid, $runId, $cat, $cutoff);
        }
        foreach ($cat['branches'] as $bKey => $branchId) {
            foreach ($cat['products'] as $pKey => $productId) {
                DB::table('forecasting_series')->updateOrInsert(
                    ['tenant_id' => $tid, 'branch_id' => $branchId, 'product_id' => $productId],
                    ['unique_id' => "b{$branchId}-p{$productId}", 'demand_class' => $pKey === 'p3' ? 'intermittent' : 'smooth',
                        'history_days' => 120, 'nonzero_days' => 120, 'total_units' => 100 * $this->base[$pKey],
                        'censored_days' => $bKey === 'b1' && $pKey === 'p1' ? 1 : 0, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }

        return $this->withinTenant($tenant, fn () => ForecastRun::query()->findOrFail($runId));
    }

    /** @return array<string, mixed> */
    private function valueRow(int $tid, int $runId, string $key, string $level, ?int $b, ?int $p, ?int $c, string $date, int $step, float $y, ?float $revenue, bool $intervals, ?string $family, ?int $registryId, ?float $price): array
    {
        return [
            'tenant_id' => $tid, 'forecast_run_id' => $runId, 'node_key' => $key, 'level' => $level,
            'branch_id' => $b, 'product_id' => $p, 'category_id' => $c, 'granularity' => 'day', 'target_date' => $date,
            'period_days' => 1, 'horizon_step' => $step, 'yhat' => round($y, 4),
            'lo_80' => $intervals ? round($y * 0.8, 4) : null, 'hi_80' => $intervals ? round($y * 1.2, 4) : null,
            'lo_95' => $intervals ? round($y * 0.6, 4) : null, 'hi_95' => $intervals ? round($y * 1.4, 4) : null,
            'model_registry_id' => $registryId, 'model_family' => $family, 'unit_price_assumed' => $price,
            'revenue_yhat' => $revenue !== null ? round($revenue, 4) : null,
        ];
    }

    private function seedEvaluations(int $tid, int $runId, int $registryId, array $cat): void
    {
        $rows = [];
        $row = fn (array $over) => array_merge([
            'tenant_id' => $tid, 'model_registry_id' => null, 'forecast_run_id' => $runId, 'model_family' => 'lightgbm_global',
            'evaluation_type' => 'backtest', 'level' => 'overall', 'level_key' => null, 'horizon_days' => null,
            'is_baseline' => false, 'is_selected' => false, 'wape' => null, 'mae' => 1.0, 'rmse' => 1.5, 'bias' => 0.02,
            'coverage_80' => 0.81, 'coverage_95' => 0.94, 'n_obs' => 500, 'n_windows' => 3, 'created_at' => now(),
        ], $over);
        $rows[] = $row(['model_registry_id' => $registryId, 'is_selected' => true, 'wape' => 0.23]);
        $rows[] = $row(['model_family' => 'seasonal_naive', 'is_baseline' => true, 'wape' => 0.31, 'bias' => -0.01, 'coverage_80' => null]);
        $rows[] = $row(['model_family' => 'window_average', 'is_baseline' => true, 'wape' => 0.35]);
        foreach ([[7, 'h1-7', 0.18, 0.25], [30, 'h8-30', 0.24, 0.32], [90, 'h31-90', 0.29, 0.36]] as [$h, $key, $sel, $base]) {
            $rows[] = $row(['level' => 'horizon', 'level_key' => $key, 'horizon_days' => $h, 'is_selected' => true, 'wape' => $sel]);
            $rows[] = $row(['level' => 'horizon', 'level_key' => $key, 'horizon_days' => $h, 'model_family' => 'seasonal_naive', 'is_baseline' => true, 'wape' => $base]);
        }
        $rows[] = $row(['level' => 'demand_class', 'level_key' => 'smooth', 'is_selected' => true, 'wape' => 0.2]);
        $rows[] = $row(['level' => 'demand_class', 'level_key' => 'intermittent', 'model_family' => 'croston_sba', 'is_selected' => true, 'wape' => 0.55]);
        foreach ($cat['products'] as $pKey => $productId) {
            $rows[] = $row(['level' => 'product', 'level_key' => "p{$productId}", 'is_selected' => true, 'wape' => $pKey === 'p1' ? 0.15 : 0.4]);
        }
        foreach ($cat['branches'] as $branchId) {
            $rows[] = $row(['level' => 'branch', 'level_key' => "b{$branchId}", 'is_selected' => true, 'wape' => 0.22]);
        }
        DB::table('model_evaluations')->insert($rows);
    }

    private function seedRecommendations(int $tid, int $runId, array $cat, string $cutoff): void
    {
        $rows = [];
        foreach ($cat['branches'] as $bKey => $branchId) {
            foreach ($cat['products'] as $pKey => $productId) {
                $critical = $bKey === 'b1' && $pKey === 'p1';
                $rows[] = [
                    'tenant_id' => $tid, 'forecast_run_id' => $runId, 'branch_id' => $branchId, 'product_id' => $productId,
                    'type' => $critical ? 'stockout_risk' : ($pKey === 'p2' ? 'reorder' : 'no_action'),
                    'inventory_snapshot_date' => $cutoff, 'on_hand' => $critical ? 0 : 50, 'reserved' => 0, 'in_transit' => 0,
                    'available' => $critical ? 0 : 50, 'lead_time_days' => 7, 'lead_time_source' => 'tenant_default',
                    'review_period_days' => 7, 'service_level' => 0.95, 'demand_lead_time' => 100, 'demand_lead_time_std' => 10,
                    'safety_stock' => 16.45, 'reorder_point' => 116.45,
                    'suggested_qty' => $critical ? 116.45 : ($pKey === 'p2' ? 66.45 : 0),
                    'unit_cost' => $this->costs[$pKey],
                    'estimated_investment' => $this->costs[$pKey] !== null ? ($critical ? 116.45 : ($pKey === 'p2' ? 66.45 : 0)) * $this->costs[$pKey] : null,
                    'days_of_cover' => $critical ? 0 : 12.5, 'stockout_probability' => $critical ? 0.99 : ($pKey === 'p2' ? 0.4 : 0.05),
                    'stockout_date_estimate' => $critical ? $cutoff : null,
                    'risk_level' => $critical ? 'critical' : ($pKey === 'p2' ? 'medium' : 'low'), 'status' => 'open',
                    'assumptions' => '{}', 'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        DB::table('recommendations')->insert($rows);
    }
}
