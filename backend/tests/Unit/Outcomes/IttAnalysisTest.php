<?php

declare(strict_types=1);

namespace Tests\Unit\Outcomes;

use App\Models\Tenant;
use App\Services\Outcomes\IttAnalysis;
use App\Services\Outcomes\OutcomeSettings;
use Tests\TestCase;

/**
 * V2.5 · W5-C: el análisis ITT solo publica una estimación con control, muestra mínima por grupo y una única fracción de control; en cualquier otro caso queda el
 * motivo y NO hay diferencia ni intervalo.
 */
final class IttAnalysisTest extends TestCase
{
    private function settings(array $outcomes = []): OutcomeSettings
    {
        return OutcomeSettings::forTenant(new Tenant(['settings' => ['outcomes' => $outcomes]]));
    }

    /** @return list<array<string, mixed>> */
    private function units(string $group, int $n, int $converted, float $contribution = 4.0, float $fraction = 0.5, bool $inPopulation = true, int $startId = 1): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $isConverted = $i < $converted;
            $out[] = [
                'customer_id' => $startId + $i, 'group' => $group, 'converted' => $isConverted ? 1 : 0, 'contribution' => $inPopulation ? ($isConverted ? $contribution : 0.0) : null,
                'in_population' => $inPopulation, 'control_fraction' => $fraction, 'contaminated' => false,
            ];
        }

        return $out;
    }

    public function test_it_estimates_with_both_groups_at_the_minimum_size_and_enough_buyers(): void
    {
        $units = array_merge($this->units('treatment', 30, 15), $this->units('control', 30, 10, startId: 1000));

        $r = IttAnalysis::estimate($units, $this->settings(), 'exp-a', 30);

        $this->assertSame('estimated', $r['status']);
        $this->assertEquals(['treatment' => 30, 'control' => 30, 'treatment_converted' => 15, 'control_converted' => 10], $r['sample']);
        $this->assertEqualsWithDelta(1 / 6, $r['conversion']['difference'], 1e-6);
        $this->assertNotNull($r['conversion']['ci_low']);
        $this->assertSame('estimated', $r['contribution']['status']);
        $this->assertEqualsWithDelta((15 - 10) * 4 / 30, $r['contribution']['difference_per_unit'], 1e-3);
        $this->assertEqualsWithDelta((15 - 10) * 4, $r['incremental']['contribution'], 0.01);
        $this->assertSame(30, $r['incremental']['treated_units']);
        $this->assertSame([0.5], $r['control_fractions']);
        $this->assertEquals(['treatment' => 15, 'control' => 10], $r['contribution']['buyers']);
    }

    public function test_one_unit_below_the_minimum_is_insufficient_and_publishes_no_difference(): void
    {
        $units = array_merge($this->units('treatment', 29, 15), $this->units('control', 30, 6, startId: 1000));

        $r = IttAnalysis::estimate($units, $this->settings());

        $this->assertSame('insufficient_sample', $r['status']);
        $this->assertNull($r['conversion']['difference']);
        $this->assertNull($r['conversion']['ci_low']);
        $this->assertNull($r['incremental']);
        $this->assertSame('not_estimated', $r['contribution']['status']);
        $this->assertStringContainsString('atribución no demostrada', $r['note']);
    }

    public function test_the_minimum_group_size_is_configurable_per_company_above_the_floor(): void
    {
        $units = array_merge($this->units('treatment', 25, 12), $this->units('control', 25, 10, startId: 1000));

        $this->assertSame('insufficient_sample', IttAnalysis::estimate($units, $this->settings())['status'], '25 < 30 por defecto');
        $this->assertSame('estimated', IttAnalysis::estimate($units, $this->settings(['min_group_size' => 25]))['status']);
        $this->assertSame('insufficient_sample', IttAnalysis::estimate($units, $this->settings(['min_group_size' => 26]))['status']);
    }

    public function test_the_floors_stop_a_company_from_configuring_a_meaningless_minimum(): void
    {
        $this->assertSame(30, $this->settings(['min_group_size' => 10])->minGroupSize(), 'por debajo del piso de 20 se ignora: vale el valor por defecto');
        $this->assertSame(30, $this->settings(['min_group_size' => 19])->minGroupSize());
        $this->assertSame(20, $this->settings(['min_group_size' => 20])->minGroupSize(), 'el piso mismo sí se admite');
        $this->assertSame(10, $this->settings(['min_buyers_per_arm' => 4])->minBuyersPerArm(), 'el piso de compradores es 5');
        $this->assertSame(5, $this->settings(['min_buyers_per_arm' => 5])->minBuyersPerArm());
        $this->assertSame(2000, $this->settings(['bootstrap_resamples' => 100])->bootstrapResamples(), 'menos de 500 remuestreos no estabiliza los percentiles');
        $this->assertSame(1000, $this->settings(['bootstrap_resamples' => 1000])->bootstrapResamples());
        $this->assertSame(['min_group_size' => 20, 'min_buyers_per_arm' => 5, 'bootstrap_resamples' => 500], config('outcomes.floors'), 'los pisos de producción');
    }

    public function test_with_too_few_buyers_in_an_arm_no_money_interval_or_incremental_is_published(): void
    {
        $units = array_merge($this->units('treatment', 40, 12), $this->units('control', 40, 9, startId: 1000));   // 9 compradores < 10 en el control

        $r = IttAnalysis::estimate($units, $this->settings(), 'exp-a', 30);

        $this->assertSame('estimated', $r['status'], 'la conversión sí se estima');
        $this->assertEqualsWithDelta(12 / 40 - 9 / 40, $r['conversion']['difference'], 1e-6);
        $this->assertSame('not_estimable', $r['contribution']['status']);
        $this->assertSame('buyers_below_min_per_arm', $r['contribution']['reason']);
        $this->assertEquals(['treatment' => 12, 'control' => 9], $r['contribution']['buyers']);
        $this->assertArrayNotHasKey('ci_low', $r['contribution']);
        $this->assertArrayNotHasKey('difference_per_unit', $r['contribution']);
        $this->assertNull($r['incremental']);
    }

    public function test_nobody_buying_gives_no_zero_width_interval_and_no_significant_loss(): void
    {
        $units = array_merge($this->units('treatment', 30, 0), $this->units('control', 30, 0, startId: 1000));   // el caso F1 de la revisión

        $r = IttAnalysis::estimate($units, $this->settings(), 'exp-a', 30);

        $this->assertSame('estimated', $r['status']);
        $this->assertSame('not_estimable', $r['contribution']['status']);
        $this->assertNull($r['incremental']);
        $this->assertLessThan(0, $r['conversion']['ci_low'], 'la conversión sí publica su intervalo honesto (Newcombe), que incluye 0');
        $this->assertGreaterThan(0, $r['conversion']['ci_high']);
    }

    public function test_identical_values_give_zero_variance_and_no_interval(): void
    {
        $units = array_merge($this->units('treatment', 30, 30), $this->units('control', 30, 30, startId: 1000));   // todos compran lo mismo: varianza nula

        $r = IttAnalysis::estimate($units, $this->settings(), 'exp-a', 30);

        $this->assertSame('not_estimable', $r['contribution']['status']);
        $this->assertSame('zero_variance', $r['contribution']['reason']);
        $this->assertNull($r['incremental']);
    }

    public function test_the_money_interval_is_a_deterministic_seeded_bootstrap_with_welch_only_as_a_diagnostic(): void
    {
        $units = array_merge($this->units('treatment', 60, 30), $this->units('control', 60, 12, startId: 1000));

        $a = IttAnalysis::estimate($units, $this->settings(), 'exp-a', 30);
        $b = IttAnalysis::estimate($units, $this->settings(), 'exp-a', 30);
        $other = IttAnalysis::estimate($units, $this->settings(), 'exp-b', 30);

        $this->assertSame($a['contribution'], $b['contribution'], 'misma semilla ⇒ mismo intervalo, bit a bit');
        $i = $a['contribution']['interval'];
        $this->assertSame('bootstrap_percentile', $i['method']);
        $this->assertSame(2000, $i['resamples']);
        $this->assertSame($this->settings()->bootstrapSeed('exp-a', 30), $i['seed']);
        $this->assertNotSame($i['seed'], $other['contribution']['interval']['seed'], 'otro experimento, otra semilla');
        $this->assertNotSame($i['std_error'], $other['contribution']['interval']['std_error'], 'otra semilla, otra simulación');
        $this->assertEqualsWithDelta(0.5465, $a['contribution']['diagnostic_welch']['ci_low'], 1e-4);
        $this->assertEqualsWithDelta(1.8535, $a['contribution']['diagnostic_welch']['ci_high'], 1e-4);
        $this->assertNotEquals($a['contribution']['diagnostic_welch']['ci_low'], $a['contribution']['ci_low'], 'el publicado NO es el de Welch');
    }

    public function test_a_heavy_tail_among_buyers_is_flagged_because_no_interval_covers_its_nominal_level_there(): void
    {
        $flat = IttAnalysis::estimate(array_merge($this->units('treatment', 30, 15), $this->units('control', 30, 10, startId: 1000)), $this->settings(), 'k', 30);
        $this->assertFalse($flat['contribution']['tail_diagnostic']['heavy_tailed'], 'todos compran lo mismo');
        $this->assertEqualsWithDelta(0.0, $flat['contribution']['tail_diagnostic']['buyer_cv'], 1e-9);

        // Un solo comprador de 500 entre compradores de 4: concentra más del 25 % de su grupo y dispara el coeficiente de variación.
        $treatment = $this->units('treatment', 30, 15);
        $treatment[0]['contribution'] = 500.0;
        $heavy = IttAnalysis::estimate(array_merge($treatment, $this->units('control', 30, 10, startId: 1000)), $this->settings(), 'k', 30);
        $d = $heavy['contribution']['tail_diagnostic'];
        $this->assertTrue($d['heavy_tailed']);
        $this->assertGreaterThan(0.25, $d['largest_buyer_share_of_arm']);
        $this->assertGreaterThan(1.5, $d['buyer_cv']);
        $this->assertStringContainsString('léase como indicativo', $d['note']);
    }

    public function test_the_sample_ratio_check_flags_a_split_incompatible_with_the_registered_fraction(): void
    {
        $ok = IttAnalysis::estimate(array_merge($this->units('treatment', 30, 15), $this->units('control', 30, 10, startId: 1000)), $this->settings(), 'k', 30);
        $this->assertSame('ok', $ok['sample_ratio']['status']);
        $this->assertEqualsWithDelta(0.5, $ok['sample_ratio']['observed_control_share'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $ok['sample_ratio']['expected_control_fraction'], 1e-9);

        // 90 tratamiento y 10 control con una fracción registrada de 0,5: la asignación o el conteo están rotos.
        $bad = IttAnalysis::estimate(array_merge($this->units('treatment', 90, 40), $this->units('control', 10, 4, startId: 1000)), $this->settings(), 'k', 30);
        $this->assertSame('mismatch', $bad['sample_ratio']['status']);
        $this->assertLessThan(0.001, $bad['sample_ratio']['p_value']);
        $this->assertEqualsWithDelta(0.1, $bad['sample_ratio']['observed_control_share'], 1e-9);

        $notEvaluable = IttAnalysis::estimate($this->units('treatment', 50, 10), $this->settings());
        $this->assertSame('not_evaluable', $notEvaluable['sample_ratio']['status']);
        $this->assertSame('ok', IttAnalysis::sampleRatio(82, 18, [0.2])['status'], '18 de 100 frente a una fracción de 0,2 es compatible');
        $this->assertSame('not_evaluable', IttAnalysis::sampleRatio(50, 50, [0.3, 0.5])['status'], 'con varias fracciones registradas no hay una esperada');
    }

    public function test_without_control_or_without_treatment_there_is_no_estimate(): void
    {
        $this->assertSame('no_control_group', IttAnalysis::estimate($this->units('treatment', 50, 10), $this->settings())['status']);
        $this->assertSame('no_treatment_group', IttAnalysis::estimate($this->units('control', 50, 10), $this->settings())['status']);
        $this->assertSame('no_control_group', IttAnalysis::estimate([], $this->settings())['status']);
    }

    public function test_different_control_fractions_cannot_be_pooled(): void
    {
        $units = array_merge($this->units('treatment', 30, 15, fraction: 0.5), $this->units('control', 15, 3, fraction: 0.5, startId: 1000), $this->units('control', 15, 3, fraction: 0.3, startId: 2000));

        $r = IttAnalysis::estimate($units, $this->settings());

        $this->assertSame('heterogeneous_control_fraction', $r['status']);
        $this->assertSame([0.3, 0.5], $r['control_fractions']);
        $this->assertNull($r['conversion']['difference']);
    }

    public function test_units_with_unreliable_cost_leave_the_contribution_analysis_but_not_the_conversion_one(): void
    {
        $units = array_merge(
            $this->units('treatment', 30, 15), $this->units('control', 30, 6, startId: 1000),
            $this->units('treatment', 5, 5, inPopulation: false, startId: 3000), $this->units('control', 5, 0, inPopulation: false, startId: 4000),
        );

        $r = IttAnalysis::estimate($units, $this->settings());

        $this->assertSame(35, $r['sample']['treatment'], 'la conversión usa a todos');
        $this->assertSame(['treatment' => 30, 'control' => 30], $r['contribution']['population'], 'la contribución solo a quienes tienen costo confiable en su alcance');
        $this->assertSame(['treatment' => 5, 'control' => 5], $r['contribution']['excluded_unreliable_cost']);
    }

    public function test_a_contribution_population_just_below_the_minimum_is_insufficient_even_if_it_has_enough_buyers(): void
    {
        // 30 unidades por grupo en la conversión, pero solo 25 con costo confiable en todo su alcance: la población de contribución (25) está bajo el mínimo (30).
        $treatment = array_merge($this->units('treatment', 25, 15), $this->units('treatment', 5, 3, inPopulation: false, startId: 500));
        $control = array_merge($this->units('control', 25, 12, startId: 1000), $this->units('control', 5, 2, inPopulation: false, startId: 1500));

        $r = IttAnalysis::estimate(array_merge($treatment, $control), $this->settings(), 'k', 30);

        $this->assertSame('estimated', $r['status']);
        $this->assertSame('insufficient_sample', $r['contribution']['status']);
        $this->assertSame('contribution_population_below_min_group_size', $r['contribution']['reason']);
        $this->assertEquals(['treatment' => 25, 'control' => 25], $r['contribution']['population']);
        $this->assertNull($r['incremental']);
    }

    public function test_a_contribution_population_below_the_minimum_gives_conversion_only(): void
    {
        $units = array_merge(
            $this->units('treatment', 30, 15, inPopulation: false), $this->units('control', 30, 6, inPopulation: false, startId: 1000),
        );

        $r = IttAnalysis::estimate($units, $this->settings());

        $this->assertSame('estimated', $r['status'], 'la conversión sí se estima');
        $this->assertSame('insufficient_sample', $r['contribution']['status']);
        $this->assertSame('contribution_population_below_min_group_size', $r['contribution']['reason']);
        $this->assertNull($r['incremental'], 'sin población con costo confiable no hay cifra monetaria');
    }

    public function test_control_units_with_a_registered_action_are_counted_as_contamination(): void
    {
        $control = $this->units('control', 30, 6, startId: 1000);
        $control[0]['contaminated'] = true;
        $control[1]['contaminated'] = true;

        $r = IttAnalysis::estimate(array_merge($this->units('treatment', 30, 15), $control), $this->settings());

        $this->assertSame(2, $r['contamination']['control_units_with_registered_action']);
        $this->assertSame(30, $r['sample']['control'], 'siguen en el control: intención de tratamiento');
    }

    public function test_the_confidence_level_is_configurable_and_widens_the_interval(): void
    {
        $units = array_merge($this->units('treatment', 40, 20), $this->units('control', 40, 10, startId: 1000));

        $at95 = IttAnalysis::estimate($units, $this->settings());
        $at99 = IttAnalysis::estimate($units, $this->settings(['confidence_level' => 0.99]));

        $this->assertEquals(0.95, $at95['confidence_level']);
        $this->assertEquals(0.99, $at99['confidence_level']);
        $this->assertLessThan($at95['conversion']['ci_low'], $at99['conversion']['ci_low']);
        $this->assertGreaterThan($at95['conversion']['ci_high'], $at99['conversion']['ci_high']);
        $this->assertEquals(0.95, $this->settings(['confidence_level' => 0.97])->confidenceLevel(), 'un nivel no admitido se ignora');
    }
}
