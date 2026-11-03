<?php

declare(strict_types=1);

namespace Tests\Feature\Outcomes;

use App\Models\DecisionRecommendation;
use App\Services\Decision\ExperimentAssigner;
use App\Services\Outcomes\ExperimentResolver;
use App\Services\Outcomes\OutcomeMeasurementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Outcomes\Support\OutcomeFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-C (ADR-V25-14): análisis por intención de tratamiento (ITT) con tratamiento y control CONOCIDOS. Un efecto sembrado se recupera con su estimación e
 * intervalo (valores de referencia calculados aparte); sin control, con una muestra insuficiente o con una asignación no verificable NO hay afirmación incremental;
 * y `causally_attributable` nunca se produce.
 */
final class IttOutcomeTest extends TestCase
{
    use OutcomeFixtures;
    use RefreshDatabase;

    private function day(int $daysAgo): string
    {
        return $this->today()->subDays($daysAgo)->toDateString();
    }

    /** @return array<string, mixed> */
    private function measure(array $w): array
    {
        return app(OutcomeMeasurementService::class)->measure($w['tenant']);
    }

    /**
     * Cohorte con grupos CONOCIDOS: `$nT` clientes de tratamiento (los primeros `$xT` compran) y `$nC` de control (los primeros `$xC` compran). Cada compra: 2 u de p1
     * a 8, costo 6 → contribución 4.
     *
     * @return array{treatment: list<DecisionRecommendation>, control: list<DecisionRecommendation>}
     */
    private function cohort(array $w, int $nT, int $xT, int $nC, int $xC, string $key = 'exp-prueba', int $purchaseDaysAgo = 20, string $product = 'p1', array $override = []): array
    {
        $customers = $this->newCustomers($w['tenant'], $nT + $nC, 'CLI-'.substr(md5($key.$nT.$nC.microtime()), 0, 5));
        $out = ['treatment' => [], 'control' => []];
        foreach ($customers as $i => $customerId) {
            $group = $i < $nT ? 'treatment' : 'control';
            $index = $i < $nT ? $i : $i - $nT;
            $rec = $this->contactFor($w, $customerId, $product, $override);
            $this->assignExperiment($w['tenant'], $rec, $group, $key);
            if ($index < ($group === 'treatment' ? $xT : $xC)) {
                $this->purchase($w['tenant'], $customerId, $w['cat']['products'][$product], $this->day($purchaseDaysAgo), 2, 8.0, 6.0);
            }
            $out[$group][] = $rec;
        }

        return $out;
    }

