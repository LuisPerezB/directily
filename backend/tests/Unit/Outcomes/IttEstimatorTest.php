<?php

declare(strict_types=1);

namespace Tests\Unit\Outcomes;

use App\Services\Outcomes\IttEstimator;
use PHPUnit\Framework\TestCase;

/**
 * V2.5 · W5-C: los estimadores del análisis por intención de tratamiento contra valores de referencia PUBLICADOS (Newcombe, 1998, Statistics in Medicine 17:873–890)
 * y cálculos independientes, no contra la propia implementación.
 */
final class IttEstimatorTest extends TestCase
{
    private const Z95 = 1.959963984540054;

    public function test_wilson_interval_matches_the_published_reference(): void
    {
        [$low, $high] = IttEstimator::wilson(81, 263, self::Z95);   // Newcombe 1998, tabla I: 81/263 → (0,2553; 0,3662)

        $this->assertEqualsWithDelta(0.2553, $low, 5e-5);
        $this->assertEqualsWithDelta(0.3662, $high, 5e-5);
    }

    public function test_wilson_interval_is_valid_at_the_extremes_where_wald_fails(): void
    {
        [$low0, $high0] = IttEstimator::wilson(0, 10, self::Z95);
        [$low1, $high1] = IttEstimator::wilson(10, 10, self::Z95);

        $this->assertSame(0.0, $low0);
        $this->assertEqualsWithDelta(0.2775, $high0, 5e-5, '0 de 10 no es «imposible»: el límite superior es 27,8 %');
        $this->assertEqualsWithDelta(0.7225, $low1, 5e-5);
        $this->assertEqualsWithDelta(1.0, $high1, 1e-12);
        $this->assertSame([0.0, 1.0], IttEstimator::wilson(0, 0, self::Z95), 'sin unidades no hay información');
    }

    public function test_newcombe_difference_matches_the_published_reference(): void
    {
        $r = IttEstimator::rateDifference(56, 70, 48, 80, self::Z95);   // Newcombe 1998, ejemplo 1: 0,8000 − 0,6000 = 0,2000 (0,0524; 0,3339)

        $this->assertEqualsWithDelta(0.8, $r['rate_treatment'], 1e-12);
        $this->assertEqualsWithDelta(0.6, $r['rate_control'], 1e-12);
        $this->assertEqualsWithDelta(0.2, $r['difference'], 1e-12);
        $this->assertEqualsWithDelta(0.0524, $r['ci_low'], 5e-5);
        $this->assertEqualsWithDelta(0.3339, $r['ci_high'], 5e-5);
        $this->assertTrue($r['significant']);
    }

    public function test_the_interval_contains_zero_and_is_not_significant_when_there_is_no_effect(): void
    {
        $r = IttEstimator::rateDifference(15, 50, 15, 50, self::Z95);

        $this->assertEqualsWithDelta(0.0, $r['difference'], 1e-12);
        $this->assertLessThan(0, $r['ci_low']);
        $this->assertGreaterThan(0, $r['ci_high']);
        $this->assertFalse($r['significant']);
        $this->assertEqualsWithDelta(-$r['ci_low'], $r['ci_high'], 1e-12, 'con tasas iguales y tamaños iguales el intervalo es simétrico');
    }

    public function test_a_wider_confidence_level_gives_a_wider_interval(): void
    {
        $narrow = IttEstimator::rateDifference(30, 100, 20, 100, 1.2815515655446004);   // 80 %
        $wide = IttEstimator::rateDifference(30, 100, 20, 100, 2.5758293035489004);     // 99 %

        $this->assertLessThan($narrow['ci_low'], $wide['ci_low']);
        $this->assertGreaterThan($narrow['ci_high'], $wide['ci_high']);
    }

    public function test_a_negative_effect_is_reported_with_its_sign(): void
    {
        $r = IttEstimator::rateDifference(10, 100, 40, 100, self::Z95);

        $this->assertEqualsWithDelta(-0.3, $r['difference'], 1e-12);
        $this->assertLessThan(0, $r['ci_high']);
        $this->assertTrue($r['significant'], 'significativamente NEGATIVO: el intervalo excluye 0 por debajo');
    }

    public function test_mean_difference_with_welch_standard_error(): void
    {
        // 12 unidades con 4 y 28 con 0 (media 1,2) frente a 6 con 4 y 34 con 0 (media 0,6): valores de referencia calculados aparte.
        $t = array_merge(array_fill(0, 12, 4.0), array_fill(0, 28, 0.0));
        $c = array_merge(array_fill(0, 6, 4.0), array_fill(0, 34, 0.0));

        $r = IttEstimator::meanDifference($t, $c, self::Z95);

        $this->assertEqualsWithDelta(1.2, $r['mean_treatment'], 1e-12);
        $this->assertEqualsWithDelta(0.6, $r['mean_control'], 1e-12);
        $this->assertEqualsWithDelta(0.6, $r['difference'], 1e-12);
        $this->assertEqualsWithDelta(0.3721042, $r['std_error'], 1e-7);
        $this->assertEqualsWithDelta(-0.1293108, $r['ci_low'], 1e-7);
        $this->assertEqualsWithDelta(1.3293108, $r['ci_high'], 1e-7);
        $this->assertFalse($r['significant']);
    }

    public function test_mean_difference_needs_two_units_per_group(): void
    {
        $this->assertNull(IttEstimator::meanDifference([1.0], [1.0, 2.0], self::Z95));
        $this->assertNull(IttEstimator::meanDifference([1.0, 2.0], [], self::Z95));
    }
}
