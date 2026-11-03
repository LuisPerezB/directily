<?php

declare(strict_types=1);

namespace Tests\Feature\Outcomes;

use App\Contracts\Decision\RecommendationSink;
use App\Models\DecisionRecommendation;
use App\Services\Decision\Workflow;
use App\Services\Outcomes\OutcomeMeasurementService;
use App\Services\Radar\AgendaFeedbackService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Outcomes\Support\OutcomeFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-C (ADR-V25-14; §12): medición de outcomes. Ventana fija, lectura as-of (un documento tardío no se ve antes de ingerirse), se miden también las
 * rechazadas, caducadas, no contactadas y el control, contribución NULL sin costo confiable, `executed → measured` solo por el workflow y medición idempotente.
 */
final class OutcomeMeasurementTest extends TestCase
{
    use OutcomeFixtures;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function measure(array $w, bool $dry = false, ?int $lag = null): array
    {
        return app(OutcomeMeasurementService::class)->measure($w['tenant'], null, $dry, $lag);
    }

    private function iso(int $daysAgo, string $time = '10:00:00'): string
    {
        return $this->today()->subDays($daysAgo)->toDateString().' '.$time.'-05';
    }

    private function day(int $daysAgo): string
    {
        return $this->today()->subDays($daysAgo)->toDateString();
    }

    // ───────────────────────── ventana y margen de espera ─────────────────────────

    public function test_only_recommendations_whose_window_and_settling_lag_have_passed_are_measured(): void
    {
        $w = $this->outcomeWorld(60);
        $c = $this->newCustomers($w['tenant'], 4);
        // horizonte 30, margen 3: se mide cuando hoy > as_of + 33. as_of = hoy−34 sí; hoy−33 (último día del margen) no; hoy−32 (ventana cerrada, en espera) no; hoy−5 (ventana abierta) no.
        $measurable = $this->contactFor($w, $c[0], 'p1', ['as_of' => $this->day(34)]);
        $lastDayOfLag = $this->contactFor($w, $c[1], 'p1', ['as_of' => $this->day(33)]);
        $settling = $this->contactFor($w, $c[2], 'p1', ['as_of' => $this->day(32)]);
        $open = $this->contactFor($w, $c[3], 'p1', ['as_of' => $this->day(5)]);

        $report = $this->measure($w);

        $this->assertSame(1, $report['measured']);
        $this->assertSame(1, DB::table('decision_outcomes')->count());
        $this->assertSame((int) $measurable->id, (int) DB::table('decision_outcomes')->value('recommendation_id'));
        $this->assertSame(2, $report['pending_settling'], 'ventana cerrada pero en el margen de espera: aún «prevista»');
        $this->assertSame(1, $report['pending_window_open']);
        foreach ([$lastDayOfLag, $settling, $open] as $rec) {
            $this->assertDatabaseMissing('decision_outcomes', ['recommendation_id' => $rec->id]);
        }
        // La ventana queda fija desde el horizonte.
        $o = $this->outcomeOf((int) $measurable->id);
        $this->assertSame($this->day(33), substr((string) $o['window_from'], 0, 10), 'window_from = as_of + 1');
        $this->assertSame($this->day(4), substr((string) $o['window_to'], 0, 10), 'window_to = as_of + horizonte');
        $this->assertSame($this->day(1), $o['evidence']['window']['knowledge_cutoff'], 'corte de conocimiento = window_to + margen');
        $this->assertSame(3, $o['evidence']['window']['settling_lag_days']);
    }

    public function test_the_settling_lag_is_configurable_per_company_and_by_option(): void
    {
        $w = $this->outcomeWorld(60);
        $this->withinTenant($w['tenant'], fn () => $w['tenant']->forceFill(['settings' => array_merge($w['tenant']->effectiveSettings(), ['outcomes' => ['settling_lag_days' => 10]])])->save());
        $c = $this->newCustomers($w['tenant'], 1);
        $rec = $this->contactFor($w, $c[0], 'p1', ['as_of' => $this->day(34)]);   // con 3 días se mediría; con 10 no

        $this->assertSame(0, $this->measure($w)['measured']);
        $this->assertDatabaseMissing('decision_outcomes', ['recommendation_id' => $rec->id]);
        $this->assertSame(1, $this->measure($w, false, 3)['measured'], 'la opción --lag manda sobre la configuración de la empresa');
    }

    // ───────────────────────── lectura as-of ─────────────────────────

