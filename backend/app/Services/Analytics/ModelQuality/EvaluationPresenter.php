<?php

declare(strict_types=1);

namespace App\Services\Analytics\ModelQuality;

use App\Models\ModelEvaluation;
use App\Services\Analytics\Support\ModelCatalog;
use App\Services\Analytics\Support\Num;
use App\Support\Results\JsonMap;

/**
 * Presenta una fila de `model_evaluations` para GET /models/evaluations y /models/shadow-evaluations (V2.5 · W3-D).
 *
 * Reglas de honestidad:
 *  - una fila con `metrics.status = insufficient_data` (segmento con pocas observaciones) sale SIN cifras de error: las columnas y los bloques
 *    derivados quedan nulos y `figures_available = false`; lo que sí llega son los tamaños de muestra y el denominador;
 *  - un WAPE inestable o indefinido ya viene nulo del motor (nunca un WAPE con denominador 0); aquí se publica con su marca (`wape_unstable`) y la métrica
 *    alternativa explícita (`quality.alternative_metric`);
 *  - nada se rellena con ceros: lo que el motor no estimó es `null`.
 */
final class EvaluationPresenter
{
    public const INSUFFICIENT = 'insufficient_data';

    /**
     * Umbral por defecto del denominador del WAPE (`demand.wape_min_denominator` de `engine/predictily_engine/platform/config.py`): menos unidades reales que esto
     * y el porcentaje de error no es estable. Una ejecución puede traer el suyo en su configuración efectiva; valor inicial «a aprobar en el piloto».
     */
    public const DEFAULT_WAPE_MIN_DENOMINATOR = 30.0;

    /**
     * Cifras de error que se anulan también en el `metrics` crudo de una fila `insufficient_data` (el motor ya las deja nulas; esto es defensa en profundidad).
     * Se conservan los tamaños de muestra (`n`, `n_positive`, `sample`, `denominator`) que explican por qué no hay cifras.
     */
    private const WITHHELD_METRIC_KEYS = [
        'wape', 'wape_raw', 'sum_abs_error', 'mase', 'rmsse', 'bias', 'bias_raw', 'mean_error', 'pinball_p10', 'pinball_p50', 'pinball_p90',
        'pinball_p50_yhat_as_median', 'interval_width_80', 'interval_width_95', 'interval', 'horizon_total', 'alternative_metric',
        'share_actual_le_p10', 'share_actual_le_p50', 'share_actual_le_p90',
        'base_rate', 'mean_predicted', 'mean_ratio', 'served', 'reference', 'brier_delta_vs_reference',
    ];

