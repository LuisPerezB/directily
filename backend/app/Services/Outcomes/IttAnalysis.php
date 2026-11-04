<?php

declare(strict_types=1);

namespace App\Services\Outcomes;

/**
 * Análisis por intención de tratamiento de un experimento (V2.5 · W5-C; ADR-V25-14) sobre sus UNIDADES (un cliente = una unidad, la misma de la asignación).
 *
 * Una unidad entra UNA sola vez por experimento y horizonte (con su primera cohorte medida): así las unidades son distintas e independientes y se pueden sumar entre
 * cohortes sin contar dos veces al mismo cliente. Cada parte se publica SOLO si su base lo permite; si no, queda el motivo y NO hay diferencia ni intervalo:
 *
 *  - CONVERSIÓN (diferencia de tasas, Newcombe): hay control y tratamiento, ambos grupos ≥ `min_group_size` y una sola fracción de control registrada.
 *  - CONTRIBUCIÓN (diferencia de medias por unidad; intervalo percentil bootstrap con semilla registrada): además, población con costo confiable ≥ `min_group_size` por
 *    grupo, al menos `min_buyers_per_arm` compradores en CADA grupo y varianza distinta de cero. Con pocos compradores o con todos los valores iguales un intervalo (el
 *    de Welch sería de ancho cero) es una ilusión de precisión: `not_estimable`. El intervalo normal de Welch se conserva SOLO como diagnóstico.
 *
 * También informa el cociente de muestras observado frente a la fracción de control registrada (SRM) y las unidades descartadas por grupo.
 *
 * @phpstan-type Unit array{customer_id: int, group: string, converted: int, contribution: float|null, in_population: bool, control_fraction: float, contaminated: bool}
 */
final class IttAnalysis
{
    public const METHOD = 'itt';

    /** Nivel de significación del aviso de SRM (convención habitual en experimentación en línea: 0,001). */
    public const SRM_P_THRESHOLD = 0.001;