    public function test_a_late_document_is_not_visible_before_it_was_ingested_but_one_ingested_within_the_lag_is(): void
    {
        $w = $this->outcomeWorld(40);   // ventana: hoy−39 … hoy−10; corte de conocimiento: hoy−7
        $c1 = $w['cat']['customers']['c1'];
        $p1 = $w['cat']['products']['p1'];
        $rec = $this->contactFor($w, $c1);
        $this->purchase($w['tenant'], $c1, $p1, $this->day(20), 2, 8.0, 6.0);                                   // conocida desde el día de la venta
        $this->purchase($w['tenant'], $c1, $p1, $this->day(15), 2, 8.0, 6.0, $this->iso(8));                    // llegó tarde (hoy−8) pero ANTES del corte (hoy−7): cuenta
        $this->purchase($w['tenant'], $c1, $p1, $this->day(12), 3, 8.0, 6.0, $this->iso(5));                    // llegó DESPUÉS del corte (hoy−5): no cuenta en esta medición

        $this->measure($w);

        $o = $this->outcomeOf((int) $rec->id);
        $this->assertSame('purchase', $o['observed_event']);
        $this->assertEquals(4.0, (float) $o['observed_units'], 'solo las dos compras conocidas al corte');
        $this->assertEquals(32.0, (float) $o['observed_net_amount']);
        $this->assertSame(1, $o['evidence']['source']['late_lines_not_counted'], 'la compra tardía se informa aparte, no se pierde ni se cuenta');
        $this->assertEquals(3.0, (float) $o['evidence']['source']['late_units_not_counted']);
        $this->assertSame($this->day(7), $o['evidence']['source']['knowledge_cutoff']);
        $this->assertSame('as_of_knowledge_cutoff', $o['evidence']['source']['reading']);
        $this->assertSame(64, strlen($o['evidence']['source']['fingerprint']), 'la huella de las ventas leídas queda en la evidencia');
        // Contribución, no ingreso bruto: 32 de importe − 4 u × costo 6 = 8.
        $this->assertEquals(8.0, (float) $o['contribution']);
    }

    public function test_a_voided_document_is_not_a_purchase(): void
    {
        $w = $this->outcomeWorld(40);
        $c1 = $w['cat']['customers']['c1'];
        $c2 = $w['cat']['customers']['c2'];
        $p1 = $w['cat']['products']['p1'];
        $recA = $this->contactFor($w, $c1);
        $recB = $this->contactFor($w, $c2);
        $this->purchase($w['tenant'], $c1, $p1, $this->day(20), 2, 8.0, 6.0);
        $this->purchase($w['tenant'], $c2, $p1, $this->day(20), 2, 8.0, 6.0, null, null, false, 'voided');   // anulada: no es compra

        $this->measure($w);

        $this->assertSame('purchase', $this->outcomeOf((int) $recA->id)['observed_event']);
        $this->assertSame('no_purchase', $this->outcomeOf((int) $recB->id)['observed_event']);
    }

    public function test_a_document_voided_and_a_return_ingested_after_the_cutoff_do_not_rewrite_the_outcome_but_are_reported(): void
    {
        $w = $this->outcomeWorld(40);   // corte de conocimiento: hoy−7
        $c1 = $w['cat']['customers']['c1'];
        $p1 = $w['cat']['products']['p1'];
        $rec = $this->contactFor($w, $c1);
        // Compra del día −20, ANULADA el día −5 (después del corte): la versión 0 (completada) era la conocida al corte.
        $saleId = $this->purchase($w['tenant'], $c1, $p1, $this->day(20), 2, 8.0, 6.0);
        DB::table('sales_document_revisions')->insert([
            'tenant_id' => $w['tenant']->id, 'sale_id' => $saleId, 'revision' => 0, 'document_type' => 'invoice', 'status' => 'completed', 'issued_at' => $this->iso(20),
            'sale_date' => $this->day(20), 'branch_id' => $w['cat']['branch'], 'customer_id' => $c1, 'currency' => 'USD', 'subtotal' => 16, 'discount_total' => 0, 'tax_total' => 0,
            'total' => 16, 'source_hash' => str_repeat('d', 64), 'ingestion_mode' => 'backfill', 'known_from' => null, 'known_until' => $this->iso(5), 'change_kind' => 'status_changed',
            'created_at' => now(),
        ]);
        DB::table('sale_item_revisions')->insert([
            'tenant_id' => $w['tenant']->id, 'sale_id' => $saleId, 'revision' => 0, 'line_number' => 1, 'product_id' => $p1, 'branch_id' => $w['cat']['branch'], 'sale_date' => $this->day(20),
            'quantity' => 2, 'unit_price' => 8, 'discount_amount' => 0, 'net_amount' => 16, 'tax_amount' => 0, 'unit_cost' => 6, 'is_return' => false, 'created_at' => now(),
        ]);
        DB::table('sales')->where('id', $saleId)->update(['status' => 'voided', 'current_revision' => 1, 'ingestion_mode' => 'incremental', 'version_known_from' => $this->iso(5)]);
        // Devolución de 1 unidad que se cargó el día −4 (también después del corte).
        $this->purchase($w['tenant'], $c1, $p1, $this->day(15), 1, 8.0, 6.0, $this->iso(4), null, true);

        $this->measure($w);

        $o = $this->outcomeOf((int) $rec->id);
        $this->assertSame('purchase', $o['observed_event'], 'al corte la compra estaba completada y la devolución no se conocía');
        $this->assertEquals(2.0, (float) $o['observed_units']);
        $this->assertSame(1, $o['evidence']['source']['lines_revised_or_removed_after_cutoff'], 'la anulación posterior se informa, no reescribe el resultado');
        $this->assertEquals(1.0, (float) $o['evidence']['source']['late_returned_units_not_counted'], 'la devolución tardía se informa aparte');
        $this->assertSame(1, $o['evidence']['source']['late_lines_not_counted']);
    }

