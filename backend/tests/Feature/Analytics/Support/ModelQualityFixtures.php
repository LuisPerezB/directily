<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics\Support;

use Illuminate\Support\Facades\DB;

/**
 * Filas FICTICIAS con la forma que escribe el motor en V2.5 · W3 (insertadas directamente, como lo haría el motor): métricas de Demand por segmento y
 * horizonte (W3-A), decisiones de las dos políticas de promoción (W3-B), evaluación prospectiva del shadow (W3-B) y la calibración por segmento y las
 * columnas de S05 del Customer Core (W3-C). Nada de esto son pronósticos reales: sirven para probar el contrato de la API.
 */
trait ModelQualityFixtures
{
    /**
     * Fila de model_evaluations con nivel `segment` (una dimensión, un valor y un horizonte) para el modelo servido y el baseline.
     *
     * @param  array<string, mixed>  $over
     * @return list<array<string, mixed>>
     */
    protected function segmentRows(int $tid, int $runId, string $dimension, string $value, int $horizon, array $over = []): array
    {
        $insufficient = (bool) ($over['insufficient'] ?? false);
        $unstable = (bool) ($over['unstable'] ?? false);
        $wape = $insufficient || $unstable ? null : (float) ($over['wape'] ?? 0.21);
        $den = (float) ($over['denominator'] ?? 1200.0);
        $base = [
            'metrics_version' => 'demand-metrics-v1', 'segment_metrics_version' => 'demand-segment-metrics-v1', 'definitions_ref' => 'evaluation_manifest.segment_metrics',
            'segment' => ['dimension' => $dimension, 'value' => $value, 'horizon_days' => $horizon, 'horizon_basis' => 'first_h_days_cumulative', 'roles' => ['selected']],
            'status' => $insufficient ? 'insufficient_data' : 'ok',
            'insufficient_reasons' => $insufficient ? [['code' => 'few_series', 'n_series' => 1, 'min' => 3]] : [],
            'withheld' => $insufficient,
            'wape' => $wape, 'wape_status' => $unstable ? 'unstable' : 'ok', 'wape_unstable' => $unstable,
            'wape_raw' => $insufficient ? null : ($unstable ? 0.9 : $wape),
            'denominator' => ['sum_actual' => $den, 'unit' => 'unidades', 'min_required' => 30.0],
            'sum_abs_error' => $insufficient ? null : 250.5,
            'alternative_metric' => $unstable ? ['name' => 'aggregate_absolute_error', 'value' => 250.5, 'per_evaluable_day' => 1.2, 'used_because' => 'wape_unstable'] : null,
            'mase' => $insufficient ? null : 0.8, 'rmsse' => $insufficient ? null : 0.9,
            'bias' => $insufficient || $unstable ? null : 0.03,
            'pinball_p10' => $insufficient ? null : 0.4, 'pinball_p50' => null, 'pinball_p90' => $insufficient ? null : 0.5,
            'interval_width_80' => $insufficient ? null : 6.0, 'interval_width_95' => $insufficient ? null : 9.5,
            'sample' => ['n_obs' => $insufficient ? 12 : 840, 'n_series' => $insufficient ? 1 : 14, 'n_windows' => 3, 'evaluable_days' => $insufficient ? 12 : 840,
                'excluded_days' => 30, 'total_days' => $insufficient ? 42 : 870, 'evaluable_share' => $insufficient ? 0.29 : 0.9655,
                'exclusions' => ['scale_undefined' => 0, 'stockout_days' => 20, 'missing_source_days' => 6, 'closed_days' => 4]],
            'stability' => ['status' => 'ok', 'n_windows' => 3, 'per_window' => [], 'wape_mean' => 0.22, 'wape_std' => 0.04, 'wape_cv' => 0.18, 'unstable_across_windows' => false],
            'comparison' => ['baseline_family' => 'seasonal_naive', 'best_statistical_family' => 'auto_ets', 'similar_tolerance' => 0.03,
                'selected_vs_baseline' => $insufficient
                    ? ['family' => 'selection', 'verdict' => 'not_comparable', 'reason' => 'insufficient_data']
                    : ['family' => 'selection', 'wape' => $wape, 'metric' => 'wape', 'value' => $wape ?? 0.5, 'baseline_value' => 0.3, 'relative_improvement' => $wape === null ? null : round(1 - $wape / 0.3, 4),
                        'verdict' => $wape !== null && $wape < 0.3 ? 'better' : 'worse']],
        ];
        $row = fn (string $family, bool $selected, array $m, ?float $w) => [
            'tenant_id' => $tid, 'core' => 'demand', 'forecast_run_id' => $runId, 'model_family' => $family, 'evaluation_type' => $over['evaluation_type'] ?? 'backtest',
            'level' => 'segment', 'level_key' => "{$dimension}:{$value}", 'horizon_days' => $horizon, 'is_baseline' => $family === 'seasonal_naive', 'is_selected' => $selected,
            'wape' => $insufficient ? null : $w, 'mae' => $insufficient ? null : 1.2, 'rmse' => $insufficient ? null : 1.9, 'bias' => $insufficient || $unstable ? null : 0.03,
            'coverage_80' => $insufficient ? null : 0.8, 'coverage_95' => $insufficient ? null : 0.94, 'n_obs' => $insufficient ? 12 : 840, 'n_windows' => 3,
            'cutoffs' => json_encode(['2026-09-30']), 'extra' => json_encode(['evaluation_scope' => 'holdout', 'independent_holdout' => true, 'segment_dimension' => $dimension, 'segment_value' => $value]),
            'metrics' => json_encode($m), 'created_at' => now(),
        ];

        return [
            $row('selection', true, $base, $wape),
            $row('seasonal_naive', false, array_replace($base, ['segment' => array_replace($base['segment'], ['roles' => ['baseline']]), 'comparison' => null]), $insufficient ? null : 0.3),
        ];
    }