    public function test_a_known_effect_is_recovered_with_its_estimate_sample_sizes_and_interval(): void
    {
        $w = $this->outcomeWorld(40);
        $cohort = $this->cohort($w, 60, 30, 60, 12);   // 50 % vs 20 %: efecto verdadero +30 puntos; contribución 4 por compra

        $report = $this->measure($w);

        $this->assertSame(120, $report['measured']);
        $this->assertSame('estimated', $report['experiments']['exp-prueba|'.$this->day(40).'|30']['status']);
        $o = $this->outcomeOf((int) $cohort['treatment'][0]->id);
        // La fila NO se estampa como incremental: el incremento es del agregado (la comparación tratamiento-control), no de una compra ni de un cliente.
        $this->assertContains($o['classification'], ['observed', 'temporally_associated']);
        $this->assertSame('none', $o['method']);
        $this->assertTrue($o['evidence']['experiment']['in_itt'], 'pero la fila enlaza con el análisis');
        $itt = $o['evidence']['itt'];
        $this->assertSame('estimated', $itt['status']);
        $this->assertSame('itt', $itt['method']);
        $this->assertEquals(['treatment' => 60, 'control' => 60, 'treatment_converted' => 30, 'control_converted' => 12], $itt['sample'], 'tamaños de muestra y conversiones');
        $this->assertEquals(0.95, $itt['confidence_level']);
        // Valores de referencia calculados con el método de Newcombe (Wilson híbrido) fuera de PHP.
        $this->assertEqualsWithDelta(0.5, $itt['conversion']['rate_treatment'], 1e-9);
        $this->assertEqualsWithDelta(0.2, $itt['conversion']['rate_control'], 1e-9);
        $this->assertEqualsWithDelta(0.3, $itt['conversion']['difference'], 1e-6, 'el efecto sembrado');
        $this->assertEqualsWithDelta(0.129929, $itt['conversion']['ci_low'], 1e-6);
        $this->assertEqualsWithDelta(0.447378, $itt['conversion']['ci_high'], 1e-6);
        $this->assertTrue($itt['conversion']['significant'], 'el intervalo no incluye 0');
        // Contribución por unidad (incluye ceros) y total incremental sobre las unidades tratadas.
        $this->assertSame('estimated', $itt['contribution']['status']);
        $this->assertEqualsWithDelta(2.0, $itt['contribution']['mean_treatment'], 1e-9);
        $this->assertEqualsWithDelta(0.8, $itt['contribution']['mean_control'], 1e-9);
        $this->assertEqualsWithDelta(1.2, $itt['contribution']['difference_per_unit'], 1e-9);
        // Intervalo percentil bootstrap: contra el percentil EXACTO de la distribución bootstrap (D = 4·(K_T − K_C)/60 con K_T ~ Bin(60; 0,5) y K_C ~ Bin(60; 0,2), calculado aparte:
        // [0,5333; 1,8667]); la red de la retícula es de 0,0667 y el error de simulación con 2000 remuestreos, ~0,02.
        $this->assertEqualsWithDelta(0.5333, $itt['contribution']['ci_low'], 0.14);
        $this->assertEqualsWithDelta(1.8667, $itt['contribution']['ci_high'], 0.14);
        $this->assertTrue($itt['contribution']['significant']);
        $this->assertSame('bootstrap_percentile', $itt['contribution']['interval']['method']);
        $this->assertSame(2000, $itt['contribution']['interval']['resamples']);
        $this->assertIsInt($itt['contribution']['interval']['seed'], 'la semilla queda registrada');
        $this->assertEquals(['treatment' => 30, 'control' => 12], $itt['contribution']['buyers']);
        // El intervalo normal de Welch queda solo como diagnóstico.
        $this->assertEqualsWithDelta(0.5465, $itt['contribution']['diagnostic_welch']['ci_low'], 1e-4);
        $this->assertEqualsWithDelta(1.8535, $itt['contribution']['diagnostic_welch']['ci_high'], 1e-4);
        $this->assertStringContainsString('Solo diagnóstico', $itt['contribution']['diagnostic_welch']['note']);
        $this->assertEqualsWithDelta(72.0, $itt['incremental']['contribution'], 1e-9);
        $this->assertEqualsWithDelta(32.0, $itt['incremental']['ci_low'], 8.4);
        $this->assertEqualsWithDelta(112.0, $itt['incremental']['ci_high'], 8.4);
        $this->assertSame(60, $itt['incremental']['treated_units']);

        // Ninguna fila se estampa «incremental estimado»; todas las unidades enlazan con el análisis (contactadas o no: intención de tratamiento); el control es la línea base observada.
        $this->assertSame(0, DB::table('decision_outcomes')->where('classification', 'incremental_estimated')->count());
        $this->assertSame(0, DB::table('decision_outcomes')->where('method', '<>', 'none')->count());
        $this->assertSame(120, DB::table('decision_outcomes')->whereRaw("evidence->'experiment'->>'in_itt' = 'true'")->count());
        $c = $this->outcomeOf((int) $cohort['control'][0]->id);
        $this->assertSame('observed', $c['classification'], 'el control es la línea base: no se le atribuye nada');
        $this->assertSame('control', $c['action_kind']);
        $this->assertEquals(0.0, (float) $c['contact_cost']);
        $this->assertSame('control_group_baseline', $c['evidence']['classification_reason']);
        $this->assertSame(0, DB::table('decision_outcomes')->where('classification', 'causally_attributable')->count(), 'causally_attributable no lo produce este paquete');
        $this->assertSame(0, DB::table('decision_outcomes')->where('method', 'quasi_experiment')->count());
        // Cada unidad se cuenta una vez (la recomendación representante de su cliente).
        $this->assertSame(120, DB::table('decision_outcomes')->whereRaw("evidence->'experiment'->>'itt_counted' = 'true'")->count());
    }