    // ───────────────────────── clasificaciones ─────────────────────────

    public function test_a_purchase_after_a_registered_action_is_temporally_associated_and_never_incremental(): void
    {
        $w = $this->outcomeWorld(40);
        $c1 = $w['cat']['customers']['c1'];
        $c2 = $w['cat']['customers']['c2'];
        $p1 = $w['cat']['products']['p1'];
        $after = $this->contactFor($w, $c1);
        $before = $this->contactFor($w, $c2);
        // c1: acción registrada el día −30; compra el día −20 (posterior). c2: acción el día −15; compra el día −25 (ANTERIOR a la acción).
        $this->executeAt($w, $after, $this->iso(30));
        $this->executeAt($w, $before, $this->iso(15));
        $this->purchase($w['tenant'], $c1, $p1, $this->day(20), 2, 8.0, 6.0);
        $this->purchase($w['tenant'], $c2, $p1, $this->day(25), 2, 8.0, 6.0);

        $this->measure($w);

        $a = $this->outcomeOf((int) $after->id);
        $this->assertSame('temporally_associated', $a['classification']);
        $this->assertSame('contacted', $a['action_kind']);
        $this->assertSame('none', $a['method'], 'sin control no hay método de atribución');
        $this->assertNotNull($a['observed_ref']);
        $this->assertStringStartsWith('sale:', $a['observed_ref']);
        $this->assertDoesNotMatchRegularExpression('/\d{10}|@/', (string) $a['observed_ref'], 'observed_ref sin datos personales');
        $this->assertSame('purchase_after_registered_action', $a['evidence']['classification_reason']);
        $this->assertSame(1, $a['evidence']['observation']['purchases_after_action']);
        $this->assertStringContainsString('NO se puede decir que la acción la causó', $a['evidence']['classification_note']);

        $b = $this->outcomeOf((int) $before->id);
        $this->assertSame('observed', $b['classification'], 'la compra fue anterior a la acción: no se asocia');
        $this->assertSame('purchase_only_before_registered_action', $b['evidence']['classification_reason']);
        $this->assertSame(1, $b['evidence']['observation']['purchases_before_action']);

        $this->assertSame(0, DB::table('decision_outcomes')->whereIn('classification', ['incremental_estimated', 'causally_attributable'])->count());
    }

    public function test_agenda_follow_up_contacted_registers_the_action_time_for_the_association(): void
    {
        $w = $this->outcomeWorld(40);
        $c1 = $w['cat']['customers']['c1'];
        $p1 = $w['cat']['products']['p1'];
        $rec = $this->contactFor($w, $c1);
        $this->withinTenant($w['tenant'], fn () => app(AgendaFeedbackService::class)->apply((int) $rec->id, $w['analyst'], 'contactado', 'Llamada', 'fb-1'));
        DB::table('decision_events')->where('recommendation_id', $rec->id)->where('event', 'feedback')->update(['created_at' => $this->iso(30)]);
        $this->purchase($w['tenant'], $c1, $p1, $this->day(20), 2, 8.0, 6.0);

        $this->measure($w);

        $o = $this->outcomeOf((int) $rec->id);
        $this->assertSame('contacted', $o['action_kind']);
        $this->assertSame('temporally_associated', $o['classification']);
        $this->assertSame('agenda:contactado', $o['evidence']['action']['source']);
        // El seguimiento no mueve el workflow desde «sugerida» (no hay transición suggested → in_progress): la acción queda registrada igual y la medición la usa.
        $this->assertSame('suggested', $this->statusOf((int) $rec->id));
        $this->assertSame('suggested', $o['evidence']['recommendation']['status_at_measurement']);
    }