    /** Un conjunto representativo: 2 clases de demanda × 2 horizontes + un segmento con pocos datos y un WAPE inestable. */
    protected function seedSegmentEvaluations(int $tid, int $runId): void
    {
        $rows = [];
        foreach ([7, 30] as $h) {
            array_push($rows, ...$this->segmentRows($tid, $runId, 'demand_class', 'smooth', $h, ['wape' => 0.18]));
            array_push($rows, ...$this->segmentRows($tid, $runId, 'demand_class', 'intermittent', $h, ['wape' => 0.42]));
        }
        array_push($rows, ...$this->segmentRows($tid, $runId, 'series_age', 'new', 7, ['insufficient' => true, 'denominator' => 8.0]));
        array_push($rows, ...$this->segmentRows($tid, $runId, 'volume_tier', 'rest', 7, ['unstable' => true, 'denominator' => 12.0]));
        array_push($rows, ...$this->segmentRows($tid, $runId, 'demand_class', 'smooth', 30, ['evaluation_type' => 'selection_windows', 'wape' => 0.15]));
        DB::table('model_evaluations')->insert($rows);
    }

    /**
     * Decisiones de las dos políticas en el alcance global y por clase; la heredada gobierna.
     *
     * @return array<string, int> ids por «política:alcance»
     */
    protected function seedPromotionDecisions(int $tid, int $runId, ?int $candidateRegistryId = null): array
    {
        $now = now();
        $legacyGates = [
            'min_improvement' => ['status' => 'pass', 'blocking' => false, 'message' => 'Mejora relativa de LightGBM frente al mejor estadístico: 8,0 % (mínimo 3 %).',
                'evidence' => ['relative_improvement' => 0.08, 'required' => 0.03]],
            'windows_won' => ['status' => 'pass', 'blocking' => false, 'message' => 'LightGBM gana en 3/4 ventanas (necesita 3).', 'evidence' => ['windows_better' => 3, 'windows_total' => 4, 'windows_needed' => 3]],
        ];
        $v25Gates = [
            'no_leakage' => ['status' => 'pass', 'blocking' => true, 'message' => 'Sin fuga.', 'evidence' => ['holdout_rows_used' => 0]],
            'temporal_stability' => ['status' => 'fail', 'blocking' => false, 'message' => 'Pierde 2 ventanas consecutivas (máximo permitido 1).', 'evidence' => ['windows' => [1, 2, 3]]],
            'improvement' => ['status' => 'pass', 'blocking' => false, 'message' => 'Mejora 8 %.', 'evidence' => ['relative_improvement' => 0.08]],
            'human_approval' => ['status' => 'pending', 'blocking' => false, 'message' => 'Aprobación humana separada pendiente (W4).', 'evidence' => []],
        ];
        $specs = [
            ['legacy', 'global', 'lightgbm_global', 'promote', true, $legacyGates, 'lightgbm_global', 'LightGBM supera al mejor estadístico por clase.'],
            ['v25', 'global', 'lightgbm_global', 'shadow', false, $v25Gates, 'seasonal_naive', 'Un gate blando no pasa: queda como challenger.'],
            ['legacy', 'class:smooth', 'lightgbm_global', 'promote', true, $legacyGates, 'lightgbm_global', 'LightGBM gana 3 de 4 ventanas.'],
            ['v25', 'class:smooth', 'lightgbm_global', 'promote', false, $v25Gates, 'lightgbm_global', 'Cumple todos los gates.'],
            ['legacy', 'horizon:30', 'lightgbm_global', 'promote', false, $legacyGates, 'lightgbm_global', 'Informativo.'],
            // Contraparte V2.5 del alcance informativo, con OTRO modelo servido: si el resumen lo contara, la discrepancia saldría en `agreement`.
            ['v25', 'horizon:30', 'lightgbm_global', 'shadow', false, $v25Gates, 'seasonal_naive', 'Informativo.'],
        ];
        $ids = [];
        foreach ($specs as [$policy, $scope, $candidate, $decision, $effective, $gates, $served, $reason]) {
            $ids["{$policy}:{$scope}"] = (int) DB::table('model_promotion_decisions')->insertGetId([
                'tenant_id' => $tid, 'core' => 'demand', 'forecast_run_id' => $runId, 'scope_key' => $scope, 'policy' => $policy,
                'policy_version' => hash('sha256', $policy.'-v1'), 'candidate_family' => $candidate, 'champion_family' => 'seasonal_naive',
                'candidate_model_registry_id' => $candidateRegistryId, 'decision' => $decision, 'is_effective' => $effective,
                'gates' => json_encode($gates), 'metrics' => json_encode(['served_family' => $served, 'informational' => str_starts_with($scope, 'horizon:'), 'champion_source' => 'baseline']),
                'reason' => $reason, 'decided_at' => $now, 'created_at' => $now,
            ]);
        }

        return $ids;
    }

