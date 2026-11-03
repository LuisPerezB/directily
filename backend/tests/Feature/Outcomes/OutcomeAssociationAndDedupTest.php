<?php

declare(strict_types=1);

namespace Tests\Feature\Outcomes;

use App\Contracts\Decision\RecommendationSink;
use App\Services\Outcomes\OutcomeVocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Outcomes\Support\OutcomeFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-C (revisión adversarial): lo «asociado a la acción» es SOLO lo posterior a ella; las devoluciones y notas de crédito posteriores a la ventana pero conocidas al
 * corte se restan; el mismo importe no se cuenta dos veces cuando varias recomendaciones observan la misma venta; una acción registrada tras cerrar la ventana no es una
 * acción atendida; y lo de nivel producto no afirma asociación.
 */
final class OutcomeAssociationAndDedupTest extends TestCase
{
    use OutcomeFixtures;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function roi(array $w, string $query = '', string $who = 'admin'): array
    {
        return $this->actingAsTenantUser($w[$who], $w['tenant'])->getJson('/api/v1/decisions/roi'.($query === '' ? '' : '?'.$query))->assertOk()->json('data');
    }

    // ───────────────────────── H1: asociado = solo lo posterior a la acción ─────────────────────────

    public function test_the_associated_money_is_only_what_was_bought_after_the_action_not_the_whole_window(): void
    {
        $w = $this->outcomeWorld(40);
        $c1 = $w['cat']['customers']['c1'];
        $p1 = $w['cat']['products']['p1'];
        $rec = $this->contactFor($w, $c1);
        $this->purchase($w['tenant'], $c1, $p1, $this->day(35), 100, 8.0, 6.0);   // 800 ANTES de la acción
        $this->executeAt($w, $rec, $this->iso(30));
        $this->purchase($w['tenant'], $c1, $p1, $this->day(20), 1, 8.0, 6.0);     // 8 DESPUÉS

        $this->runMeasure($w);

        $o = $this->outcomeOf((int) $rec->id);
        $this->assertSame('temporally_associated', $o['classification']);
        $this->assertEquals(808.0, (float) $o['observed_net_amount'], 'la ventana completa queda como observación');
        $this->assertEquals(202.0, (float) $o['contribution']);
        $after = $o['evidence']['after_action'];
        $this->assertEquals(1.0, $after['units']);
        $this->assertEquals(8.0, $after['net_amount']);
        $this->assertEquals(2.0, $after['contribution'], '8 de importe − 1 u × 6');
        $this->assertSame('known', $after['contribution_status']);
        $this->assertSame(1, $o['evidence']['observation']['purchases_before_action']);

        $api = collect($this->actingAsTenantUser($w['analyst'], $w['tenant'])->getJson('/api/v1/decisions/outcomes')->json('data'))->firstWhere('recommendation.id', $rec->id);
        $this->assertEquals(8.0, $api['after_action']['net_amount']);
        $this->assertEquals(2.0, $api['after_action']['contribution']);
        $this->assertEquals(808.0, $api['observed_net_amount']);

        $roi = $this->roi($w);
        $this->assertEquals(8.0, $roi['summary']['associated']['net_amount'], 'lo asociado: solo 8, no 808');
        $this->assertEquals(1.0, $roi['summary']['associated']['units']);
        $this->assertEquals(2.0, $roi['summary']['associated']['contribution_known'], 'y 2, no 202');
        $this->assertEquals(808.0, $roi['summary']['observed']['net_amount']);
        $this->assertEquals(202.0, $roi['summary']['observed']['contribution_known']);
    }

    public function test_a_purchase_only_before_the_action_is_observed_and_adds_nothing_to_the_association(): void
    {
        $w = $this->outcomeWorld(40);
        $c1 = $w['cat']['customers']['c1'];
        $rec = $this->contactFor($w, $c1);
        $this->purchase($w['tenant'], $c1, $w['cat']['products']['p1'], $this->day(35), 5, 8.0, 6.0);
        $this->executeAt($w, $rec, $this->iso(30));

        $this->runMeasure($w);

        $o = $this->outcomeOf((int) $rec->id);
        $this->assertSame('observed', $o['classification']);
        $this->assertEquals(0.0, $o['evidence']['after_action']['units']);
        $roi = $this->roi($w);
        $this->assertSame(0, $roi['summary']['associated']['recommendations']);
        $this->assertEquals(0.0, $roi['summary']['associated']['net_amount']);
        $this->assertEquals(40.0, $roi['summary']['observed']['net_amount']);
    }