    public function test_rejected_expired_not_contacted_and_superseded_recommendations_are_measured_too(): void
    {
        $w = $this->outcomeWorld(40);
        $c = $this->newCustomers($w['tenant'], 4);
        $p1 = $w['cat']['products']['p1'];
        $rejected = $this->contactFor($w, $c[0]);
        $expired = $this->contactFor($w, $c[1]);
        $notContacted = $this->contactFor($w, $c[2]);
        $quiet = $this->contactFor($w, $c[3]);
        $this->stepAs($w, (int) $rejected->id, 'rejected', 'dismiss-1', ['reason' => 'Cliente fuera de ruta']);
        $this->withinTenant($w['tenant'], fn () => app(Workflow::class)->systemTransition((int) $expired->id, 'expired', 'expiry', 'La recomendación venció sin una acción en curso.', ['reason_code' => 'expired']));
        foreach ([$c[0], $c[1], $c[2]] as $customer) {
            $this->purchase($w['tenant'], $customer, $p1, $this->day(20), 2, 8.0, 6.0);   // compraron igual, sin que nadie actuara
        }

        $report = $this->measure($w);

        $this->assertSame(4, $report['measured']);
        foreach ([[$rejected, 'rejected', 'purchase'], [$expired, 'expired', 'purchase'], [$notContacted, 'not_contacted', 'purchase'], [$quiet, 'not_contacted', 'no_purchase']] as [$rec, $kind, $event]) {
            $o = $this->outcomeOf((int) $rec->id);
            $this->assertSame($kind, $o['action_kind']);
            $this->assertSame($event, $o['observed_event']);
            $this->assertSame('observed', $o['classification'], 'sin acción registrada la compra es solo observada');
            $this->assertEquals(0.0, (float) $o['contact_cost'], 'sin contacto no hay costo de contacto');
        }
        $this->assertNotNull($this->outcomeOf((int) $rejected->id)['evidence']['action']['rejected_at'], 'la fecha del rechazo queda en la evidencia (el motivo, en el evento de auditoría)');
        $this->assertNotNull($this->outcomeOf((int) $expired->id)['evidence']['action']['expired_at']);
        $this->assertStringNotContainsString('fuera de ruta', json_encode($this->outcomeOf((int) $rejected->id)['evidence']), 'el texto libre del motivo no se copia a la evidencia');
        $this->assertSame('expired', $this->statusOf((int) $expired->id), 'medir no cambia el estado de lo que no se ejecutó');
        $this->assertSame('rejected', $this->statusOf((int) $rejected->id));
    }

    public function test_a_contacted_recommendation_that_later_expired_or_was_discarded_is_still_contacted(): void
    {
        $w = $this->outcomeWorld(40);
        $c = $this->newCustomers($w['tenant'], 1);
        $rec = $this->contactFor($w, $c[0]);
        $this->withinTenant($w['tenant'], fn () => app(AgendaFeedbackService::class)->apply((int) $rec->id, $w['analyst'], 'contactado', null, 'fb-1'));
        DB::table('decision_events')->where('recommendation_id', $rec->id)->where('event', 'feedback')->update(['created_at' => $this->iso(30)]);
        $this->withinTenant($w['tenant'], fn () => app(AgendaFeedbackService::class)->apply((int) $rec->id, $w['analyst'], 'descartado', 'No compra', 'fb-2'));

        $this->measure($w);

        $o = $this->outcomeOf((int) $rec->id);
        $this->assertSame('contacted', $o['action_kind'], 'la acción registrada gana sobre el estado final');
        $this->assertSame('descartado', $o['evidence']['action']['agenda_status']);
    }

    public function test_a_superseded_recommendation_is_measured_and_marked_as_superseded(): void
    {
        $w = $this->outcomeWorld(60);
        $c1 = $w['cat']['customers']['c1'];
        $old = $this->contactFor($w, $c1, 'p1', ['as_of' => $this->day(45)]);
        $new = $this->contactFor($w, $c1, 'p1', ['as_of' => $this->day(44)]);   // insumos más nuevos: la anterior expira (reemplazada)

        $this->measure($w);

        $o = $this->outcomeOf((int) $old->id);
        $this->assertSame('expired', $o['action_kind']);
        $this->assertTrue($o['evidence']['recommendation']['superseded']);
        $this->assertSame((int) $new->id, $o['evidence']['action']['superseded_by']);
    }

    // ───────────────────────── contribución ─────────────────────────

    public function test_contribution_is_null_with_its_reason_when_the_cost_is_not_reliable_and_zero_when_nothing_was_bought(): void
    {
        $w = $this->outcomeWorld(40);
        $c1 = $w['cat']['customers']['c1'];
        $c2 = $w['cat']['customers']['c2'];
        $p2 = $w['cat']['products']['p2'];   // p2 no tiene costo en ninguna línea
        $unreliable = $this->contactFor($w, $c1, 'p2');
        $none = $this->contactFor($w, $c2, 'p2');
        $this->purchase($w['tenant'], $c1, $p2, $this->day(20), 2, 8.0, null);

        $this->measure($w);

        $o = $this->outcomeOf((int) $unreliable->id);
        $this->assertSame('purchase', $o['observed_event']);
        $this->assertEquals(16.0, (float) $o['observed_net_amount'], 'el importe se informa');
        $this->assertNull($o['contribution'], 'NULL: no se inventa un costo ni se presenta el ingreso como contribución');
        $this->assertSame('unknown', $o['evidence']['contribution']['status']);
        $this->assertContains('no_cost_in_sales_lines', $o['evidence']['contribution']['reasons']);
        $this->assertSame($w['cat']['products']['p2'], $o['evidence']['contribution']['unknown_products'][0]['product_id']);

        $n = $this->outcomeOf((int) $none->id);
        $this->assertSame('no_purchase', $n['observed_event']);
        $this->assertEquals(0.0, (float) $n['contribution'], 'sin compra la contribución es cero, no desconocida');
        $this->assertSame('not_applicable_no_purchase', $n['evidence']['contribution']['status']);
    }