    public function test_an_interval_that_includes_zero_is_reported_as_not_significant_not_as_a_win(): void
    {
        $w = $this->outcomeWorld(40);
        $cohort = $this->cohort($w, 40, 12, 40, 10);   // 30 % vs 25 %: diferencia +5 puntos; con 40 por grupo el intervalo incluye 0

        $this->measure($w);

        $itt = $this->outcomeOf((int) $cohort['treatment'][0]->id)['evidence']['itt'];
        $this->assertSame('estimated', $itt['status']);
        $this->assertEqualsWithDelta(0.05, $itt['conversion']['difference'], 1e-6);
        $this->assertLessThan(0, $itt['conversion']['ci_low']);
        $this->assertGreaterThan(0, $itt['conversion']['ci_high']);
        $this->assertFalse($itt['conversion']['significant'], 'el intervalo incluye 0: efecto no distinguible de cero');
        $this->assertEqualsWithDelta(0.2, $itt['contribution']['difference_per_unit'], 1e-9);
        // Percentil exacto de la distribución bootstrap (calculado aparte): [−0,6; 1,0] por unidad.
        $this->assertEqualsWithDelta(-0.6, $itt['contribution']['ci_low'], 0.2);
        $this->assertEqualsWithDelta(1.0, $itt['contribution']['ci_high'], 0.2);
        $this->assertFalse($itt['contribution']['significant']);
        $this->assertEqualsWithDelta(8.0, $itt['incremental']['contribution'], 1e-9);
        $this->assertLessThan(0, $itt['incremental']['ci_low']);
        $this->assertGreaterThan(0, $itt['incremental']['ci_high']);
    }

    public function test_with_fewer_buyers_than_the_minimum_per_arm_no_money_interval_is_published(): void
    {
        $w = $this->outcomeWorld(40);
        $cohort = $this->cohort($w, 40, 12, 40, 6);   // el control tiene 6 compradores < 10: la conversión se estima, la contribución NO

        $this->measure($w);

        $itt = $this->outcomeOf((int) $cohort['treatment'][0]->id)['evidence']['itt'];
        $this->assertSame('estimated', $itt['status']);
        $this->assertEqualsWithDelta(0.15, $itt['conversion']['difference'], 1e-6);
        $this->assertSame('not_estimable', $itt['contribution']['status']);
        $this->assertSame('buyers_below_min_per_arm', $itt['contribution']['reason']);
        $this->assertEquals(['treatment' => 12, 'control' => 6], $itt['contribution']['buyers']);
        $this->assertArrayNotHasKey('ci_low', $itt['contribution'], 'ni intervalo ni diferencia monetaria');
        $this->assertNull($itt['incremental']);
        $this->assertSame(10, $itt['min_buyers_per_arm']);
    }

    public function test_the_analysis_follows_the_assigned_group_not_who_was_actually_contacted(): void
    {
        $w = $this->outcomeWorld(40);
        $this->outcomeSettings($w, ['min_group_size' => 5, 'min_buyers_per_arm' => 1]);
        $cohort = $this->cohort($w, 12, 6, 12, 2);
        // De los 12 de tratamiento solo se contactó a 3 (los tres compradores); el resto, asignado a tratamiento, quedó sin contactar.
        foreach (array_slice($cohort['treatment'], 0, 3) as $rec) {
            $this->executeAt($w, $rec, $this->today()->subDays(30)->toDateString().' 10:00:00-05');
        }
        // Un control con una acción registrada (contaminación): se informa, y SIGUE en el control (ITT).
        DB::table('decision_events')->insert([
            'tenant_id' => $w['tenant']->id, 'recommendation_id' => $cohort['control'][0]->id, 'event' => 'feedback', 'from_status' => 'suggested', 'to_status' => 'suggested',
            'agenda_status' => 'contactado', 'actor_user_id' => $w['analyst']->id, 'actor_kind' => 'user', 'payload' => '{}', 'idempotency_key' => str_repeat('c', 64),
            'created_at' => $this->today()->subDays(30)->toDateString().' 11:00:00-05',
        ]);

        $this->measure($w);

        $itt = $this->outcomeOf((int) $cohort['treatment'][5]->id)['evidence']['itt'];
        $this->assertEquals(['treatment' => 12, 'control' => 12, 'treatment_converted' => 6, 'control_converted' => 2], $itt['sample'], 'los 12 asignados cuentan, no solo los 3 contactados');
        $this->assertSame(1, $itt['contamination']['control_units_with_registered_action']);
        $contaminated = $this->outcomeOf((int) $cohort['control'][0]->id);
        $this->assertSame('control', $contaminated['action_kind']);
        $this->assertTrue($contaminated['evidence']['action']['control_contaminated']);
        $this->assertSame('observed', $contaminated['classification']);
        $this->assertEquals(0.0, (float) $contaminated['contact_cost']);
        // Cada fila conserva lo que le pasó: quien se contactó y compró DESPUÉS queda asociado temporalmente; el asignado a tratamiento que nadie contactó, observado. El
        // incremento no se estampa en ninguna de las dos: es del agregado, y ambas cuentan en él (intención de tratamiento).
        $contacted = $this->outcomeOf((int) $cohort['treatment'][0]->id);
        $this->assertSame('contacted', $contacted['action_kind']);
        $this->assertSame('temporally_associated', $contacted['classification']);
        $this->assertTrue($contacted['evidence']['experiment']['in_itt']);
        $notContacted = $this->outcomeOf((int) $cohort['treatment'][4]->id);
        $this->assertSame('not_contacted', $notContacted['action_kind']);
        $this->assertSame('observed', $notContacted['classification']);
        $this->assertTrue($notContacted['evidence']['experiment']['in_itt']);
    }

