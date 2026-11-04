<?php

declare(strict_types=1);

namespace App\Services\Outcomes;

/**
 * Estimadores del análisis por INTENCIÓN DE TRATAMIENTO (ITT; V2.5 · W5-C; ADR-V25-14; §12), funciones puras y deterministas.
 *
 * ITT: se compara a cada unidad según el grupo que se le ASIGNÓ al generar la recomendación (tratamiento o control), contactada o no. Es la comparación que la
 * aleatorización protege; descartar a los no contactados la rompería (sesgo de selección: se contacta a los más fáciles).
 *
 *  - Tasa de conversión (proporción de unidades que compraron): diferencia de proporciones con el intervalo de NEWCOMBE (híbrido de Wilson, método 10 sin corrección
 *    de continuidad). Es válido con proporciones cerca de 0 o de 1 y con muestras pequeñas, donde el intervalo de Wald falla.
 *  - Contribución por unidad (incluye ceros de quien no compró): diferencia de medias con error estándar de Welch (varianzas muestrales n−1) e intervalo con la
 *    aproximación normal. Exige n ≥ 2 por grupo; por eso el servicio exige `min_group_size` (a aprobar en el piloto) antes de publicarlo como estimación.
 */
final class IttEstimator
{
    /**
     * Intervalo de Wilson de una proporción x/n.
     *
     * @return array{0: float, 1: float}
     */
    public static function wilson(int $x, int $n, float $z): array
    {
        if ($n <= 0) {
            return [0.0, 1.0];
        }
        $p = $x / $n;
        $z2 = $z * $z;
        $denominator = 1 + $z2 / $n;
        $center = ($p + $z2 / (2 * $n)) / $denominator;
        $half = $z * sqrt($p * (1 - $p) / $n + $z2 / (4 * $n * $n)) / $denominator;

        return [max(0.0, $center - $half), min(1.0, $center + $half)];
    }

    /**
     * Diferencia de tasas tratamiento − control con intervalo de Newcombe.
     *
     * @return array{rate_treatment: float, rate_control: float, difference: float, ci_low: float, ci_high: float, significant: bool}
     */
    public static function rateDifference(int $xTreatment, int $nTreatment, int $xControl, int $nControl, float $z): array
    {
        $pt = $nTreatment > 0 ? $xTreatment / $nTreatment : 0.0;
        $pc = $nControl > 0 ? $xControl / $nControl : 0.0;
        [$lt, $ut] = self::wilson($xTreatment, $nTreatment, $z);
        [$lc, $uc] = self::wilson($xControl, $nControl, $z);
        $d = $pt - $pc;
        $low = $d - sqrt(($pt - $lt) ** 2 + ($uc - $pc) ** 2);
        $high = $d + sqrt(($ut - $pt) ** 2 + ($pc - $lc) ** 2);

        return [
            'rate_treatment' => $pt, 'rate_control' => $pc, 'difference' => $d, 'ci_low' => max(-1.0, $low), 'ci_high' => min(1.0, $high),
            'significant' => $low > 0 || $high < 0,
        ];
    }

    /**
     * Diferencia de medias tratamiento − control (Welch). Null si algún grupo tiene menos de 2 unidades.
     *
     * @param  list<float>  $treatment
     * @param  list<float>  $control
     * @return array{mean_treatment: float, mean_control: float, difference: float, std_error: float, ci_low: float, ci_high: float, significant: bool}|null
     */
    public static function meanDifference(array $treatment, array $control, float $z): ?array
    {
        $nt = count($treatment);
        $nc = count($control);
        if ($nt < 2 || $nc < 2) {
            return null;
        }
        $mt = array_sum($treatment) / $nt;
        $mc = array_sum($control) / $nc;
        $vt = array_sum(array_map(fn (float $v) => ($v - $mt) ** 2, $treatment)) / ($nt - 1);
        $vc = array_sum(array_map(fn (float $v) => ($v - $mc) ** 2, $control)) / ($nc - 1);
        $se = sqrt($vt / $nt + $vc / $nc);
        $d = $mt - $mc;

        return [
            'mean_treatment' => $mt, 'mean_control' => $mc, 'difference' => $d, 'std_error' => $se,
            'ci_low' => $d - $z * $se, 'ci_high' => $d + $z * $se, 'significant' => ($d - $z * $se) > 0 || ($d + $z * $se) < 0,
        ];
    }
}