    /**
     * @param  list<array<string, mixed>>  $units
     * @param  string  $seedMaterial  «experimento|horizonte» para derivar la semilla del bootstrap (vacío = sin semilla propia de experimento)
     * @return array<string, mixed>
     */
    public static function estimate(array $units, OutcomeSettings $settings, string $experimentKey = '', int $horizonDays = 0): array
    {
        $z = $settings->z();
        $min = $settings->minGroupSize();
        $t = array_values(array_filter($units, fn (array $u) => $u['group'] === 'treatment'));
        $c = array_values(array_filter($units, fn (array $u) => $u['group'] === 'control'));
        $nT = count($t);
        $nC = count($c);
        $xT = array_sum(array_map(fn (array $u) => (int) $u['converted'], $t));
        $xC = array_sum(array_map(fn (array $u) => (int) $u['converted'], $c));
        $fractions = array_values(array_unique(array_map(fn (array $u) => round((float) $u['control_fraction'], 6), $units)));
        sort($fractions);

        $status = match (true) {
            $nC === 0 => 'no_control_group',
            $nT === 0 => 'no_treatment_group',
            count($fractions) > 1 => 'heterogeneous_control_fraction',
            $nT < $min || $nC < $min => 'insufficient_sample',
            default => 'estimated',
        };
        $out = [
            'method' => self::METHOD, 'status' => $status, 'confidence_level' => $settings->confidenceLevel(), 'z' => round($z, 6), 'min_group_size' => $min,
            'min_buyers_per_arm' => $settings->minBuyersPerArm(),
            'sample' => ['treatment' => $nT, 'control' => $nC, 'treatment_converted' => $xT, 'control_converted' => $xC],
            'control_fractions' => $fractions,
            'sample_ratio' => self::sampleRatio($nT, $nC, $fractions),
            'contamination' => ['control_units_with_registered_action' => count(array_filter($c, fn (array $u) => ! empty($u['contaminated'])))],
            'estimators' => [
                'conversion' => 'Diferencia de tasas tratamiento − control, intervalo de Newcombe (Wilson híbrido, método 10)',
                'contribution' => 'Diferencia de medias por unidad (incluye ceros), intervalo percentil bootstrap con semilla registrada (Welch solo como diagnóstico)',
            ],
            'conversion' => [
                'rate_treatment' => $nT > 0 ? round($xT / $nT, 6) : null, 'rate_control' => $nC > 0 ? round($xC / $nC, 6) : null,
                'difference' => null, 'ci_low' => null, 'ci_high' => null, 'significant' => null,
            ],
            'contribution' => ['status' => 'not_estimated', 'reason' => $status === 'estimated' ? null : $status],
            'incremental' => null,
            'note' => $status === 'estimated'
                ? 'Estimación por intención de tratamiento con intervalo; no es causalidad demostrada (ver OUTCOME_MEASUREMENT.md).'
                : 'Sin estimación: no hay base para afirmar un incremento. Oportunidad potencial, atribución no demostrada.',
        ];
        if ($status !== 'estimated') {
            return $out;
        }

        $r = IttEstimator::rateDifference($xT, $nT, $xC, $nC, $z);
        $out['conversion'] = [
            'rate_treatment' => round($r['rate_treatment'], 6), 'rate_control' => round($r['rate_control'], 6), 'difference' => round($r['difference'], 6),
            'ci_low' => round($r['ci_low'], 6), 'ci_high' => round($r['ci_high'], 6), 'significant' => $r['significant'],
        ];

        // Contribución: solo la población con costo confiable en TODO su alcance de productos (decidida antes de ver quién compró; ver OUTCOME_MEASUREMENT.md).
        $pop = fn (array $group): array => array_values(array_filter($group, fn (array $u) => $u['in_population'] && $u['contribution'] !== null));
        $tp = $pop($t);
        $cp = $pop($c);
        $buyers = fn (array $group): int => count(array_filter($group, fn (array $u) => (int) $u['converted'] === 1));
        $population = ['treatment' => count($tp), 'control' => count($cp)];
        $armBuyers = ['treatment' => $buyers($tp), 'control' => $buyers($cp)];
        $excluded = ['treatment' => $nT - count($tp), 'control' => $nC - count($cp)];
        $base = ['population' => $population, 'buyers' => $armBuyers, 'excluded_unreliable_cost' => $excluded];
        if (count($tp) < $min || count($cp) < $min) {
            $out['contribution'] = ['status' => 'insufficient_sample', 'reason' => 'contribution_population_below_min_group_size'] + $base;

            return $out;
        }
        $tv = array_map(fn (array $u) => (float) $u['contribution'], $tp);
        $cv = array_map(fn (array $u) => (float) $u['contribution'], $cp);
        $welch = IttEstimator::meanDifference($tv, $cv, $z);
        if ($armBuyers['treatment'] < $settings->minBuyersPerArm() || $armBuyers['control'] < $settings->minBuyersPerArm()) {
            $out['contribution'] = ['status' => 'not_estimable', 'reason' => 'buyers_below_min_per_arm'] + $base;

            return $out;
        }
        if ($welch === null || $welch['std_error'] <= 0.0) {
            $out['contribution'] = ['status' => 'not_estimable', 'reason' => 'zero_variance'] + $base;

            return $out;
        }
        $seed = $settings->bootstrapSeed($experimentKey, $horizonDays);
        $boot = BootstrapInterval::meanDifference($tv, $cv, $settings->bootstrapResamples(), $seed, $settings->confidenceLevel(), $settings->bootstrapMaxDraws());
        if (isset($boot['unavailable'])) {
            $out['contribution'] = ['status' => 'not_estimable', 'reason' => $boot['unavailable']] + $base;

            return $out;
        }
        $significant = $boot['ci_low'] > 0 || $boot['ci_high'] < 0;
        $out['contribution'] = [
            'status' => 'estimated', 'reason' => null] + $base + [
                'mean_treatment' => round($welch['mean_treatment'], 4), 'mean_control' => round($welch['mean_control'], 4), 'difference_per_unit' => round($welch['difference'], 4),
                'ci_low' => round($boot['ci_low'], 4), 'ci_high' => round($boot['ci_high'], 4), 'significant' => $significant,
                'interval' => [
                    'method' => 'bootstrap_percentile', 'engine' => $boot['engine'], 'resamples' => $boot['resamples'], 'resamples_requested' => $boot['resamples_requested'], 'seed' => $boot['seed'],
                    'seed_derivation' => 'primeros 32 bits de SHA-256(«outcomes-bootstrap-v1|empresa|experimento|horizonte»)', 'resampling' => 'unidades con reemplazo dentro de cada grupo',
                    'std_error' => round($boot['std_error'], 6),
                ],
                'tail_diagnostic' => self::tailDiagnostic($tv, $cv),
                'diagnostic_welch' => [
                    'std_error' => round($welch['std_error'], 6), 'ci_low' => round($welch['ci_low'], 4), 'ci_high' => round($welch['ci_high'], 4),
                    'note' => 'Solo diagnóstico: el intervalo normal subestima la incertidumbre con contribución cero-inflada y de cola pesada; NO es el intervalo publicado.',
                ],
            ];
        $n = count($tp);
        $out['incremental'] = [
            'basis' => 'difference_per_unit × treated_units_in_population',
            'treated_units' => $n,
            'contribution' => round($welch['difference'] * $n, 2), 'ci_low' => round($boot['ci_low'] * $n, 2), 'ci_high' => round($boot['ci_high'] * $n, 2),
        ];

        return $out;
    }

