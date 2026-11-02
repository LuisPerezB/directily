<?php

declare(strict_types=1);

namespace Tests\Unit\Decision;

use App\Models\Tenant;
use App\Services\Decision\DecisionEngine;
use App\Services\Decision\DecisionSettings;
use App\Services\Decision\ExperimentAssigner;
use App\Services\Decision\ScoreFormula;
use Tests\TestCase;

/** V2.5 · W5-B: la fórmula del score, su versión, las prioridades por posición, la huella de idempotencia y el reparto del experimento (sin base de datos). */
final class ScoreFormulaTest extends TestCase
{
    private function formula(array $decision = []): ScoreFormula
    {
        $tenant = new Tenant(['settings' => ['decision' => $decision]]);
        $tenant->uuid = '11111111-2222-3333-4444-555555555555';

        return ScoreFormula::fromSettings(DecisionSettings::forTenant($tenant));
    }

    public function test_with_a_reliable_cost_the_score_is_the_contribution_minus_the_action_expense(): void
    {
        $r = $this->formula()->evaluate('contact', 20.0, 50.0, true, 1.5);

        $this->assertSame(['score' => 18.5, 'value' => 20.0, 'basis' => 'contribution', 'expense' => 1.5, 'weight' => null, 'cost_unreliable' => false], $r);
    }

    public function test_without_a_reliable_cost_the_gross_amount_is_weighted_and_marked(): void
    {
        $r = $this->formula()->evaluate('contact', null, 50.0, false, null);

        $this->assertSame(10.0, $r['score']);
        $this->assertSame('gross_weighted_cost_unreliable', $r['basis']);
        $this->assertTrue($r['cost_unreliable']);
        $this->assertSame(0.2, $r['weight']);
        // Aunque venga una contribución, si el costo no es confiable NO se usa.
        $this->assertSame(10.0, $this->formula()->evaluate('contact', 99.0, 50.0, false, null)['score']);
    }

    public function test_without_any_value_the_score_is_null_not_zero(): void
    {
        $r = $this->formula()->evaluate('inventory_mismatch', null, null, false, 10.0);

        $this->assertNull($r['score']);
        $this->assertNull($r['value']);
        $this->assertNull($r['basis']);
    }

    public function test_the_purchase_outlay_is_capital_not_an_expense_so_it_is_not_subtracted(): void
    {
        $this->assertSame(160.0, $this->formula()->evaluate('replenishment', 160.0, 640.0, true, 698.7)['score']);
        $this->assertSame(160.0 - 698.7, $this->formula(['contact_cost' => 1])->evaluate('contact', 160.0, 640.0, true, 698.7)['score']);
    }

    public function test_a_negative_contribution_stays_negative(): void
    {
        $this->assertSame(-5.0, $this->formula()->evaluate('contact', -5.0, 10.0, true, null)['score']);
    }

    public function test_the_version_is_the_sha256_of_the_canonical_parameters_and_follows_them(): void
    {
        $base = $this->formula();

        $this->assertSame(hash('sha256', ScoreFormula::canonicalJson($base->parameters())), $base->version());
        $this->assertSame(64, strlen($base->version()));
        $this->assertSame($base->version(), $this->formula()->version(), 'estable para los mismos parámetros');
        $this->assertNotSame($base->version(), $this->formula(['unreliable_cost_weight' => 0.4])->version());
        $this->assertSame($base->version(), $this->formula(['contact_cost' => 3.0])->version(), 'el costo de contacto es un insumo, no un parámetro de la fórmula');
        $this->assertSame('{"a":1,"b":{"x":2,"y":3}}', ScoreFormula::canonicalJson(['b' => ['y' => 3, 'x' => 2], 'a' => 1]), 'las claves se ordenan recursivamente');
    }

    public function test_the_default_formula_version_is_pinned_so_a_change_of_parameters_is_always_deliberate(): void
    {
        // Si este valor cambia, cambió la fórmula o sus parámetros por defecto: actualice también docs/decision/ECONOMIC_PRIORITIZATION.md.
        $this->assertSame('c1aa6d23c576cc4b7533d7b46a74504dd46e89f354ad200d573b5d9755b38bd3', $this->formula()->version());
        $this->assertSame(
            '{"decimals":6,"expense_kinds":["contact","customer_risk","recovery"],"id":"expected-net-value","priority_bands":[0.1,0.3,0.6,0.85],"revision":1,"unreliable_cost_weight":0.2}',
            ScoreFormula::canonicalJson($this->formula()->parameters()),
        );
    }