    public function test_only_returns_issued_after_the_action_reduce_the_after_action_figures(): void
    {
        $w = $this->outcomeWorld(40);
        $c1 = $w['cat']['customers']['c1'];
        $c2 = $w['cat']['customers']['c2'];
        $p1 = $w['cat']['products']['p1'];
        $a = $this->contactFor($w, $c1);
        $b = $this->contactFor($w, $c2);
        $this->executeAt($w, $a, $this->iso(30));
        $this->executeAt($w, $b, $this->iso(30));
        // c1: compra de 5 después de la acción y una devolución de 2 TAMBIÉN después ⇒ 3 netas.
        $this->purchase($w['tenant'], $c1, $p1, $this->day(25), 5, 8.0, 6.0);
        $this->purchase($w['tenant'], $c1, $p1, $this->day(20), 2, 8.0, 6.0, null, null, true);
        // c2: compra de 5 después de la acción y una devolución de 3 ANTES de ella (de una compra anterior a la ventana): no reduce lo posterior.
        $this->purchase($w['tenant'], $c2, $p1, $this->day(35), 3, 8.0, 6.0, null, null, true);
        $this->purchase($w['tenant'], $c2, $p1, $this->day(25), 5, 8.0, 6.0);

        $this->runMeasure($w);

        $oa = $this->outcomeOf((int) $a->id)['evidence']['after_action'];
        $this->assertEquals(3.0, $oa['units']);
        $this->assertEquals(24.0, $oa['net_amount']);
        $this->assertEquals(6.0, $oa['contribution']);
        $ob = $this->outcomeOf((int) $b->id);
        $this->assertEquals(5.0, $ob['evidence']['after_action']['units'], 'la devolución anterior a la acción no reduce lo posterior');
        $this->assertEquals(2.0, (float) $ob['observed_units'], 'pero sí reduce la ventana completa (5 − 3)');
    }

    // ───────────────────────── M2: devoluciones posteriores a la ventana y conocidas al corte ─────────────────────────

    public function test_returns_and_credit_notes_dated_after_the_window_but_known_before_the_cutoff_are_netted_and_reported(): void
    {
        $w = $this->outcomeWorld(40);   // ventana …día −10; corte de conocimiento: día −7
        $c1 = $w['cat']['customers']['c1'];
        $c2 = $w['cat']['customers']['c2'];
        $p1 = $w['cat']['products']['p1'];
        $a = $this->contactFor($w, $c1);
        $b = $this->contactFor($w, $c2);
        $this->purchase($w['tenant'], $c1, $p1, $this->day(20), 5, 8.0, 6.0);
        $this->purchase($w['tenant'], $c1, $p1, $this->day(9), 2, 8.0, 6.0, null, null, false, 'completed', 'credit_note');   // nota de crédito posterior a la ventana (día −9), anterior al corte
        $this->purchase($w['tenant'], $c1, $p1, $this->day(5), 1, 8.0, 6.0, null, null, true);                                // devolución del día −5: DESPUÉS del corte, no se conocía
        $this->purchase($w['tenant'], $c2, $p1, $this->day(20), 2, 8.0, 6.0);
        $this->purchase($w['tenant'], $c2, $p1, $this->day(8), 2, 8.0, 6.0, null, null, true);                                // devolución completa del día −8 (posterior a la ventana, conocida)

        $this->runMeasure($w);

        $oa = $this->outcomeOf((int) $a->id);
        $this->assertEquals(3.0, (float) $oa['observed_units'], '5 − 2 de la nota de crédito posterior a la ventana');
        $this->assertEquals(24.0, (float) $oa['observed_net_amount']);
        $this->assertEquals(6.0, (float) $oa['contribution']);
        $this->assertSame(1, $oa['evidence']['observation']['returns_after_window']['lines']);
        $this->assertEquals(2.0, $oa['evidence']['observation']['returns_after_window']['units'], 'se informa en la evidencia');
        $this->assertEquals(1.0, (float) $oa['evidence']['source']['late_returned_units_not_counted'], 'la posterior al corte se informa aparte, sin restarse');
        $ob = $this->outcomeOf((int) $b->id);
        $this->assertSame('no_purchase', $ob['observed_event'], 'devuelta por completo antes del corte: no hay compra');
        $this->assertEquals(0.0, (float) $ob['observed_units']);
    }