    public function test_returns_are_netted_and_never_create_negative_sales(): void
    {
        $w = $this->outcomeWorld(40);
        $c = $this->newCustomers($w['tenant'], 3);
        $p1 = $w['cat']['products']['p1'];
        $partial = $this->contactFor($w, $c[0]);
        $full = $this->contactFor($w, $c[1]);
        $onlyReturn = $this->contactFor($w, $c[2]);
        $this->purchase($w['tenant'], $c[0], $p1, $this->day(20), 5, 8.0, 6.0);
        $this->purchase($w['tenant'], $c[0], $p1, $this->day(18), 2, 8.0, 6.0, null, null, true);
        $this->purchase($w['tenant'], $c[1], $p1, $this->day(20), 3, 8.0, 6.0);
        $this->purchase($w['tenant'], $c[1], $p1, $this->day(19), 3, 8.0, 6.0, null, null, false, 'completed', 'credit_note');
        $this->purchase($w['tenant'], $c[2], $p1, $this->day(19), 4, 8.0, 6.0, null, null, true);   // devolución de una compra anterior a la ventana

        $this->measure($w);

        $a = $this->outcomeOf((int) $partial->id);
        $this->assertEquals(3.0, (float) $a['observed_units']);
        $this->assertEquals(24.0, (float) $a['observed_net_amount']);
        $this->assertEquals(6.0, (float) $a['contribution'], '24 de importe neto − 3 u × 6');
        $this->assertEquals(2.0, (float) $a['evidence']['observation']['returned_units']);
        $this->assertSame('no_purchase', $this->outcomeOf((int) $full->id)['observed_event'], 'devuelta por completo: no hay compra');
        $o = $this->outcomeOf((int) $onlyReturn->id);
        $this->assertSame('no_purchase', $o['observed_event']);
        $this->assertEquals(0.0, (float) $o['observed_units'], 'las devoluciones nunca generan ventas negativas');
    }

    public function test_the_contact_cost_comes_from_the_recommendation_only_when_an_action_was_registered(): void
    {
        $w = $this->outcomeWorld(40);
        $this->withinTenant($w['tenant'], fn () => $w['tenant']->forceFill(['settings' => array_merge($w['tenant']->effectiveSettings(), ['decision' => ['contact_cost' => 2.5]])])->save());
        $c = $this->newCustomers($w['tenant'], 2);
        $acted = $this->contactFor($w, $c[0]);
        $idle = $this->contactFor($w, $c[1]);
        $this->executeAt($w, $acted, $this->iso(30));

        $this->measure($w);

        $this->assertEquals(2.5, (float) $this->outcomeOf((int) $acted->id)['contact_cost']);
        $this->assertEquals(0.0, (float) $this->outcomeOf((int) $idle->id)['contact_cost']);
    }

    public function test_an_action_without_a_configured_contact_cost_leaves_the_cost_unknown_not_zero(): void
    {
        $w = $this->outcomeWorld(40);
        $c = $this->newCustomers($w['tenant'], 1);
        $acted = $this->contactFor($w, $c[0]);
        $this->executeAt($w, $acted, $this->iso(30));

        $this->measure($w);

        $this->assertNull($this->outcomeOf((int) $acted->id)['contact_cost']);
    }

    // ───────────────────────── workflow ─────────────────────────

    public function test_executed_moves_to_measured_through_the_system_transition_with_its_audit_event(): void
    {
        $w = $this->outcomeWorld(40);
        $c = $this->newCustomers($w['tenant'], 2);
        $executed = $this->contactFor($w, $c[0]);
        $suggested = $this->contactFor($w, $c[1]);
        $this->executeAt($w, $executed, $this->iso(30));

        $report = $this->measure($w);

        $this->assertSame(1, $report['transitioned_to_measured']);
        $this->assertSame('measured', $this->statusOf((int) $executed->id));
        $this->assertSame('suggested', $this->statusOf((int) $suggested->id), 'solo lo ejecutado pasa a medido');
        $event = DB::table('decision_events')->where('recommendation_id', $executed->id)->where('to_status', 'measured')->first();
        $this->assertNotNull($event);
        $this->assertSame('system', $event->actor_kind);
        $this->assertNull($event->actor_user_id);
        $this->assertSame('executed', $event->from_status);
        $payload = json_decode((string) $event->payload, true);
        $this->assertSame((int) DB::table('decision_outcomes')->where('recommendation_id', $executed->id)->value('id'), $payload['outcome_id']);
        $this->assertSame('measure', $payload['action']);
        // Una persona sigue pudiendo cerrar lo medido (closed) por el workflow normal.
        $this->stepAs($w, (int) $executed->id, 'closed', 'close-1');
        $this->assertSame('closed', $this->statusOf((int) $executed->id));
    }