    /**
     * Alcances informativos `horizon:<H>` de ambas políticas para los horizontes dados (p. ej. los que el plan no incluye).
     *
     * @param  list<int>  $horizons
     */
    protected function seedHorizonDecisions(int $tid, int $runId, array $horizons): void
    {
        foreach ($horizons as $h) {
            foreach (['legacy', 'v25'] as $policy) {
                DB::table('model_promotion_decisions')->insert([
                    'tenant_id' => $tid, 'core' => 'demand', 'forecast_run_id' => $runId, 'scope_key' => "horizon:{$h}", 'policy' => $policy, 'policy_version' => hash('sha256', $policy.'-v1'),
                    'candidate_family' => 'lightgbm_global', 'champion_family' => 'seasonal_naive', 'decision' => 'promote', 'is_effective' => false,
                    'gates' => json_encode(['improvement' => ['status' => 'pass', 'blocking' => false, 'message' => "Horizonte {$h}.", 'evidence' => []]]),
                    'metrics' => json_encode(['served_family' => 'lightgbm_global', 'informational' => true]), 'reason' => "Horizonte {$h}", 'decided_at' => now(), 'created_at' => now(),
                ]);
            }
        }
    }

    /** Valores del challenger no servido (2 series × 3 días) y, opcionalmente, su evaluación prospectiva. */
    protected function seedShadow(int $tid, int $runId, bool $evaluated, ?int $registryId = null, string $family = 'auto_ets', float $sumActual = 280.0, bool $legacyRows = false): void
    {
        $values = [];
        foreach (['series:1:1', 'series:1:2'] as $node) {
            foreach ([1, 2, 3] as $step) {
                $values[] = [
                    'tenant_id' => $tid, 'forecast_run_id' => $runId, 'model_registry_id' => $registryId, 'model_family' => $family, 'node_key' => $node, 'level' => 'series',
                    'target_date' => date('Y-m-d', strtotime("2026-10-01 +{$step} days")), 'horizon_step' => $step, 'yhat' => 5.5, 'lo_80' => 4.0, 'hi_80' => 7.0, 'created_at' => now(),
                ];
            }
        }
        DB::table('shadow_forecast_values')->insert($values);
        if (! $evaluated) {
            return;
        }
        $metrics = ['shadow' => true, 'served' => false, 'scope' => 'prospectivo (tras el corte)',
            'exclusions' => ['series_days' => 6, 'evaluated_series_days' => 5, 'stockout_days' => 1, 'missing_source_days' => 0, 'closed_days' => 0],
            'sample' => ['n_obs' => 5, 'n_series' => 2, 'sum_actual' => $sumActual],
            'comparison' => ['served_wape_same_series_days' => 0.25, 'relative_improvement_vs_served' => 0.1, 'n_served_compared' => 5], 'coverage_80_n' => 5];
        if (! $legacyRows) {   // filas escritas por el motor desde W3-D; las anteriores no traen estas claves
            $metrics += [
                'denominator' => ['sum_actual' => $sumActual, 'unit' => 'unidades', 'min_required' => 30.0], 'wape_status' => $sumActual < 30 ? 'unstable' : 'ok', 'wape_unstable' => $sumActual < 30,
                'evaluated_period' => ['first_date' => '2026-10-02', 'last_date' => '2026-10-04', 'n_days' => 3],
            ];
        }
        foreach ([['overall', null, null], ['horizon', 'h1-7', 7]] as [$level, $key, $h]) {
            DB::table('model_evaluations')->insert([
                'tenant_id' => $tid, 'core' => 'demand', 'forecast_run_id' => $runId, 'model_registry_id' => $registryId, 'model_family' => $family, 'evaluation_type' => 'shadow_prospective',
                'level' => $level, 'level_key' => $key, 'horizon_days' => $h, 'is_baseline' => false, 'is_selected' => false, 'wape' => 0.225, 'mae' => 1.3, 'rmse' => 1.6, 'bias' => -0.02,
                'coverage_80' => 0.8, 'n_obs' => 5, 'n_windows' => 1, 'cutoffs' => json_encode(['2026-09-30']), 'extra' => json_encode(['shadow' => true, 'evaluation_scope' => 'prospective']),
                'metrics' => json_encode($metrics), 'created_at' => now(),
            ]);
        }
    }