    // ───────────────────────── sin afirmación incremental ─────────────────────────

    public function test_without_a_control_group_nothing_is_ever_incremental(): void
    {
        $w = $this->outcomeWorld(40);
        $customers = $this->newCustomers($w['tenant'], 40);
        $recs = [];
        foreach ($customers as $i => $customerId) {
            $recs[$i] = $this->contactFor($w, $customerId);   // sin experimento: fracción de control 0
            if ($i < 20) {
                $this->executeAt($w, $recs[$i], $this->today()->subDays(30)->toDateString().' 10:00:00-05');
                $this->purchase($w['tenant'], $customerId, $w['cat']['products']['p1'], $this->day(20), 2, 8.0, 6.0);
            }
        }

        $report = $this->measure($w);

        $this->assertSame(40, $report['measured']);
        $this->assertSame([], $report['experiments']);
        $this->assertSame(0, DB::table('decision_outcomes')->whereIn('classification', ['incremental_estimated', 'causally_attributable'])->count());
        $this->assertSame(20, DB::table('decision_outcomes')->where('classification', 'temporally_associated')->count(), 'compra posterior a una acción: solo asociación temporal');
        $this->assertSame(20, DB::table('decision_outcomes')->where('classification', 'observed')->count());
        $this->assertSame(0, DB::table('decision_outcomes')->where('method', '<>', 'none')->count());
        $o = $this->outcomeOf((int) $recs[0]->id);
        $this->assertNull($o['evidence']['experiment']);
        $this->assertArrayNotHasKey('itt', $o['evidence']);
    }

    public function test_a_treatment_only_experiment_without_controls_makes_no_incremental_claim(): void
    {
        $w = $this->outcomeWorld(40);
        $cohort = $this->cohort($w, 40, 20, 0, 0);

        $report = $this->measure($w);

        $this->assertSame('no_control_group', $report['experiments']['exp-prueba|'.$this->day(40).'|30']['status']);
        $this->assertSame(0, DB::table('decision_outcomes')->whereIn('classification', ['incremental_estimated', 'causally_attributable'])->count());
        $o = $this->outcomeOf((int) $cohort['treatment'][0]->id);
        $this->assertSame('no_control_group', $o['evidence']['experiment']['itt_status']);
        $this->assertSame('no_control_group', $o['evidence']['itt']['status']);
        $this->assertNull($o['evidence']['itt']['conversion']['difference'], 'sin control no hay diferencia ni intervalo');
        $this->assertNull($o['evidence']['itt']['incremental']);
    }

    public function test_a_sample_below_the_minimum_per_group_is_insufficient_and_makes_no_claim(): void
    {
        $w = $this->outcomeWorld(40);
        $cohort = $this->cohort($w, 29, 20, 40, 2);   // 29 < 30 por grupo (mínimo provisional «a aprobar en el piloto»)

        $report = $this->measure($w);

        $this->assertSame('insufficient_sample', $report['experiments']['exp-prueba|'.$this->day(40).'|30']['status']);
        $o = $this->outcomeOf((int) $cohort['treatment'][0]->id);
        $this->assertNotSame('incremental_estimated', $o['classification']);
        $this->assertEquals(['treatment' => 29, 'control' => 40, 'treatment_converted' => 20, 'control_converted' => 2], $o['evidence']['itt']['sample'], 'los tamaños se informan aunque no haya estimación');
        $this->assertNull($o['evidence']['itt']['conversion']['difference']);
        $this->assertSame(30, $o['evidence']['itt']['min_group_size']);
        $this->assertSame(0, DB::table('decision_outcomes')->whereIn('classification', ['incremental_estimated', 'causally_attributable'])->count());
    }

