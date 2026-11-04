<?php

declare(strict_types=1);

namespace Tests\Unit\Outcomes;

use App\Services\Outcomes\BootstrapInterval;
use Tests\TestCase;

/**
 * V2.5 · W5-C: el intervalo percentil bootstrap de la contribución por unidad es determinista (semilla registrada), remuestrea dentro de cada grupo y reproduce la distribución
 * bootstrap EXACTA en un caso con solución cerrada.
 */
final class BootstrapIntervalTest extends TestCase
{
    /** @return list<float> */
    private function arm(int $n, int $buyers, float $value = 4.0): array
    {
        return array_merge(array_fill(0, $buyers, $value), array_fill(0, $n - $buyers, 0.0));
    }

    public function test_it_reproduces_the_exact_bootstrap_distribution_of_a_closed_form_case(): void
    {
        // Compradores con valor 4 en 30/60 (tratamiento) y 12/60 (control): D = 4·(K_T − K_C)/60 con K_T ~ Bin(60; 0,5) y K_C ~ Bin(60; 0,2).
        // Percentiles 2,5 % y 97,5 % de esa distribución, calculados aparte con la convolución exacta: [0,5333; 1,8667]. La retícula de D es de 0,0667.
        $r = BootstrapInterval::meanDifference($this->arm(60, 30), $this->arm(60, 12), 2000, 123, 0.95, 40000000);

        $this->assertEqualsWithDelta(0.5333, $r['ci_low'], 0.14);
        $this->assertEqualsWithDelta(1.8667, $r['ci_high'], 0.14);
        $this->assertSame(2000, $r['resamples']);
        $this->assertSame(123, $r['seed']);
        $this->assertSame('mt19937-percentile-v1', $r['engine']);
        // Error estándar bootstrap de la diferencia ≈ 0,33 (σ = 4·√(0,25/60 + 0,16/60)).
        $this->assertEqualsWithDelta(4 * sqrt(0.25 / 60 + 0.16 / 60), $r['std_error'], 0.03);
    }

    public function test_the_same_seed_gives_the_same_interval_and_another_seed_a_different_simulation(): void
    {
        $t = $this->arm(60, 30);
        $c = $this->arm(60, 12);

        $a = BootstrapInterval::meanDifference($t, $c, 2000, 7, 0.95, 40000000);
        $b = BootstrapInterval::meanDifference($t, $c, 2000, 7, 0.95, 40000000);
        $other = BootstrapInterval::meanDifference($t, $c, 2000, 8, 0.95, 40000000);

        $this->assertSame($a, $b);
        $this->assertNotSame($a['std_error'], $other['std_error']);
    }

    public function test_it_resamples_within_each_arm_so_an_arm_of_zeros_stays_zero(): void
    {
        $r = BootstrapInterval::meanDifference($this->arm(40, 0), $this->arm(40, 10), 1000, 1, 0.95, 40000000);

        $this->assertLessThanOrEqual(0.0, $r['ci_high'], 'el tratamiento nunca compra: ninguna diferencia positiva es posible');
        $this->assertLessThan(0.0, $r['ci_low']);
    }

    public function test_a_wider_level_widens_the_interval(): void
    {
        $t = $this->arm(80, 24);
        $c = $this->arm(80, 12);

        $narrow = BootstrapInterval::meanDifference($t, $c, 2000, 5, 0.80, 40000000);
        $wide = BootstrapInterval::meanDifference($t, $c, 2000, 5, 0.99, 40000000);

        $this->assertLessThan($narrow['ci_low'], $wide['ci_low']);
        $this->assertGreaterThan($narrow['ci_high'], $wide['ci_high']);
    }

    public function test_the_compute_budget_lowers_the_resamples_and_below_the_minimum_publishes_nothing(): void
    {
        $t = $this->arm(2000, 600);
        $c = $this->arm(2000, 400);
        // Costo por remuestreo ≈ 2·min(b, n−b) + b + 2 por grupo = 3·600+2 + 3·400+2 = 3004; con 3 000 000 de sorteos caben ~998 remuestreos.
        $lowered = BootstrapInterval::meanDifference($t, $c, 2000, 3, 0.95, 3000000);
        $this->assertArrayNotHasKey('unavailable', $lowered);
        $this->assertLessThan(2000, $lowered['resamples']);
        $this->assertGreaterThanOrEqual(BootstrapInterval::MIN_RESAMPLES, $lowered['resamples']);
        $this->assertSame(2000, $lowered['resamples_requested']);

        $this->assertSame(['unavailable' => 'bootstrap_budget_exceeded'], BootstrapInterval::meanDifference($t, $c, 2000, 3, 0.95, 1000000));
    }

    public function test_heavy_tails_widen_the_interval_relative_to_the_normal_approximation(): void
    {
        // Compradores con valores muy asimétricos (lognormal): el percentil bootstrap es asimétrico alrededor de la diferencia observada.
        mt_srand(11);
        $tail = fn (int $n, int $buyers) => array_merge(array_map(fn () => exp(2.0 + 1.5 * (-log(1 - mt_rand() / (mt_getrandmax() + 1)))), range(1, $buyers)), array_fill(0, $n - $buyers, 0.0));
        $t = $tail(80, 24);
        $c = $tail(80, 12);

        $r = BootstrapInterval::meanDifference($t, $c, 2000, 9, 0.95, 40000000);
        $d = array_sum($t) / 80 - array_sum($c) / 80;

        $this->assertNotEqualsWithDelta($d - $r['ci_low'], $r['ci_high'] - $d, 1e-6, 'con cola pesada el intervalo no es simétrico');
    }
}