    /**
     * Cola de la contribución de quienes compran. Ningún intervalo no paramétrico alcanza su nivel nominal con muestras de decenas o cientos de unidades cuando unos pocos
     * compradores concentran el importe (la cobertura medida baja a 74–94 %: ver OUTCOME_MEASUREMENT.md): este diagnóstico lo advierte en cada estimación. Umbrales
     * provisionales («a aprobar en el piloto»): coeficiente de variación de los valores de compradores > 1,5 (lognormal con σ ≈ 1,1) o un solo comprador con más del 25 % del
     * total de su grupo.
     *
     * @param  list<float>  $treatment
     * @param  list<float>  $control
     * @return array<string, mixed>
     */
    public static function tailDiagnostic(array $treatment, array $control): array
    {
        $positive = array_values(array_filter(array_merge($treatment, $control), fn (float $v) => $v > 0));
        $n = count($positive);
        $mean = $n > 0 ? array_sum($positive) / $n : 0.0;
        $sd = $n > 1 ? sqrt(array_sum(array_map(fn (float $v) => ($v - $mean) ** 2, $positive)) / ($n - 1)) : 0.0;
        $cv = $mean > 0 ? $sd / $mean : 0.0;
        $share = 0.0;
        foreach ([$treatment, $control] as $arm) {
            $buyers = array_values(array_filter($arm, fn (float $v) => $v > 0));
            $total = array_sum($buyers);
            $share = max($share, $total > 0 ? max($buyers) / $total : 0.0);
        }
        $heavy = $cv > 1.5 || $share > 0.25;

        return [
            'buyer_cv' => round($cv, 4), 'largest_buyer_share_of_arm' => round($share, 4), 'heavy_tailed' => $heavy,
            'thresholds' => ['buyer_cv' => 1.5, 'largest_buyer_share_of_arm' => 0.25],
            'note' => $heavy
                ? 'Contribución de cola pesada: pocos compradores concentran el importe. El intervalo monetario puede cubrir bastante menos que su nivel nominal con esta muestra; léase como indicativo.'
                : 'Sin concentración fuerte de compradores. La cobertura de un intervalo no paramétrico sigue siendo aproximada con muestras pequeñas.',
        ];
    }

    /**
     * Cociente de muestras (SRM): ¿la proporción observada de control entre las unidades contadas es compatible con la fracción registrada? χ² de 1 grado de libertad
     * sobre el conteo del control. Un desajuste (p < 0,001) señala una asignación rota o unidades perdidas de forma desigual: el estimado debe leerse con ese aviso.
     *
     * @param  list<float>  $fractions
     * @return array<string, mixed>
     */
    public static function sampleRatio(int $nT, int $nC, array $fractions): array
    {
        $n = $nT + $nC;
        if (count($fractions) !== 1 || $nT === 0 || $nC === 0 || $fractions[0] <= 0.0 || $fractions[0] >= 1.0) {
            return ['status' => 'not_evaluable', 'expected_control_fraction' => count($fractions) === 1 ? $fractions[0] : null, 'observed_control_share' => $n > 0 ? round($nC / $n, 6) : null];
        }
        $f = $fractions[0];
        $chi2 = (($nC - $n * $f) ** 2) / ($n * $f * (1 - $f));
        $p = self::chiSquare1Survival($chi2);

        return [
            'status' => $p < self::SRM_P_THRESHOLD ? 'mismatch' : 'ok', 'expected_control_fraction' => $f, 'observed_control_share' => round($nC / $n, 6),
            'chi_square' => round($chi2, 4), 'p_value' => round($p, 6), 'threshold' => self::SRM_P_THRESHOLD,
            'note' => 'Compara la proporción observada de control con la fracción registrada al asignar (χ², 1 g.l.).',
        ];
    }

    /** P(χ²₁ > x) = erfc(√(x/2)); erfc por la aproximación de Abramowitz–Stegun 7.1.26 (error absoluto < 1,5·10⁻⁷). */
    private static function chiSquare1Survival(float $x): float
    {
        $z = sqrt(max(0.0, $x) / 2);
        $t = 1.0 / (1.0 + 0.3275911 * $z);
        $poly = $t * (0.254829592 + $t * (-0.284496736 + $t * (1.421413741 + $t * (-1.453152027 + $t * 1.061405429))));

        return max(0.0, min(1.0, $poly * exp(-$z * $z)));
    }
}