    public function test_a_group_not_backed_by_a_registered_assignment_is_excluded_from_the_analysis(): void
    {
        $w = $this->outcomeWorld(40);
        $this->outcomeSettings($w, ['min_group_size' => 2, 'min_buyers_per_arm' => 1]);
        $cohort = $this->cohort($w, 4, 2, 4, 1);
        // Un tratamiento cuyo grupo en la recomendación no coincide con lo registrado, y un control sin asignación registrada.
        DB::table('decision_recommendations')->where('id', $cohort['treatment'][0]->id)->update(['experiment_group' => 'control']);
        DB::table('experiment_assignments')->where('unit_key', 'customer:'.$cohort['control'][0]->customer_id)->delete();

        $this->measure($w);

        $a = $this->outcomeOf((int) $cohort['treatment'][0]->id);
        $this->assertSame('unverified_assignment:assignment_group_mismatch', $a['evidence']['experiment']['itt_status']);
        $this->assertFalse($a['evidence']['experiment']['in_itt']);
        $b = $this->outcomeOf((int) $cohort['control'][0]->id);
        $this->assertSame('unverified_assignment:assignment_not_found', $b['evidence']['experiment']['itt_status']);
        $itt = $this->outcomeOf((int) $cohort['treatment'][1]->id)['evidence']['itt'];
        $this->assertEquals(['treatment' => 3, 'control' => 3, 'treatment_converted' => 1, 'control_converted' => 0], $itt['sample'], 'las dos unidades sin asignación verificable no cuentan');
    }

    public function test_an_assignment_registered_after_the_recommendation_was_generated_is_not_trusted(): void
    {
        $w = $this->outcomeWorld(40);
        $cohort = $this->cohort($w, 1, 0, 0, 0);
        DB::table('experiment_assignments')->where('unit_key', 'customer:'.$cohort['treatment'][0]->customer_id)->update(['assigned_at' => now()->addHour()]);

        $this->measure($w);

        $o = $this->outcomeOf((int) $cohort['treatment'][0]->id);
        $this->assertSame('unverified_assignment:assignment_registered_after_generation', $o['evidence']['experiment']['itt_status']);
        $this->assertNotSame('incremental_estimated', $o['classification']);
    }

    public function test_a_single_control_fraction_is_required_to_pool_units(): void
    {
        $w = $this->outcomeWorld(40);
        $this->outcomeSettings($w, ['min_group_size' => 2, 'min_buyers_per_arm' => 1]);
        $a = $this->cohort($w, 4, 2, 4, 1, 'exp-mixto');
        foreach ([...$a['treatment'], ...$a['control']] as $i => $rec) {
            if ($i % 2 === 0) {
                DB::table('experiment_assignments')->where('unit_key', 'customer:'.$rec->customer_id)->update(['policy' => json_encode(['algorithm' => 'sha256-uniform-v1', 'control_fraction' => 0.3, 'seed' => 's'])]);
            }
        }

        $report = $this->measure($w);

        $this->assertSame('heterogeneous_control_fraction', $report['experiments']['exp-mixto|'.$this->day(40).'|30']['status'], 'mezclar fracciones de control distintas sesga la comparación: no se afirma nada');
        $this->assertSame(0, DB::table('decision_outcomes')->where('classification', 'incremental_estimated')->count());
    }

    // ───────────────────────── clave del experimento ─────────────────────────

    /** El evento de propuesta sin la clave del experimento (el Decision Engine actual ya no la copia al evento): solo quedan las asignaciones. */
    private function forgetExperimentKeyInEvents(): void
    {
        DB::table('decision_events')->whereNull('from_status')->where('to_status', 'suggested')->update(['payload' => json_encode(['action' => 'propose'])]);
    }

    private function setMinimumGroupSize(array $w, int $n): void
    {
        $this->outcomeSettings($w, ['min_group_size' => $n, 'min_buyers_per_arm' => 1]);
    }