    // ───────────────────────── M3: sin doble conteo entre recomendaciones solapadas ─────────────────────────

    public function test_overlapping_recommendations_do_not_double_count_the_same_sales_in_the_headline_totals(): void
    {
        $w = $this->outcomeWorld(40);
        $c1 = $w['cat']['customers']['c1'];
        $p1 = $w['cat']['products']['p1'];
        // El mismo cliente × producto con tres recomendaciones: contacto a 30 días, contacto a 7 días (ventana anidada) y recuperación a 30 días.
        $this->contactFor($w, $c1);
        $this->contactFor($w, $c1, 'p1', ['horizon_days' => 7]);
        $this->contactFor($w, $c1, 'p1', ['kind' => 'recovery']);
        $this->purchase($w['tenant'], $c1, $p1, $this->day(35), 2, 8.0, 6.0);   // dentro de las dos ventanas (30 y 7 días)
        $this->purchase($w['tenant'], $c1, $p1, $this->day(20), 3, 8.0, 6.0);   // solo dentro de la de 30 días

        $this->runMeasure($w);

        $roi = $this->roi($w);
        $observed = $roi['summary']['observed'];
        $this->assertEquals(5.0, $observed['units'], '5 unidades vendidas una sola vez, no 12');
        $this->assertEquals(40.0, $observed['net_amount']);
        $this->assertEquals(10.0, $observed['contribution_known']);
        $this->assertSame(1, $observed['customers'], 'clientes distintos');
        $this->assertSame(2, $observed['purchase_lines'], 'líneas de venta distintas');
        $this->assertSame(3, $observed['recommendations_with_purchase']);
        $this->assertEquals(12.0, $observed['per_recommendation']['units'], 'la suma por recomendación queda como detalle: 5 + 2 + 5');
        $this->assertEquals(96.0, $observed['per_recommendation']['net_amount']);
        $this->assertStringContainsString('NO es aditiva', $observed['per_recommendation']['note']);
        // Dentro de cada fila del desglose también sin doble conteo (contacto: 30 y 7 días ⇒ 5 unidades; recuperación ⇒ 5).
        $rows = collect($this->roi($w, 'group_by=kind')['breakdown']['rows'])->keyBy('key');
        $this->assertEquals(5.0, $rows['contact']['observed']['units']);
        $this->assertEquals(5.0, $rows['recovery']['observed']['units']);
        $this->assertEquals(7.0, $rows['contact']['observed']['per_recommendation']['units']);
        $this->assertEquals(5.0, $this->roi($w, 'group_by=horizon')['breakdown']['rows'][1]['observed']['units'], 'por horizonte de 30 días: contacto y recuperación comparten las mismas líneas');
    }

    public function test_the_associated_totals_are_deduplicated_too(): void
    {
        $w = $this->outcomeWorld(40);
        $c1 = $w['cat']['customers']['c1'];
        $p1 = $w['cat']['products']['p1'];
        $contact = $this->contactFor($w, $c1);
        $recovery = $this->contactFor($w, $c1, 'p1', ['kind' => 'recovery']);
        $this->executeAt($w, $contact, $this->iso(30));
        $this->executeAt($w, $recovery, $this->iso(30));
        $this->purchase($w['tenant'], $c1, $p1, $this->day(20), 4, 8.0, 6.0);

        $this->runMeasure($w);

        $associated = $this->roi($w)['summary']['associated'];
        $this->assertSame(2, $associated['recommendations']);
        $this->assertEquals(4.0, $associated['units'], 'una sola venta de 4 unidades, observada por dos recomendaciones');
        $this->assertEquals(32.0, $associated['net_amount']);
        $this->assertEquals(8.0, $associated['contribution_known']);
        $this->assertEquals(8.0, $associated['per_recommendation']['contribution_known'] / 2, 'por recomendación 8 + 8 = 16');
    }