    public function test_priorities_follow_the_rank_inside_the_batch_and_are_deterministic(): void
    {
        $scores = [];
        foreach (range(1, 10) as $i) {
            $scores["k{$i}"] = (float) (100 - $i);   // k1 es el mejor
        }
        $scores['sin_score'] = null;
        $scores['negativo'] = -3.0;
        $scores['cero'] = 0.0;

        $p = $this->formula()->priorities($scores);

        $this->assertSame(1, $p['k1']);        // 0 % del lote por encima
        $this->assertSame(2, $p['k2']);        // 10 %
        $this->assertSame(2, $p['k3']);        // 20 %
        $this->assertSame(3, $p['k4']);        // 30 %
        $this->assertSame(3, $p['k6']);        // 50 %
        $this->assertSame(4, $p['k7']);        // 60 %
        $this->assertSame(4, $p['k8']);        // 70 %
        $this->assertSame(5, $p['k10']);       // 90 %
        $this->assertSame([5, 5, 5], [$p['sin_score'], $p['negativo'], $p['cero']], 'sin score o sin valor positivo: la más baja');
        $this->assertEquals($p, $this->formula()->priorities(array_reverse($scores, true)), 'no depende del orden de entrada');
        $this->assertSame(['solo' => 1], $this->formula()->priorities(['solo' => 7.0]));
        $this->assertSame([], $this->formula()->priorities([]));
    }

    public function test_the_idempotency_key_is_a_stable_sha256_of_kind_cut_horizon_entity_and_source(): void
    {
        $base = DecisionEngine::idempotencyKey('contact', '2026-10-26', 30, ['customer_product', 1, 2, null], [10, null]);

        $this->assertSame($base, DecisionEngine::idempotencyKey('contact', '2026-10-26', 30, ['customer_product', 1, 2, null], [10, null]));
        $this->assertSame(64, strlen($base));
        foreach ([
            DecisionEngine::idempotencyKey('recovery', '2026-10-26', 30, ['customer_product', 1, 2, null], [10, null]),
            DecisionEngine::idempotencyKey('contact', '2026-10-27', 30, ['customer_product', 1, 2, null], [10, null]),
            DecisionEngine::idempotencyKey('contact', '2026-10-26', 7, ['customer_product', 1, 2, null], [10, null]),
            DecisionEngine::idempotencyKey('contact', '2026-10-26', 30, ['customer_product', 2, 2, null], [10, null]),
            DecisionEngine::idempotencyKey('contact', '2026-10-26', 30, ['customer_product', 1, 2, null], [11, null]),
            DecisionEngine::idempotencyKey('contact', '2026-10-26', 30, ['customer_product', 1, 2, null], [10, 3]),
        ] as $other) {
            $this->assertNotSame($base, $other);
        }
    }

    public function test_the_experiment_split_is_uniform_deterministic_and_recomputable(): void
    {
        $control = 0;
        $n = 5000;
        for ($i = 1; $i <= $n; $i++) {
            $u = ExperimentAssigner::uniform('semilla-de-prueba', 'exp', ExperimentAssigner::unitKey($i));
            $this->assertGreaterThanOrEqual(0.0, $u);
            $this->assertLessThan(1.0, $u);
            $this->assertSame($u, ExperimentAssigner::uniform('semilla-de-prueba', 'exp', ExperimentAssigner::unitKey($i)));
            $control += $u < 0.2 ? 1 : 0;
        }
        $this->assertEqualsWithDelta(0.2, $control / $n, 0.02);
        $policy = ['seed' => 'semilla-de-prueba', 'control_fraction' => 0.2];
        $this->assertSame(
            ExperimentAssigner::uniform('semilla-de-prueba', 'exp', 'customer:7') < 0.2 ? 'control' : 'treatment',
            ExperimentAssigner::groupFor($policy, 'exp', 'customer:7'),
        );
        $this->assertNotSame(ExperimentAssigner::uniform('otra', 'exp', 'customer:7'), ExperimentAssigner::uniform('semilla-de-prueba', 'exp', 'customer:7'));
    }

    public function test_the_default_settings_are_provisional_and_safe(): void
    {
        $tenant = new Tenant(['settings' => []]);
        $tenant->uuid = '11111111-2222-3333-4444-555555555555';
        $s = DecisionSettings::forTenant($tenant);

        $this->assertSame(0.0, $s->controlFraction(), 'sin experimento por defecto');
        $this->assertNull($s->contactCost(), 'no se inventa un costo de contacto');
        $this->assertTrue($s->kindRequiresApproval('replenishment'));
        $this->assertFalse($s->kindRequiresApproval('contact'));
        $this->assertSame([30], $s->horizons());
        $over = DecisionSettings::forTenant(new Tenant(['settings' => ['decision' => ['control_fraction' => 0.9, 'cost_reliability' => ['max_cv' => 0.5], 'clave_rara' => 1]]]));
        $this->assertSame(0.5, $over->controlFraction(), 'tope del 50 %');
        $this->assertSame(['window_days' => 90, 'min_observations' => 3, 'min_coverage' => 0.8, 'max_cv' => 0.5], $over->costReliability(), 'la configuración parcial se completa con los valores por defecto');
        $this->assertArrayNotHasKey('clave_rara', $over->all());
    }
}
