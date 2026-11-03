<?php

declare(strict_types=1);

namespace App\Services\Outcomes;

use Illuminate\Support\Facades\Cache;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Intervalo PERCENTIL BOOTSTRAP de la diferencia de medias por unidad (tratamiento − control) del análisis por intención de tratamiento (V2.5 · W5-C).
 *
 * Por qué no el intervalo normal (Welch) para dinero: la contribución por unidad es cero para casi todos y de cola pesada para quien compra; el intervalo normal cubre
 * entre 54 % y 90 % cuando se anuncia 95 % (simulación documentada en OUTCOME_MEASUREMENT.md). El bootstrap percentil remuestrea CON REEMPLAZO las unidades de cada
 * grupo (nunca entre grupos), calcula la diferencia de medias de cada remuestreo y toma los percentiles α/2 y 1−α/2. Mejora la cobertura pero tampoco es exacto con muestras
 * pequeñas: por eso se exige un mínimo de compradores por grupo antes de publicarlo.
 *
 * DETERMINISTA: motor Mersenne Twister (PHP 8.3 `Random\\Engine\\Mt19937`) con una semilla que se registra; mismos datos y semilla ⇒ mismo intervalo.
 *
 * COSTO: cada grupo se comprime en (n, valores distintos de cero). En cada remuestreo el número de unidades con valor ≠ 0 que salen sorteadas es Binomial(n, b/n) (se
 * sortea EXACTO por tiempos de espera geométricos) y solo para esas se sortea cuál de los b valores es: O(b) en vez de O(n) por remuestreo. Con más cómputo del permitido
 * (`maxDraws`) baja el número de remuestreos hasta un mínimo; por debajo, no se publica.
 */
final class BootstrapInterval
{
    public const ENGINE = 'mt19937-percentile-v1';

    public const MIN_RESAMPLES = 500;

    /**
     * @param  list<float>  $treatment  contribución por unidad (con ceros)
     * @param  list<float>  $control
     * @return array{ci_low: float, ci_high: float, resamples: int, resamples_requested: int, seed: int, engine: string, std_error: float}|array{unavailable: string}
     */
    public static function meanDifference(array $treatment, array $control, int $resamples, int $seed, float $level, int $maxDraws): array
    {
        $arms = [self::compress($treatment), self::compress($control)];
        $costPerResample = 0.0;
        foreach ($arms as [$n, $values]) {
            $b = count($values);
            $costPerResample += 2.0 * min($b, $n - $b) + $b + 2;
        }
        $effective = $resamples;
        if ($costPerResample * $resamples > $maxDraws) {
            $effective = (int) floor($maxDraws / max(1.0, $costPerResample));
            if ($effective < self::MIN_RESAMPLES) {
                return ['unavailable' => 'bootstrap_budget_exceeded'];
            }
        }
        $compute = function () use ($arms, $effective, $resamples, $seed, $level): array {
            $rng = new Randomizer(new Mt19937($seed));
            $diffs = [];
            for ($r = 0; $r < $effective; $r++) {
                $diffs[] = self::resampledMean($rng, $arms[0]) - self::resampledMean($rng, $arms[1]);
            }
            sort($diffs);
            $alpha = 1.0 - $level;
            $low = $diffs[(int) floor($effective * $alpha / 2)];
            $high = $diffs[min($effective - 1, (int) ceil($effective * (1 - $alpha / 2)) - 1)];
            $mean = array_sum($diffs) / $effective;
            $variance = array_sum(array_map(fn (float $d) => ($d - $mean) ** 2, $diffs)) / max(1, $effective - 1);

            return ['ci_low' => $low, 'ci_high' => $high, 'resamples' => $effective, 'resamples_requested' => $resamples, 'seed' => $seed, 'engine' => self::ENGINE, 'std_error' => sqrt($variance)];
        };

        // Misma entrada ⇒ mismo resultado: el informe se puede pedir muchas veces, el cómputo se hace una (memoria compartida; si no hay caché, se calcula).
        try {
            $key = 'outcomes:bootstrap:'.hash('sha256', json_encode([$arms, $effective, $seed, $level, self::ENGINE], JSON_THROW_ON_ERROR));

            return Cache::remember($key, 21600, $compute);
        } catch (\Throwable) {
            return $compute();
        }
    }

    /**
     * @param  list<float>  $values
     * @return array{0: int, 1: list<float>} tamaño del grupo y valores distintos de cero
     */
    private static function compress(array $values): array
    {
        return [count($values), array_values(array_filter($values, fn (float $v) => $v != 0.0))];
    }

    /** @param array{0: int, 1: list<float>} $arm */
    private static function resampledMean(Randomizer $rng, array $arm): float
    {
        [$n, $values] = $arm;
        $b = count($values);
        if ($n === 0 || $b === 0) {
            return 0.0;
        }
        $k = self::binomial($rng, $n, $b / $n);
        $sum = 0.0;
        for ($i = 0; $i < $k; $i++) {
            $sum += $values[$rng->getInt(0, $b - 1)];
        }

        return $sum / $n;
    }

    /** Binomial(n, p) EXACTA por tiempos de espera geométricos: O(n·min(p, 1−p)) sorteos. */
    private static function binomial(Randomizer $rng, int $n, float $p): int
    {
        if ($p >= 1.0) {
            return $n;
        }
        $flip = $p > 0.5;
        $q = $flip ? 1.0 - $p : $p;
        $logComplement = log(1.0 - $q);
        $position = 0;
        $successes = 0;
        while (true) {
            $position += (int) floor(log(1.0 - $rng->nextFloat()) / $logComplement) + 1;
            if ($position > $n) {
                break;
            }
            $successes++;
        }

        return $flip ? $n - $successes : $successes;
    }
}