    /** @return array<string, mixed> */
    public static function row(ModelEvaluation $e, ?float $minDenominator = null): array
    {
        $metrics = is_array($e->metrics) ? $e->metrics : [];
        $extra = is_array($e->extra) ? $e->extra : [];
        $status = is_string($metrics['status'] ?? null) ? $metrics['status'] : null;
        $withheld = $status === self::INSUFFICIENT;
        $isShadow = $e->evaluation_type === ModelEvaluation::TYPE_SHADOW_PROSPECTIVE;
        $shadow = $isShadow ? self::shadow($metrics, $minDenominator) : null;
        $unstable = $shadow !== null && $shadow['wape_unstable'];   // WAPE y sesgo dependen del mismo denominador: sin estabilidad no se publican
        $figure = static fn (mixed $v, string $kind, bool $hide = false) => ($withheld || $hide) ? null : ($kind === 'units' ? Num::units($v) : Num::ratio($v));
        $isSegment = $e->level === ModelEvaluation::LEVEL_SEGMENT;
        $roles = $extra['roles'] ?? ($metrics['segment']['roles'] ?? null);

        return [
            'id' => $e->id,
            'core' => $e->core,
            'forecast_run_id' => $e->forecast_run_id,
            'core_run_id' => $e->core_run_id,
            'model_registry_id' => $e->model_registry_id,
            'model_family' => $e->model_family,
            'label' => ModelCatalog::label($e->model_family),
            'roles' => is_array($roles) ? array_values(array_map('strval', $roles)) : [],
            'evaluation_type' => $e->evaluation_type,
            'level' => $e->level,
            'level_key' => $e->level_key,
            'segment' => $isSegment ? self::segment($e->level_key) : null,
            'horizon_days' => $e->horizon_days !== null ? (int) $e->horizon_days : null,
            'is_baseline' => (bool) $e->is_baseline,
            'is_selected' => (bool) $e->is_selected,
            'status' => $status,
            'figures_available' => ! $withheld,
            'wape' => $figure($e->wape, 'ratio', $unstable),
            'mae' => $figure($e->mae, 'units'),
            'rmse' => $figure($e->rmse, 'units'),
            'bias' => $figure($e->bias, 'ratio', $unstable),
            'coverage_80' => $figure($e->coverage_80, 'ratio'),
            'coverage_95' => $figure($e->coverage_95, 'ratio'),
            'n_obs' => (int) $e->n_obs,
            'n_windows' => (int) $e->n_windows,
            'cutoffs' => self::list($e->cutoffs),
            'quality' => $e->core === 'demand' ? self::quality($metrics, $withheld) : null,
            'calibration' => $e->core === 'customer' && $isSegment ? self::calibration($metrics, $withheld) : null,
            'shadow' => $shadow,
            'metrics' => JsonMap::of($withheld ? self::stripFigures($e->metrics) : $e->metrics),
            'extra' => JsonMap::of($e->extra),
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }

    /** @return array{dimension: string, value: string}|null */
    public static function segment(?string $levelKey): ?array
    {
        if ($levelKey === null || ! str_contains($levelKey, ':')) {
            return null;
        }
        [$dimension, $value] = explode(':', $levelKey, 2);

        return ['dimension' => $dimension, 'value' => $value];
    }

    /**
     * Calidad de una fila de Demand: denominador y cobertura explícitos, marca de WAPE inestable y su métrica alternativa, escala (MASE/RMSSE),
     * estabilidad entre ventanas y comparación contra el baseline. Nulo si la fila no declara denominador (filas anteriores a V2.5).
     *
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>|null
     */
    private static function quality(array $m, bool $withheld): ?array
    {
        if (! isset($m['denominator']) && ! isset($m['wape_status'])) {
            return null;
        }
        $den = is_array($m['denominator'] ?? null) ? $m['denominator'] : [];
        $sample = is_array($m['sample'] ?? null) ? $m['sample'] : null;
        $stab = is_array($m['stability'] ?? null) ? $m['stability'] : null;
        $cmp = is_array($m['comparison']['selected_vs_baseline'] ?? null) ? $m['comparison']['selected_vs_baseline'] : null;
        $alt = is_array($m['alternative_metric'] ?? null) ? $m['alternative_metric'] : null;

        return [
            'metrics_version' => $m['segment_metrics_version'] ?? ($m['metrics_version'] ?? null),
            'status' => is_string($m['status'] ?? null) ? $m['status'] : null,
            'insufficient_reasons' => is_array($m['insufficient_reasons'] ?? null) ? array_values($m['insufficient_reasons']) : [],
            'wape_status' => $m['wape_status'] ?? null,
            'wape_unstable' => (bool) ($m['wape_unstable'] ?? false),
            'wape_raw' => $withheld ? null : Num::ratio($m['wape_raw'] ?? null),
            'denominator' => $den === [] ? null : [
                'sum_actual' => Num::units($den['sum_actual'] ?? null),
                'unit' => $den['unit'] ?? 'unidades',
                'min_required' => Num::units($den['min_required'] ?? null),
            ],
            'alternative_metric' => $withheld || $alt === null ? null : JsonMap::of($alt),
            'mase' => $withheld ? null : Num::ratio($m['mase'] ?? null),
            'rmsse' => $withheld ? null : Num::ratio($m['rmsse'] ?? null),
            'sum_abs_error' => $withheld ? null : Num::units($m['sum_abs_error'] ?? null),
            'pinball' => $withheld ? null : [
                'p10' => Num::units($m['pinball_p10'] ?? null),
                'p50' => Num::units($m['pinball_p50'] ?? null),
                'p90' => Num::units($m['pinball_p90'] ?? null),
            ],
            // Mapa con claves numéricas ('80', '95'): objeto JSON aunque JsonResource renumere los arreglos de claves numéricas.
            'interval_width' => $withheld ? null : JsonMap::of([
                '80' => Num::units($m['interval_width_80'] ?? null),
                '95' => Num::units($m['interval_width_95'] ?? null),
            ]),
            'sample' => $sample === null ? null : JsonMap::of($sample),
            'stability' => $stab === null ? null : [
                'status' => $stab['status'] ?? null,
                'n_windows' => isset($stab['n_windows']) ? (int) $stab['n_windows'] : null,
                'wape_mean' => $withheld ? null : Num::ratio($stab['wape_mean'] ?? null),
                'wape_std' => $withheld ? null : Num::ratio($stab['wape_std'] ?? null),
                'wape_cv' => $withheld ? null : Num::ratio($stab['wape_cv'] ?? null),
                'unstable_across_windows' => $withheld ? null : ($stab['unstable_across_windows'] ?? null),
            ],
            // Solo en la fila del modelo servido: el veredicto frente al baseline y la métrica con que se comparó.
            'comparison' => $cmp === null ? null : [
                'baseline_family' => $m['comparison']['baseline_family'] ?? null,
                'verdict' => $cmp['verdict'] ?? null,
                'metric' => $withheld ? null : ($cmp['metric'] ?? null),
                'value' => $withheld ? null : Num::ratio($cmp['value'] ?? null),
                'baseline_value' => $withheld ? null : Num::ratio($cmp['baseline_value'] ?? null),
                'relative_improvement' => $withheld ? null : Num::ratio($cmp['relative_improvement'] ?? null),
                'reason' => $cmp['reason'] ?? null,
            ],
        ];
    }

    /**
     * Calibración por segmento del Customer Core (W3-C): n, tasa observada frente a media predicha, Brier del servido y de la referencia.
     *
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private static function calibration(array $m, bool $withheld): array
    {
        $served = is_array($m['served'] ?? null) ? $m['served'] : [];
        $ref = is_array($m['reference'] ?? null) ? $m['reference'] : [];

        return [
            'status' => is_string($m['status'] ?? null) ? $m['status'] : null,
            'n' => isset($m['n']) ? (int) $m['n'] : null,
            'n_positive' => isset($m['n_positive']) ? (int) $m['n_positive'] : null,
            'base_rate' => $withheld ? null : Num::ratio($m['base_rate'] ?? null),
            'mean_predicted' => $withheld ? null : Num::ratio($m['mean_predicted'] ?? null),
            'mean_ratio' => $withheld ? null : Num::ratio($m['mean_ratio'] ?? null),
            'brier' => $withheld ? null : Num::ratio($served['brier'] ?? null),
            'log_loss' => $withheld ? null : Num::ratio($served['log_loss'] ?? null),
            'ece' => $withheld ? null : Num::ratio($served['ece'] ?? null),
            'reference_brier' => $withheld ? null : Num::ratio($ref['brier'] ?? null),
            'brier_delta_vs_reference' => $withheld ? null : Num::ratio($m['brier_delta_vs_reference'] ?? null),
            'reason' => is_string($m['reason'] ?? null) ? $m['reason'] : null,
        ];
    }

    /**
     * Evaluación prospectiva del challenger: nunca servido; comparado con el WAPE del modelo servido sobre las MISMAS series y días no censurados.
     * Con la misma guarda de estabilidad que las métricas por segmento (W3-A): si lo realmente vendido en lo evaluado (`sample.sum_actual`) es menor que el
     * mínimo, el WAPE, el sesgo, el WAPE del servido y la mejora relativa NO se publican (`wape_unstable`), porque un porcentaje sobre muy pocas ventas es engañoso.
     * Siempre se informa el denominador y el período realmente evaluado (filas anteriores a W3-D pueden no traer las fechas).
     *
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private static function shadow(array $m, ?float $minDenominator): array
    {
        $cmp = is_array($m['comparison'] ?? null) ? $m['comparison'] : [];
        $sample = is_array($m['sample'] ?? null) ? $m['sample'] : [];
        $den = is_array($m['denominator'] ?? null) ? $m['denominator'] : [];
        $period = is_array($m['evaluated_period'] ?? null) ? $m['evaluated_period'] : [];
        $sumActual = isset($sample['sum_actual']) && is_numeric($sample['sum_actual']) ? (float) $sample['sum_actual']
            : (isset($den['sum_actual']) && is_numeric($den['sum_actual']) ? (float) $den['sum_actual'] : null);
        $min = isset($den['min_required']) && is_numeric($den['min_required']) ? (float) $den['min_required'] : ($minDenominator ?? self::DEFAULT_WAPE_MIN_DENOMINATOR);
        $wapeStatus = $sumActual === null ? null : ($sumActual <= 0 ? 'undefined' : ($sumActual < $min ? 'unstable' : 'ok'));
        $unstable = $wapeStatus === 'unstable' || $wapeStatus === 'undefined';

        return [
            'served' => false,
            'scope' => $m['scope'] ?? 'prospectivo (tras el corte)',
            'wape_status' => $wapeStatus,
            'wape_unstable' => $unstable,
            'denominator' => $sumActual === null ? null : ['sum_actual' => Num::units($sumActual), 'unit' => 'unidades', 'min_required' => Num::units($min)],
            'served_wape_same_series_days' => $unstable ? null : Num::ratio($cmp['served_wape_same_series_days'] ?? null),
            'relative_improvement_vs_served' => $unstable ? null : Num::ratio($cmp['relative_improvement_vs_served'] ?? null),
            'n_served_compared' => isset($cmp['n_served_compared']) ? (int) $cmp['n_served_compared'] : null,
            'sum_actual' => Num::units($sumActual),
            'n_series' => isset($sample['n_series']) ? (int) $sample['n_series'] : null,
            'coverage_80_n' => isset($m['coverage_80_n']) ? (int) $m['coverage_80_n'] : null,
            // Período REALMENTE evaluado (no el de las predicciones): fechas y días con ventas comparables, series-día y series.
            'evaluated' => [
                'first_date' => is_string($period['first_date'] ?? null) ? substr($period['first_date'], 0, 10) : null,
                'last_date' => is_string($period['last_date'] ?? null) ? substr($period['last_date'], 0, 10) : null,
                'n_days' => isset($period['n_days']) ? (int) $period['n_days'] : null,
                'n_series_days' => isset($sample['n_obs']) ? (int) $sample['n_obs'] : null,
                'n_series' => isset($sample['n_series']) ? (int) $sample['n_series'] : null,
            ],
            'exclusions' => JsonMap::orEmpty($m['exclusions'] ?? null),
        ];
    }

    /**
     * Quita las cifras de error del jsonb crudo de una fila `insufficient_data` (ver WITHHELD_METRIC_KEYS) y, dentro de `comparison`/`stability`, las
     * cifras de cada entrada; deja los tamaños de muestra, el denominador y los motivos.
     */
    private static function stripFigures(mixed $metrics): mixed
    {
        if (! is_array($metrics)) {
            return $metrics;
        }
        foreach (self::WITHHELD_METRIC_KEYS as $key) {
            if (array_key_exists($key, $metrics)) {
                $metrics[$key] = null;
            }
        }
        if (is_array($metrics['comparison'] ?? null)) {
            foreach (['selected_vs_baseline', 'best_statistical_vs_baseline', 'ml_vs_baseline'] as $entry) {
                if (is_array($metrics['comparison'][$entry] ?? null)) {
                    foreach (['wape', 'mase', 'sum_abs_error', 'value', 'baseline_value', 'delta', 'relative_improvement'] as $figure) {
                        if (array_key_exists($figure, $metrics['comparison'][$entry])) {
                            $metrics['comparison'][$entry][$figure] = null;
                        }
                    }
                }
            }
        }
        if (is_array($metrics['stability'] ?? null)) {
            foreach (['wape_mean', 'wape_std', 'wape_cv', 'wape_min', 'wape_max'] as $figure) {
                if (array_key_exists($figure, $metrics['stability'])) {
                    $metrics['stability'][$figure] = null;
                }
            }
            if (array_key_exists('per_window', $metrics['stability'])) {
                $metrics['stability']['per_window'] = [];
            }
        }

        return $metrics;
    }

    /** @return list<string> */
    private static function list(mixed $value): array
    {
        $list = is_string($value) ? json_decode($value, true) : $value;

        return is_array($list) ? array_values(array_map('strval', $list)) : [];
    }
}