    public function test_an_execution_registered_after_the_measurement_is_closed_as_measured_with_the_late_mark(): void
    {
        $w = $this->outcomeWorld(40);
        $c = $this->newCustomers($w['tenant'], 1);
        $rec = $this->contactFor($w, $c[0]);
        $this->measure($w);   // aún sin acción: no contactada
        $this->assertSame('not_contacted', $this->outcomeOf((int) $rec->id)['action_kind']);
        $this->executeAt($w, $rec, $this->iso(1));   // se registra la ejecución cuando el resultado ya existe

        $report = $this->measure($w);

        $this->assertSame(0, $report['measured']);
        $this->assertSame(1, $report['finalized_late_execution']);
        $this->assertSame('measured', $this->statusOf((int) $rec->id));
        $this->assertSame('not_contacted', $this->outcomeOf((int) $rec->id)['action_kind'], 'el resultado es inmutable: no se reescribe');
        $payload = json_decode((string) DB::table('decision_events')->where('recommendation_id', $rec->id)->where('to_status', 'measured')->value('payload'), true);
        $this->assertTrue($payload['late_execution']);
    }

    // ───────────────────────── idempotencia ─────────────────────────

    public function test_re_measuring_is_idempotent_and_dry_run_writes_nothing(): void
    {
        $w = $this->outcomeWorld(40);
        $c = $this->newCustomers($w['tenant'], 3);
        $recs = array_map(fn (int $id) => $this->contactFor($w, $id), $c);
        $this->executeAt($w, $recs[0], $this->iso(30));
        $this->purchase($w['tenant'], $c[0], $w['cat']['products']['p1'], $this->day(20), 2, 8.0, 6.0);

        $dry = $this->measure($w, true);
        $this->assertSame(3, $dry['measured'], 'en simulación informa lo que mediría');
        $this->assertSame(0, DB::table('decision_outcomes')->count());
        $this->assertSame('executed', $this->statusOf((int) $recs[0]->id), 'y no mueve ningún estado');

        $first = $this->measure($w);
        $this->assertSame(3, $first['measured']);
        $snapshot = DB::table('decision_outcomes')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $events = DB::table('decision_events')->count();

        $second = $this->measure($w);
        $third = $this->measure($w);

        $this->assertSame(0, $second['measured']);
        $this->assertSame(0, $third['measured']);
        $this->assertSame(3, DB::table('decision_outcomes')->count(), 're-medir no duplica');
        $this->assertSame($snapshot, DB::table('decision_outcomes')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'ni modifica lo ya medido');
        $this->assertSame($events, DB::table('decision_events')->count(), 'ni agrega eventos');
    }

    public function test_a_recommendation_already_measured_by_another_process_is_skipped_inside_a_partly_measured_group(): void
    {
        $w = $this->outcomeWorld(40);
        $c = $this->newCustomers($w['tenant'], 3);
        $recs = array_map(fn (int $id) => $this->contactFor($w, $id), $c);
        // Otro proceso ya midió la primera (sin pasar por este servicio): no se vuelve a escribir.
        DB::table('decision_outcomes')->insert([
            'tenant_id' => $w['tenant']->id, 'recommendation_id' => $recs[0]->id, 'action_kind' => 'not_contacted', 'window_from' => $this->day(39), 'window_to' => $this->day(10),
            'observed_event' => 'no_purchase', 'classification' => 'observed', 'method' => 'none', 'evidence' => json_encode(['por' => 'otro proceso']), 'measured_at' => now(),
        ]);

        $report = $this->measure($w);

        $this->assertSame(2, $report['measured']);
        $this->assertSame(2, $report['examined'], 'la ya medida ni siquiera se examina');
        $this->assertSame(0, $report['already_measured_concurrently']);
        $this->assertSame(1, DB::table('decision_outcomes')->where('recommendation_id', $recs[0]->id)->count());
        $this->assertSame('otro proceso', json_decode((string) DB::table('decision_outcomes')->where('recommendation_id', $recs[0]->id)->value('evidence'), true)['por'], 'lo ya medido no se toca');
        $this->assertSame(1, DB::table('decision_outcomes')->where('recommendation_id', $recs[1]->id)->count());
        $this->assertSame(1, DB::table('decision_outcomes')->where('recommendation_id', $recs[2]->id)->count());
    }

    public function test_a_group_that_fails_midway_is_rolled_back_whole_reported_and_measured_on_the_next_run(): void
    {
        $w = $this->outcomeWorld(40);
        $c = $this->newCustomers($w['tenant'], 3);
        $recs = array_map(fn (int $id) => $this->contactFor($w, $id), $c);
        $this->executeAt($w, $recs[1], $this->iso(30));   // la 2.ª pasará a «measured»: falla DESPUÉS de haber escrito el resultado de la 1.ª
        $fail = true;
        DecisionRecommendation::updating(function (DecisionRecommendation $r) use (&$fail): void {
            if ($fail && $r->status === 'measured') {
                throw new \RuntimeException('fallo simulado al pasar a medida');
            }
        });

        $report = $this->measure($w);

        $this->assertCount(1, $report['errors']);
        $this->assertSame(OutcomeMeasurementService::ERROR_GROUP_FAILED, $report['errors'][0]['code'], 'un código estable, no el texto de la excepción');
        $this->assertStringNotContainsString('fallo simulado', json_encode($report['errors']));
        $this->assertSame(0, $report['measured'], 'los contadores solo suman grupos completos');
        $this->assertSame(0, DB::table('decision_outcomes')->count(), 'el grupo se revierte entero: ni el resultado ya escrito de la 1.ª queda');
        $this->assertSame('executed', $this->statusOf((int) $recs[1]->id), 'ni la transición a medida');
        $this->assertSame(0, DB::table('decision_events')->where('to_status', 'measured')->count());

        $fail = false;
        $retry = $this->measure($w);
        $this->assertSame(3, $retry['measured']);
        $this->assertSame([], $retry['errors']);
        $this->assertSame('measured', $this->statusOf((int) $recs[1]->id));
    }