    public function test_without_the_key_in_the_proposal_event_it_is_inferred_from_the_single_assignment_of_the_customer(): void
    {
        $w = $this->outcomeWorld(40);
        $this->setMinimumGroupSize($w, 2);
        $cohort = $this->cohort($w, 4, 2, 4, 1, 'exp-inferida');
        $this->forgetExperimentKeyInEvents();

        $report = $this->measure($w);

        $this->assertSame('estimated', $report['experiments']['exp-inferida|'.$this->day(40).'|30']['status']);
        $e = $this->outcomeOf((int) $cohort['treatment'][0]->id)['evidence']['experiment'];
        $this->assertSame('exp-inferida', $e['key']);
        $this->assertSame('single_assignment_of_customer', $e['key_source']);
        $this->assertTrue($e['verified']);
    }

    public function test_when_several_experiments_fit_a_customer_the_key_is_ambiguous_and_nothing_is_claimed(): void
    {
        $w = $this->outcomeWorld(40);
        $this->setMinimumGroupSize($w, 2);
        $cohort = $this->cohort($w, 4, 2, 4, 1, 'exp-a');
        foreach ([...$cohort['treatment'], ...$cohort['control']] as $rec) {
            $a = (array) DB::table('experiment_assignments')->where('unit_key', 'customer:'.$rec->customer_id)->first();
            unset($a['id']);
            DB::table('experiment_assignments')->insert(['experiment_key' => 'exp-b'] + $a);   // el mismo cliente, el mismo grupo, otra clave
        }
        $this->forgetExperimentKeyInEvents();

        $report = $this->measure($w);

        $this->assertSame([], $report['experiments'], 'ninguna unidad entra al análisis');
        $o = $this->outcomeOf((int) $cohort['treatment'][0]->id);
        $this->assertSame('unverified_assignment:experiment_key_ambiguous', $o['evidence']['experiment']['itt_status']);
        $this->assertNotSame('incremental_estimated', $o['classification']);
        $this->assertSame(0, DB::table('decision_outcomes')->whereIn('classification', ['incremental_estimated', 'causally_attributable'])->count());
    }

    public function test_the_key_recorded_in_the_proposal_event_wins_over_inference(): void
    {
        $w = $this->outcomeWorld(40);
        $this->setMinimumGroupSize($w, 2);
        $cohort = $this->cohort($w, 4, 2, 4, 1, 'exp-b');   // el evento de propuesta dice «exp-b»
        foreach ([...$cohort['treatment'], ...$cohort['control']] as $rec) {
            $a = (array) DB::table('experiment_assignments')->where('unit_key', 'customer:'.$rec->customer_id)->first();
            unset($a['id']);
            DB::table('experiment_assignments')->insert(['experiment_key' => 'exp-a'] + $a);
        }

        $report = $this->measure($w);

        $this->assertSame('estimated', $report['experiments']['exp-b|'.$this->day(40).'|30']['status']);
        $this->assertSame('proposal_event', $this->outcomeOf((int) $cohort['treatment'][0]->id)['evidence']['experiment']['key_source']);
    }

    public function test_a_group_without_any_assignment_or_recorded_key_does_not_enter_the_analysis(): void
    {
        $w = $this->outcomeWorld(40);
        $c = $this->newCustomers($w['tenant'], 1);
        $rec = $this->contactFor($w, $c[0]);
        DB::table('decision_recommendations')->where('id', $rec->id)->update(['experiment_group' => 'treatment']);   // grupo sin asignación ni clave registrada

        $this->measure($w);

        $o = $this->outcomeOf((int) $rec->id);
        $this->assertSame('unverified_assignment:experiment_key_not_recorded', $o['evidence']['experiment']['itt_status']);
        $this->assertNotSame('incremental_estimated', $o['classification']);
    }