    public function test_a_unit_cost_that_is_not_reliable_leaves_the_deduplicated_contribution_unknown_not_zero(): void
    {
        $w = $this->outcomeWorld(40);
        $c1 = $w['cat']['customers']['c1'];
        $this->contactFor($w, $c1, 'p2');
        $this->purchase($w['tenant'], $c1, $w['cat']['products']['p2'], $this->day(20), 2, 8.0, null);

        $this->runMeasure($w);

        $observed = $this->roi($w)['summary']['observed'];
        $this->assertEquals(16.0, $observed['net_amount']);
        $this->assertEquals(0.0, $observed['contribution_known']);
        $this->assertSame(1, $observed['pairs_with_unknown_contribution']);
    }

    // ───────────────────────── M5: el costo del ROI sale de las unidades del ITT, no del filtro de tipo ─────────────────────────

    public function test_a_kind_filter_does_not_change_the_cost_or_the_net_of_the_experiment(): void
    {
        $w = $this->outcomeWorld(40);
        $this->configureDecision($w, ['contact_cost' => 2.5]);
        $this->outcomeSettings($w, ['min_group_size' => 5, 'min_buyers_per_arm' => 1]);
        $customers = $this->newCustomers($w['tenant'], 24);
        $treated = [];
        foreach ($customers as $i => $customerId) {
            $group = $i < 12 ? 'treatment' : 'control';
            $index = $i < 12 ? $i : $i - 12;
            $rec = $this->contactFor($w, $customerId);
            $this->assignExperiment($w['tenant'], $rec, $group);
            if ($index < ($group === 'treatment' ? 6 : 2)) {
                $this->purchase($w['tenant'], $customerId, $w['cat']['products']['p1'], $this->day(20), 2, 8.0, 6.0);
            }
            if ($group === 'treatment') {
                $treated[] = $rec;
            }
        }
        foreach (array_slice($treated, 0, 3) as $rec) {
            $this->executeAt($w, $rec, $this->iso(30));
        }
        $this->runMeasure($w);

        $all = $this->roi($w);
        foreach (['kind=contact', 'kind=recovery', 'kind=replenishment', 'kind=contact,recovery'] as $filter) {
            $filtered = $this->roi($w, $filter);
            $this->assertEquals(7.5, $filtered['experiments'][0]['contact_cost']['total'], $filter);
            $this->assertEquals($all['roi']['net_incremental_contribution']['estimate'], $filtered['roi']['net_incremental_contribution']['estimate'], $filter);
            $this->assertSame($all['experiments'][0]['sample'], $filtered['experiments'][0]['sample'], $filter);
        }
        $this->assertEquals(8.5, $all['roi']['net_incremental_contribution']['estimate']);
        $this->assertSame(0, $this->roi($w, 'kind=replenishment')['summary']['funnel']['identified'], 'el embudo sí respeta el filtro de tipo');
    }

    // ───────────────────────── acción registrada tras cerrar la ventana ─────────────────────────

    public function test_an_action_registered_after_the_window_closed_is_not_attended_nor_does_it_cost_contact(): void
    {
        $w = $this->outcomeWorld(40);   // la ventana cierra el día −10
        $this->configureDecision($w, ['contact_cost' => 2.5]);
        $c = $this->newCustomers($w['tenant'], 2);
        $late = $this->contactFor($w, $c[0]);
        $inWindow = $this->contactFor($w, $c[1]);
        $this->executeAt($w, $late, $this->iso(8));        // registrada el día −8: la ventana ya cerró (día −10)
        $this->executeAt($w, $inWindow, $this->iso(12));   // el día −12: dentro de la ventana
        $this->purchase($w['tenant'], $c[0], $w['cat']['products']['p1'], $this->day(9), 2, 8.0, 6.0);   // no puede ser efecto de esa acción
        $this->purchase($w['tenant'], $c[1], $w['cat']['products']['p1'], $this->day(11), 2, 8.0, 6.0);

        $this->runMeasure($w);

        $l = $this->outcomeOf((int) $late->id);
        $this->assertSame('not_contacted', $l['action_kind']);
        $this->assertNull($l['action_at']);
        $this->assertEquals(0.0, (float) $l['contact_cost'], 'sin acción atendida en la ventana no hay costo de contacto');
        $this->assertNotNull($l['evidence']['action']['action_registered_after_window_at']);
        $this->assertSame('observed', $l['classification']);
        $in = $this->outcomeOf((int) $inWindow->id);
        $this->assertSame('contacted', $in['action_kind']);
        $this->assertEquals(2.5, (float) $in['contact_cost']);
        $roi = $this->roi($w);
        $this->assertSame(1, $roi['summary']['funnel']['attended']);
        $this->assertEquals(2.5, $roi['costs']['contact']['total']);
    }