    public function test_a_large_group_is_measured_in_one_transaction_without_exhausting_the_database_lock_table(): void
    {
        // Regresión de la prueba E1 de la revisión: un punto de guardado por fila agotaba la tabla de candados de PostgreSQL con unos miles de filas en una sola transacción.
        $w = $this->outcomeWorld(40);
        $t = (int) $w['tenant']->id;
        $n = 7000;   // más que los ≈ 6 400 candados de transacción por omisión (max_locks_per_transaction × conexiones)
        DB::statement("INSERT INTO customers (tenant_id, external_code, customer_type, is_generic, created_at, updated_at) SELECT {$t}, 'BULK'||g, 'company', false, now(), now() FROM generate_series(1, {$n}) g");
        $asOf = $w['asOf'];
        DB::statement("INSERT INTO decision_recommendations (tenant_id, kind, as_of, horizon_days, entity_type, customer_id, probability, score_formula_version, status, idempotency_key, created_at, updated_at)
                       SELECT {$t}, 'contact', '{$asOf}', 30, 'customer', c.id, 0.5, 'f1', 'suggested', md5(c.id::text)||md5((c.id+1)::text), now(), now()
                       FROM customers c WHERE c.tenant_id = {$t} AND c.external_code LIKE 'BULK%'");

        $report = $this->measure($w);

        $this->assertSame([], $report['errors']);
        $this->assertSame($n, $report['measured']);
        $this->assertSame($n, DB::table('decision_outcomes')->count());
    }

    public function test_the_artisan_command_measures_per_tenant_supports_dry_run_and_validates_the_lag(): void
    {
        $w = $this->outcomeWorld(40);
        $other = $this->outcomeWorld(40);
        $c = $this->newCustomers($w['tenant'], 1);
        $this->contactFor($w, $c[0]);
        $oc = $this->newCustomers($other['tenant'], 1, 'CLI-O');
        $this->contactFor($other, $oc[0]);

        $this->artisan('predictily:outcomes-measure', ['--tenant' => $w['tenant']->id, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('decision_outcomes')->count());
        $this->artisan('predictily:outcomes-measure', ['--tenant' => $w['tenant']->id])->assertSuccessful();
        $this->assertSame(1, DB::table('decision_outcomes')->where('tenant_id', $w['tenant']->id)->count());
        $this->assertSame(0, DB::table('decision_outcomes')->where('tenant_id', $other['tenant']->id)->count(), '--tenant solo mide esa empresa');
        $this->artisan('predictily:outcomes-measure')->assertSuccessful();
        $this->artisan('predictily:outcomes-measure')->assertSuccessful();
        $this->assertSame(2, DB::table('decision_outcomes')->count(), 'sin --tenant mide todas las activas, y repetir no duplica');
        $this->artisan('predictily:outcomes-measure', ['--lag' => '-1'])->assertFailed();
        $this->artisan('predictily:outcomes-measure', ['--lag' => 'abc'])->assertFailed();
    }

    public function test_the_command_is_scheduled_after_the_decisions_are_generated(): void
    {
        $events = collect(app(Schedule::class)->events());
        $generate = $events->first(fn ($e) => str_contains((string) $e->command, 'predictily:decisions-generate'));
        $measure = $events->first(fn ($e) => str_contains((string) $e->command, 'predictily:outcomes-measure'));
        $this->assertNotNull($measure, 'el comando está programado');
        $this->assertTrue($measure->withoutOverlapping);
        $this->assertTrue($measure->onOneServer);
        $time = fn ($e) => (int) explode(' ', $e->expression)[1] * 60 + (int) explode(' ', $e->expression)[0];
        $this->assertGreaterThan($time($generate), $time($measure), 'se ejecuta después de decisions-generate');
    }

    // ───────────────────────── tipos por producto ─────────────────────────

    public function test_product_level_recommendations_are_measured_by_sales_and_stockouts_and_never_incremental(): void
    {
        $w = $this->outcomeWorld(40);
        $p1 = $w['cat']['products']['p1'];
        $p2 = $w['cat']['products']['p2'];
        $c1 = $w['cat']['customers']['c1'];
        $replenish = $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose([
            'kind' => 'replenishment', 'as_of' => $w['asOf'], 'horizon_days' => 14, 'entity_type' => 'product_branch', 'product_id' => $p1, 'branch_id' => $w['cat']['branch'],
            'expected_units' => 80.0, 'action_cost' => 480.0,
        ]));
        $mismatch = $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose([
            'kind' => 'inventory_mismatch', 'as_of' => $w['asOf'], 'horizon_days' => 14, 'entity_type' => 'product', 'product_id' => $p2, 'expected_units' => 10.0,
        ]));
        // Ventas de p1 en la ventana (as_of+1 … as_of+14) y un quiebre de stock en p1.
        $this->purchase($w['tenant'], $c1, $p1, $this->day(35), 6, 8.0, 6.0);
        DB::table('inventory_snapshots')->insert([
            ['tenant_id' => $w['tenant']->id, 'branch_id' => $w['cat']['branch'], 'product_id' => $p1, 'snapshot_date' => $this->day(33), 'on_hand' => 0, 'reserved' => 0, 'in_transit' => 0, 'created_at' => $this->iso(33), 'updated_at' => $this->iso(33)],
            // Una foto de otro día de la ventana que se cargó DESPUÉS del corte (hoy−23): no era conocida al medir, no cuenta (lectura as-of también del inventario).
            ['tenant_id' => $w['tenant']->id, 'branch_id' => $w['cat']['branch'], 'product_id' => $p2, 'snapshot_date' => $this->day(30), 'on_hand' => 0, 'reserved' => 0, 'in_transit' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $report = $this->measure($w);

        $this->assertSame(2, $report['measured']);
        $o = $this->outcomeOf((int) $replenish->id);
        $this->assertSame('stockout', $o['observed_event'], 'se observó un día sin existencias en la ventana');
        $this->assertSame('not_executed', $o['action_kind']);
        $this->assertEquals(6.0, (float) $o['observed_units']);
        $this->assertEquals(12.0, (float) $o['contribution'], '48 de importe − 6 u × 6');
        $this->assertNull($o['contact_cost'], 'la compra al proveedor no es un costo de contacto');
        $this->assertSame(1, $o['evidence']['inventory']['stockout_days']);
        $this->assertSame('observed', $o['classification']);
        $m = $this->outcomeOf((int) $mismatch->id);
        $this->assertSame('product_sales', $m['observed_event'], 'la foto sin existencias cargada después del corte no se conocía: no es un quiebre observado');
        $this->assertSame(0, $m['evidence']['inventory']['snapshot_days'], 'sin fotos conocidas la cobertura de inventario es cero (desconocido, no «sin stock»)');
        $this->assertEquals(0.0, (float) $m['observed_units']);
        $this->assertEquals(0, DB::table('decision_outcomes')->where('classification', 'incremental_estimated')->count());
    }

    // ───────────────────────── seguridad y aislamiento ─────────────────────────

    public function test_one_company_never_reads_the_sales_or_recommendations_of_another(): void
    {
        $a = $this->outcomeWorld(40);
        $b = $this->outcomeWorld(40);
        $ca = $this->newCustomers($a['tenant'], 1, 'CLI-A');
        $cb = $this->newCustomers($b['tenant'], 1, 'CLI-B');
        $recA = $this->contactFor($a, $ca[0]);
        $recB = $this->contactFor($b, $cb[0]);
        // Compras: solo la empresa B compra; las ventas de B no pueden aparecer en el resultado de A aunque compartan fechas y SKU.
        $this->purchase($b['tenant'], $cb[0], $b['cat']['products']['p1'], $this->day(20), 5, 8.0, 6.0);

        $this->measure($a);

        $this->assertSame(1, DB::table('decision_outcomes')->count());
        $this->assertSame((int) $a['tenant']->id, (int) DB::table('decision_outcomes')->value('tenant_id'));
        $this->assertSame('no_purchase', $this->outcomeOf((int) $recA->id)['observed_event']);
        $this->assertDatabaseMissing('decision_outcomes', ['recommendation_id' => $recB->id]);
        $this->measure($b);
        $this->assertSame('purchase', $this->outcomeOf((int) $recB->id)['observed_event']);
        $this->assertSame(2, DB::table('decision_outcomes')->count());
        $this->assertSame(0, DB::table('decision_outcomes as o')->join('decision_recommendations as r', 'r.id', '=', 'o.recommendation_id')->whereColumn('r.tenant_id', '<>', 'o.tenant_id')->count());
    }

    public function test_measuring_never_calls_the_erp_sends_messages_or_creates_recommendations(): void
    {
        Http::fake();
        Mail::fake();
        $w = $this->outcomeWorld(40);
        $c = $this->newCustomers($w['tenant'], 2);
        $recs = array_map(fn (int $id) => $this->contactFor($w, $id), $c);
        $before = DecisionRecommendation::withoutTenantScope()->count();
        $columns = DB::table('decision_recommendations')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->measure($w);

        Http::assertNothingSent();
        Mail::assertNothingSent();
        $this->assertSame($before, DecisionRecommendation::withoutTenantScope()->count());
        $this->assertSame($columns, DB::table('decision_recommendations')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'medir no modifica las recomendaciones que no ejecutó nadie');
        $this->assertCount(2, $recs);
    }
}