    /**
     * S05 del Customer Core en las predicciones ya sembradas por seedPredictionRun: c1 con ventana estimada, c2 sin cifras (`insufficient_data`).
     */
    protected function seedCustomerS05(int $tid, int $runId, array $cat): void
    {
        DB::table('customer_predictions')->where('core_run_id', $runId)->where('customer_id', $cat['customers']['c1'])->update([
            'expected_orders' => 1.35, 'quantity_per_order' => 3.2, 'expected_units' => 4.32, 'window_p10_days' => 6, 'window_p50_days' => 11.5, 'window_p90_days' => 19,
            'window_status' => 'estimated', 'segment' => 'frequency:high', 'quality_flags' => json_encode(['orders_nested_adjusted', 'window_beyond_validation']),
        ]);
        DB::table('customer_predictions')->where('core_run_id', $runId)->where('customer_id', $cat['customers']['c2'])->update([
            'expected_orders' => 0.4, 'quantity_per_order' => 2.0, 'expected_units' => 0.8, 'window_p10_days' => null, 'window_p50_days' => null, 'window_p90_days' => null,
            'window_status' => 'insufficient_data', 'segment' => 'frequency:low', 'quality_flags' => json_encode(['cold_start', 's05_failed']),
        ]);
    }

    /** Calibración por segmento del Customer Core (nivel `segment`) y una fila de S05 de nivel `overall`, en una corrida de entrenamiento. */
    protected function seedCustomerSegmentEvaluations(int $tid, int $trainingRunId): void
    {
        $seg = fn (string $key, string $status, int $h) => [
            'tenant_id' => $tid, 'core' => 'customer', 'core_run_id' => $trainingRunId, 'forecast_run_id' => null, 'model_family' => 'customer_segment_calibration',
            'evaluation_type' => 'holdout', 'level' => 'segment', 'level_key' => $key, 'horizon_days' => $h, 'is_baseline' => false, 'is_selected' => true,
            'n_obs' => $status === 'insufficient_data' ? 40 : 1800, 'n_windows' => 0, 'created_at' => now(), 'extra' => json_encode(['dimension' => explode(':', $key)[0], 'status' => $status]),
            'metrics' => json_encode($status === 'insufficient_data'
                ? ['dimension' => explode(':', $key)[0], 'segment' => $key, 'n' => 40, 'n_positive' => 2, 'base_rate' => 0.05, 'mean_predicted' => 0.07, 'status' => 'insufficient_data',
                    'reason' => 'menos de 100 filas o de 20 positivos', 'served' => null, 'reference' => null, 'mean_ratio' => null]
                : ['dimension' => explode(':', $key)[0], 'segment' => $key, 'n' => 1800, 'n_positive' => 240, 'base_rate' => 0.1333, 'mean_predicted' => 0.1401, 'status' => $status,
                    'mean_ratio' => 1.05, 'served' => ['brier' => 0.101, 'log_loss' => 0.33, 'ece' => 0.02], 'reference' => ['brier' => 0.109], 'brier_delta_vs_reference' => 0.008]),
        ];
        $overall = [
            [
                'tenant_id' => $tid, 'core' => 'customer', 'core_run_id' => $trainingRunId, 'forecast_run_id' => null, 'model_family' => 'customer_orders_served',
                'evaluation_type' => 'holdout', 'level' => 'overall', 'level_key' => 'h30:expected_orders', 'horizon_days' => 30, 'is_baseline' => false, 'is_selected' => true,
                'n_obs' => 1800, 'n_windows' => 0, 'created_at' => now(), 'metrics' => json_encode(['n' => 1800, 'bias' => -0.04, 'mae' => 0.3]),
            ],
            [
                'tenant_id' => $tid, 'core' => 'customer', 'core_run_id' => $trainingRunId, 'forecast_run_id' => null, 'model_family' => 'customer_window',
                'evaluation_type' => 'out_of_sample', 'level' => 'overall', 'level_key' => 'window', 'horizon_days' => null, 'is_baseline' => false, 'is_selected' => true,
                'coverage_80' => 0.4, 'n_obs' => 127, 'n_windows' => 0, 'created_at' => now(), 'metrics' => json_encode(['status' => 'not_validated']),
            ],
        ];
        // Una inserción por fila: las columnas de cada fila difieren (los INSERT de varias filas exigen las mismas).
        foreach ([$seg('frequency:high', 'calibrated', 30), $seg('frequency:low', 'miscalibrated', 30), $seg('history:cold_start', 'insufficient_data', 30), ...$overall] as $row) {
            DB::table('model_evaluations')->insert($row);
        }
    }
}