    public function test_the_units_dropped_per_arm_and_the_sample_ratio_are_reported(): void
    {
        $w = $this->outcomeWorld(60);
        $this->outcomeSettings($w, ['min_group_size' => 4, 'min_buyers_per_arm' => 1]);
        $one = $this->cohort($w, 8, 4, 8, 3, 'exp-descartes', 30, 'p1', ['as_of' => $this->day(45)]);
        // Un cliente de tratamiento repite en una cohorte posterior; un control pierde su asignación registrada; un tratamiento cambia de grupo en la recomendación.
        $repeat = $this->contactFor($w, (int) $one['treatment'][0]->customer_id, 'p1', ['as_of' => $this->day(43)]);
        $this->assignExperiment($w['tenant'], $repeat, 'treatment', 'exp-descartes');
        DB::table('experiment_assignments')->where('unit_key', 'customer:'.$one['control'][0]->customer_id)->delete();
        DB::table('decision_recommendations')->where('id', $one['treatment'][1]->id)->update(['experiment_group' => 'control']);

        $this->measure($w);

        $roi = $this->actingAsTenantUser($w['admin'], $w['tenant'])->getJson('/api/v1/decisions/roi')->assertOk()->json('data');
        $e = collect($roi['experiments'])->firstWhere('experiment_key', 'exp-descartes');
        $this->assertSame(1, $e['dropped_units']['repeat_customer']['treatment'], 'el cliente repetido');
        // Por el grupo que DICE la recomendación: el control sin asignación registrada y el tratamiento cuyo grupo ya no coincide con lo registrado (ahora dice «control»).
        $this->assertSame(['treatment' => 0, 'control' => 2], $e['dropped_units']['unverified_assignment']);
        $this->assertSame(['treatment' => 0, 'control' => 0], $e['dropped_units']['conflicting_groups']);
        $this->assertEquals(['treatment' => 7, 'control' => 7], array_intersect_key($e['sample'], ['treatment' => 1, 'control' => 1]), 'las unidades que sí entraron: 8 − 1 descartada por grupo');
        $this->assertSame('ok', $e['sample_ratio']['status']);
        $this->assertEqualsWithDelta(0.5, $e['sample_ratio']['observed_control_share'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $e['sample_ratio']['expected_control_fraction'], 1e-9);
    }

    public function test_a_sample_ratio_mismatch_is_flagged_in_the_experiment_and_in_the_roi_reasons(): void
    {
        $w = $this->outcomeWorld(40);
        $this->outcomeSettings($w, ['min_group_size' => 5, 'min_buyers_per_arm' => 1]);
        $this->cohort($w, 40, 12, 10, 3, 'exp-srm');   // fracción de control registrada 0,5; observado 10 de 50

        $this->measure($w);

        $roi = $this->actingAsTenantUser($w['admin'], $w['tenant'])->getJson('/api/v1/decisions/roi')->assertOk()->json('data');
        $this->assertSame('mismatch', $roi['experiments'][0]['sample_ratio']['status']);
        $this->assertLessThan(0.001, $roi['experiments'][0]['sample_ratio']['p_value']);
        $this->assertContains('sample_ratio_mismatch', $roi['roi']['reasons'], 'el aviso viaja con el ROI: el estimado debe leerse con cautela');
    }

    public function test_the_resolver_loads_events_and_assignments_in_chunks(): void
    {
        $w = $this->outcomeWorld(40);
        $cohort = $this->cohort($w, 9, 3, 9, 2, 'exp-chunks');
        $recs = $this->withinTenant($w['tenant'], fn () => DecisionRecommendation::query()->whereIn('id', collect([...$cohort['treatment'], ...$cohort['control']])->pluck('id'))->get());
        $tenantId = (int) $w['tenant']->id;

        $small = $this->withinTenant($w['tenant'], fn () => (new ExperimentResolver(chunk: 4))->resolve($recs));
        $default = $this->withinTenant($w['tenant'], fn () => (new ExperimentResolver)->resolve($recs));

        $this->assertCount(18, $small);
        $this->assertSame($default, $small, 'el tamaño del lote no cambia el resultado');
        $this->assertSame(18, count(array_filter($small, fn ($e) => $e['verified'])));
        // El análisis acumulado también se lee por lotes (paginación por clave).
        $this->measure($w);
        $unitsSmall = (new ExperimentResolver(chunk: 5))->countedUnits($tenantId, 'exp-chunks', 30);
        $unitsDefault = (new ExperimentResolver)->countedUnits($tenantId, 'exp-chunks', 30);
        $this->assertCount(18, $unitsSmall);
        $this->assertSame($unitsDefault, $unitsSmall);
    }

    // ───────────────────────── una vez por cliente ─────────────────────────

    public function test_each_customer_enters_the_analysis_once_and_cohorts_of_different_customers_accumulate(): void
    {
        $w = $this->outcomeWorld(60);
        $this->outcomeSettings($w, ['min_group_size' => 4, 'min_buyers_per_arm' => 1]);
        // Cohorte 1 (corte hoy−45) y cohorte 2 (corte hoy−44) con clientes DISTINTOS; la cohorte 3 (hoy−43) repite a los de la 1.
        $one = $this->cohort($w, 5, 2, 5, 1, 'exp-acumulado', 30, 'p1', ['as_of' => $this->day(45)]);
        $two = $this->cohort($w, 5, 3, 5, 0, 'exp-acumulado', 30, 'p1', ['as_of' => $this->day(44)]);
        $repeat = $this->contactFor($w, (int) $one['treatment'][0]->customer_id, 'p1', ['as_of' => $this->day(43)]);
        $this->assignExperiment($w['tenant'], $repeat, 'treatment', 'exp-acumulado');

        $report = $this->measure($w);

        $first = $this->outcomeOf((int) $one['treatment'][0]->id)['evidence']['itt'];
        $this->assertEquals(['treatment' => 5, 'control' => 5, 'treatment_converted' => 2, 'control_converted' => 1], $first['sample']);
        $second = $this->outcomeOf((int) $two['treatment'][0]->id)['evidence']['itt'];
        $this->assertEquals(['treatment' => 10, 'control' => 10, 'treatment_converted' => 5, 'control_converted' => 1], $second['sample'], 'el análisis acumula clientes distintos de cohortes sucesivas');
        $r = $this->outcomeOf((int) $repeat->id);
        $this->assertSame('repeat_customer', $r['evidence']['experiment']['itt_status'], 'el cliente ya entró con su primera cohorte');
        $this->assertFalse($r['evidence']['experiment']['in_itt']);
        $this->assertNotSame('incremental_estimated', $r['classification']);
        $this->assertCount(3, $report['experiments']);
        // Volver a medir (idempotente) no cambia lo contado.
        $this->assertSame(0, $this->measure($w)['measured']);
        $this->assertSame(20, DB::table('decision_outcomes')->whereRaw("evidence->'experiment'->>'itt_counted' = 'true'")->count());
    }

    // ───────────────────────── asignación real del Decision Engine ─────────────────────────

    public function test_the_assignment_made_by_the_decision_engine_is_the_one_analysed(): void
    {
        $w = $this->outcomeWorld(40);
        $this->withinTenant($w['tenant'], fn () => $w['tenant']->forceFill(['settings' => array_merge($w['tenant']->effectiveSettings(), [
            'decision' => ['control_fraction' => 0.5, 'experiment_key' => 'exp-real', 'experiment_seed' => 'semilla-registrada-0001'],
        ])])->save());
        $this->outcomeSettings($w, ['min_group_size' => 5, 'min_buyers_per_arm' => 1]);
        $customers = $this->newCustomers($w['tenant'], 60);
        $recs = [];
        foreach ($customers as $i => $customerId) {
            $recs[] = $this->contactFor($w, $customerId);
            if ($i % 3 === 0) {
                $this->purchase($w['tenant'], $customerId, $w['cat']['products']['p1'], $this->day(20), 2, 8.0, 6.0);
            }
        }

        $report = $this->measure($w);

        $this->assertSame('estimated', $report['experiments']['exp-real|'.$this->day(40).'|30']['status']);
        $assignments = DB::table('experiment_assignments')->where('experiment_key', 'exp-real')->get()->keyBy('unit_key');
        foreach ($recs as $rec) {
            $o = $this->outcomeOf((int) $rec->id);
            $assignment = $assignments['customer:'.$rec->customer_id];
            $this->assertSame($assignment->group, $o['evidence']['experiment']['group'], 'el grupo analizado es el registrado al generar');
            $this->assertTrue($o['evidence']['experiment']['in_itt'], 'la unidad entra al análisis con la asignación registrada');
            $this->assertNotSame('incremental_estimated', $o['classification']);
            $this->assertSame($assignment->group === 'control' ? 'control' : 'not_contacted', $o['action_kind']);
            $policy = json_decode((string) $assignment->policy, true);
            $this->assertSame($assignment->group, ExperimentAssigner::groupFor($policy, 'exp-real', $assignment->unit_key), 'reproducible con la semilla registrada');
        }
        $itt = $this->outcomeOf((int) $recs[0]->id)['evidence']['itt'];
        $this->assertSame(60, $itt['sample']['treatment'] + $itt['sample']['control']);
        $this->assertSame([0.5], $itt['control_fractions']);
        $stored = json_encode($this->outcomeOf((int) $recs[0]->id)['evidence']);
        $this->assertStringNotContainsString('semilla-registrada-0001', $stored, 'la semilla del experimento no se copia a la evidencia');
    }
}
