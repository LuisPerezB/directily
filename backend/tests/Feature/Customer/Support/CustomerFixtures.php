<?php

declare(strict_types=1);

namespace Tests\Feature\Customer\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;

/**
 * Datos deterministas FICTICIOS del Customer Core, insertados directamente (como los dejaría el motor).
 * Catálogo: 1 sucursal, 2 productos, 3 compradores (el 3.º es «consumidor final», genérico).
 */
trait CustomerFixtures
{
    use InteractsWithTenants;

    /** @return array{branch: int, products: array<string, int>, customers: array<string, int>} */
    protected function seedCustomerCatalog(Tenant $tenant): array
    {
        $now = now();
        $tid = $tenant->id;
        $branch = (int) DB::table('branches')->insertGetId(['tenant_id' => $tid, 'code' => '001', 'name' => 'Matriz', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $products = [];
        foreach (['p1' => ['CEM-01', 'Cemento 50kg', 'saco'], 'p2' => ['VAR-12', 'Varilla 12mm', 'unidad']] as $key => [$sku, $name, $unit]) {
            $products[$key] = (int) DB::table('products')->insertGetId([
                'tenant_id' => $tid, 'sku' => $sku, 'name' => $name, 'unit' => $unit, 'is_active' => true, 'current_price' => 8, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $customers = [];
        foreach (['c1' => ['CLI-0001', 'company', false], 'c2' => ['CLI-0002', 'person', false], 'c3' => ['9999999999999', 'final_consumer', true]] as $key => [$code, $type, $generic]) {
            $customers[$key] = (int) DB::table('customers')->insertGetId([
                'tenant_id' => $tid, 'external_code' => $code, 'customer_type' => $type, 'is_generic' => $generic, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return ['branch' => $branch, 'products' => $products, 'customers' => $customers];
    }

    /**
     * Corrida de predicción completada con predicciones para c1/c2 × p1/p2 en cada horizonte.
     *
     * @param  list<int>  $horizons
     */
    protected function seedPredictionRun(Tenant $tenant, array $cat, array $horizons = [7, 30, 90], string $asOf = '2026-10-01', bool $current = true): int
    {
        $tid = $tenant->id;
        $version = (int) DB::table('core_runs')->where('tenant_id', $tid)->where('core', 'customer')->max('version') + 1;
        $runId = (int) DB::table('core_runs')->insertGetId([
            'tenant_id' => $tid, 'core' => 'customer', 'operation' => 'predict', 'version' => $version, 'run_type' => 'production', 'status' => 'completed',
            'result_type' => 'forecast', 'quality_status' => 'ok', 'uncertainty_source' => 'empirical_holdout', 'as_of' => $asOf,
            'horizons' => json_encode($horizons), 'horizon_start' => date('Y-m-d', strtotime($asOf.' +1 day')),
            'horizon_end' => date('Y-m-d', strtotime($asOf.' +'.max($horizons).' days')), 'target_definition' => json_encode(['event' => 'purchase']),
            'options' => '{}', 'config_version' => str_repeat('c', 64),
            'model_versions' => json_encode(array_combine(array_map('strval', $horizons), array_map(fn ($h) => ['probability' => 'v1', 'ranking_source' => $h === 7 ? 'popularity' : 'model'], $horizons))),
            'assumptions' => json_encode(['Supuesto de prueba']), 'limitations' => json_encode(['Datos ficticios']), 'summary' => json_encode(['rows' => 12]),
            'is_current' => $current, 'triggered_by' => 'user', 'started_at' => now(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $rows = [];
        $base = ['c1' => ['p1' => 0.60, 'p2' => 0.20], 'c2' => ['p1' => 0.10, 'p2' => 0.45]];
        foreach ($horizons as $i => $h) {
            foreach ($base as $c => $byProduct) {
                foreach ($byProduct as $p => $prob) {
                    $prob = min(0.95, $prob + 0.05 * $i);   // P7 ≤ P30 ≤ P90
                    $qty = $p === 'p1' ? 4.0 : 2.0;
                    $rows[] = [
                        'tenant_id' => $tid, 'core_run_id' => $runId, 'customer_id' => $cat['customers'][$c], 'product_id' => $cat['products'][$p],
                        'horizon_days' => $h, 'as_of' => $asOf, 'purchase_probability' => $prob, 'probability_raw' => $prob, 'probability_coherence_adjusted' => false,
                        'calibration_status' => 'calibrated', 'quantity_if_purchase' => $qty, 'quantity_lo' => $qty * 0.5, 'quantity_hi' => $qty * 2,
                        'expected_quantity' => $prob * $qty, 'committed_quantity' => 0, 'incremental_quantity' => $prob * $qty, 'total_view_quantity' => $prob * $qty,
                        'assumed_unit_price' => 8, 'expected_amount' => $prob * $qty * 8, 'currency' => 'USD', 'median_interval_days' => 21,
                        'days_since_last_purchase' => 10, 'n_purchases' => 6, 'model_family' => 'lgbm_small', 'model_role' => 'primary',
                        'quality_flags' => json_encode([]),
                    ];
                }
            }
        }
        DB::table('customer_predictions')->insert($rows);
        $rank = [];
        foreach ($horizons as $h) {
            foreach (DB::table('customer_predictions')->where('core_run_id', $runId)->where('horizon_days', $h)->orderBy('customer_id')->orderByDesc('purchase_probability')->get() as $i => $p) {
                $rank[$p->customer_id] = ($rank[$p->customer_id] ?? 0) + 1;
                DB::table('customer_product_rankings')->insert([
                    'tenant_id' => $tid, 'core_run_id' => $runId, 'customer_id' => $p->customer_id, 'product_id' => $p->product_id, 'horizon_days' => $h,
                    'rank' => ($rank[$p->customer_id] - 1) % 2 + 1, 'purchase_probability' => $p->purchase_probability, 'expected_quantity' => $p->expected_quantity,
                    'expected_amount' => $p->expected_amount, 'currency' => 'USD',
                    'ranking_source' => $h === 7 ? 'popularity' : 'model', 'ranking_score' => $h === 7 ? 40 : $p->purchase_probability,
                ]);
            }
            $rank = [];
        }
        foreach (['c1' => ['active', 0.30, 'stable'], 'c2' => ['inactive_by_pattern', 0.97, 'decrease']] as $c => [$status, $risk, $change]) {
            DB::table('customer_activity')->insert([
                'tenant_id' => $tid, 'core_run_id' => $runId, 'customer_id' => $cat['customers'][$c], 'as_of' => $asOf, 'last_purchase_date' => '2026-09-20',
                'days_since_last_purchase' => 11, 'n_purchase_days' => 30, 'purchase_frequency_per_30d' => 2.5, 'median_interval_days' => 12, 'mean_interval_days' => 12.5,
                'interval_p75_days' => 15, 'interval_p90_days' => 20, 'n_intervals' => 24, 'inactivity_risk' => $risk, 'inactivity_status' => $status,
                'behavior_change' => $change, 'frequency_ratio' => 0.5, 'behavior_p_value' => 0.01,
            ]);
        }
        foreach ($horizons as $h) {
            foreach ([['p1', 'ok', 100.0, 30.0, 0.0, 70.0], ['p2', 'inconsistent', 10.0, 25.0, 15.0, 0.0]] as [$p, $status, $agg, $buyers, $excess, $residual]) {
                DB::table('customer_reconciliations')->insert([
                    'tenant_id' => $tid, 'core_run_id' => $runId, 'product_id' => $cat['products'][$p], 'horizon_days' => $h,
                    'aggregate_quantity' => $agg, 'aggregate_days' => $h, 'buyers_total_quantity' => $buyers, 'buyers_committed_quantity' => 5,
                    'buyers_incremental_quantity' => $buyers - 5, 'buyers_expected_quantity' => $buyers, 'n_buyers' => 2, 'committed_outside_model' => 0,
                    'residual_quantity' => $residual, 'excess_quantity' => $excess > 0 ? $excess : null, 'excess_ratio' => $excess > 0 ? $excess / $agg : null,
                    'residual_share' => $status === 'ok' ? $residual / $agg : null, 'status' => $status, 'severity' => $status === 'inconsistent' ? 'major' : null,
                ]);
            }
        }

        return $runId;
    }

    protected function seedTrainingRun(Tenant $tenant, array $horizons = [7, 30, 90], string $asOf = '2026-09-30', string $status = 'completed'): int
    {
        $tid = $tenant->id;
        $version = (int) DB::table('core_runs')->where('tenant_id', $tid)->where('core', 'customer')->max('version') + 1;
        $manifest = ['protocol_version' => 'cust-protocol-2026.10-v1', 'labels_mature_through' => $asOf, 'final_holdout_policy' => 'El holdout no participa en la elección',
            'horizons' => array_combine(array_map('strval', $horizons), array_map(fn ($h) => [
                'status' => 'trained', 'tier' => 'full', 'blocks' => ['train' => ['cutoff_from' => '2025-01-01', 'cutoff_to' => '2025-08-01', 'n_cutoffs' => 10], 'holdout' => ['cutoff_from' => '2026-01-01', 'cutoff_to' => '2026-02-01', 'n_cutoffs' => 3]],
            ], $horizons))];
        $runId = (int) DB::table('core_runs')->insertGetId([
            'tenant_id' => $tid, 'core' => 'customer', 'operation' => 'train', 'version' => $version, 'run_type' => 'production', 'status' => $status,
            'result_type' => 'evaluation', 'quality_status' => 'ok', 'uncertainty_source' => 'empirical_holdout', 'as_of' => $asOf, 'horizons' => json_encode($horizons),
            'options' => '{}', 'evaluation_manifest' => json_encode($manifest), 'model_versions' => json_encode(['7' => ['probability' => 'v1']]),
            'assumptions' => '[]', 'limitations' => '[]', 'summary' => json_encode(['duration_ms' => 1000]), 'is_current' => $status === 'completed',
            'triggered_by' => 'user', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($horizons as $h) {
            foreach ([['customer_prob_reference', true, false, 0.0900], ['customer_prob_lgbm_small', false, true, 0.0850]] as [$family, $baseline, $selected, $brier]) {
                DB::table('model_evaluations')->insert([
                    'tenant_id' => $tid, 'core' => 'customer', 'core_run_id' => $runId, 'forecast_run_id' => null, 'model_family' => $family, 'evaluation_type' => 'holdout',
                    'level' => 'overall', 'level_key' => "h{$h}:probability", 'horizon_days' => $h, 'is_baseline' => $baseline, 'is_selected' => $selected, 'n_obs' => 2000,
                    'n_windows' => 0, 'created_at' => now(),
                    'metrics' => json_encode(['n' => 2000, 'n_positive' => 300, 'brier' => $brier, 'log_loss' => 0.31, 'pr_auc' => 0.4, 'ece' => 0.02,
                        'calibration_curve' => [['n' => 1000, 'mean_predicted' => 0.05, 'observed_rate' => 0.06], ['n' => 1000, 'mean_predicted' => 0.25, 'observed_rate' => 0.24]]]),
                ]);
            }
        }
        DB::table('model_evaluations')->insert([
            'tenant_id' => $tid, 'core' => 'customer', 'core_run_id' => $runId, 'model_family' => 'customer_coherence', 'evaluation_type' => 'holdout', 'level' => 'overall',
            'level_key' => 'coherence', 'horizon_days' => null, 'is_baseline' => false, 'is_selected' => true, 'n_obs' => 0, 'n_windows' => 0, 'created_at' => now(),
            'metrics' => json_encode(['rule' => 'cumulative_max']),
        ]);

        return $runId;
    }

    protected function registerProductionModel(Tenant $tenant, int $horizon = 30): void
    {
        DB::table('model_registry')->insert([
            'tenant_id' => $tenant->id, 'version' => "t{$tenant->id}-customer-h{$horizon}-probability-reference", 'model_family' => 'customer_probability_reference',
            'strategy_level' => 'B', 'scope' => 'tenant', 'status' => 'production', 'core' => 'customer', 'role' => 'fallback',
            'hyperparameters' => json_encode(['slot' => "h{$horizon}:probability", 'kind' => 'reference']), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function seedOneSale(Tenant $tenant, array $cat, string $date): void
    {
        $saleId = (int) DB::table('sales')->insertGetId([
            'tenant_id' => $tenant->id, 'branch_id' => $cat['branch'], 'document_type' => 'invoice', 'document_number' => '001-001-'.str_pad((string) random_int(1, 999999), 9, '0', STR_PAD_LEFT),
            'status' => 'completed', 'issued_at' => $date.' 12:00:00', 'sale_date' => $date, 'currency' => 'USD', 'source_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sale_items')->insert([
            'tenant_id' => $tenant->id, 'sale_id' => $saleId, 'line_number' => 1, 'product_id' => $cat['products']['p1'], 'branch_id' => $cat['branch'], 'sale_date' => $date,
            'quantity' => 2, 'unit_price' => 8, 'discount_amount' => 0, 'net_amount' => 16, 'tax_amount' => 0, 'is_return' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