    // ───────────────────────── nivel producto: observación sin asociación ─────────────────────────

    public function test_product_level_results_never_claim_association_with_a_supplier_purchase(): void
    {
        $w = $this->outcomeWorld(40);
        $p1 = $w['cat']['products']['p1'];
        $rec = $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose([
            'kind' => 'replenishment', 'as_of' => $w['asOf'], 'horizon_days' => 14, 'entity_type' => 'product_branch', 'product_id' => $p1, 'branch_id' => $w['cat']['branch'], 'expected_units' => 80.0, 'action_cost' => 480.0,
        ]));
        DB::table('decision_events')->insert([
            'tenant_id' => $w['tenant']->id, 'recommendation_id' => $rec->id, 'event' => 'transition', 'from_status' => 'in_progress', 'to_status' => 'executed', 'actor_user_id' => $w['analyst']->id,
            'actor_kind' => 'user', 'payload' => '{}', 'idempotency_key' => str_repeat('e', 64), 'created_at' => $this->iso(39),
        ]);
        $this->purchase($w['tenant'], $w['cat']['customers']['c1'], $p1, $this->day(35), 6, 8.0, 6.0);   // ventas posteriores a la «ejecución»

        $this->runMeasure($w);

        $o = $this->outcomeOf((int) $rec->id);
        $this->assertSame('executed', $o['action_kind']);
        $this->assertSame('observed', $o['classification'], 'una compra al proveedor no «causa» las ventas posteriores del producto');
        $this->assertSame('product_level_observation_no_association_claim', $o['evidence']['classification_reason']);
        $this->assertSame(1, $o['evidence']['observation']['purchase_lines_after_action'], 'lo posterior se informa');
        $this->assertStringContainsString('NO se asocia', $o['evidence']['observation']['caveat']);
        $this->assertSame(0, DB::table('decision_outcomes')->where('classification', 'temporally_associated')->count());
    }

    public function test_availability_that_did_not_fall_is_reported_as_no_sell_through_not_as_overstock(): void
    {
        $w = $this->outcomeWorld(40);
        $p2 = $w['cat']['products']['p2'];
        $rec = $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose([
            'kind' => 'inventory_mismatch', 'as_of' => $w['asOf'], 'horizon_days' => 14, 'entity_type' => 'product', 'product_id' => $p2, 'expected_units' => 10.0,
            'availability' => ['status' => 'known', 'available' => 50.0],
        ]));
        DB::table('inventory_snapshots')->insert([
            'tenant_id' => $w['tenant']->id, 'branch_id' => $w['cat']['branch'], 'product_id' => $p2, 'snapshot_date' => $this->day(30), 'on_hand' => 60, 'reserved' => 0, 'in_transit' => 0,
            'created_at' => $this->iso(30), 'updated_at' => $this->iso(30),
        ]);

        $this->runMeasure($w);

        $o = $this->outcomeOf((int) $rec->id);
        $this->assertSame('no_sell_through', $o['observed_event']);
        $this->assertSame(0, DB::table('decision_outcomes')->where('observed_event', 'overstock')->count());
        $this->assertSame(['no_sell_through'], array_values(array_intersect(['no_sell_through', 'overstock'], OutcomeVocabulary::EVENTS)));
    }

    /** @param array<string, mixed> $decision */
    private function configureDecision(array $w, array $decision): void
    {
        $tenant = $w['tenant']->refresh();
        $tenant->forceFill(['settings' => array_merge($tenant->effectiveSettings(), ['decision' => $decision])])->save();
    }
}
