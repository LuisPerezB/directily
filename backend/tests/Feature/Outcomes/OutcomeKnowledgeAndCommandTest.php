<?php

declare(strict_types=1);

namespace Tests\Feature\Outcomes;

use App\Contracts\Decision\RecommendationSink;
use App\Models\DecisionEvent;
use App\Models\DecisionRecommendation;
use App\Services\Outcomes\OutcomeMeasurementService;
use App\Services\Radar\AgendaFeedbackService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Outcomes\Support\OutcomeFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-C (revisión adversarial): la zona horaria de la empresa decide qué es «hoy» y qué día se conoció un documento; el inventario se lee as-of con el piso por
 * sucursal; qué estados de agenda cuentan como acción (la PRIMERA registrada); la ejecución tardía en simulación; y el comando (códigos de salida, sin SQL en la salida).
 */
final class OutcomeKnowledgeAndCommandTest extends TestCase
{
    use OutcomeFixtures;
    use RefreshDatabase;

    // ───────────────────────── zona horaria de la empresa (M42, M43) ─────────────────────────

    public function test_today_is_the_local_date_of_the_company_not_utc(): void
    {
        $guayaquil = $this->outcomeWorld(60);                                   // UTC−5
        $auckland = $this->outcomeWorld(60, ['timezone' => 'Pacific/Auckland']);   // UTC+12/+13
        $asOf = $this->day(34);                                                  // ventana: …día −4; corte de conocimiento: día −1 ⇒ se mide cuando el «hoy» local pasa del día −1
        $cutoff = CarbonImmutable::parse($this->day(1));
        $a = $this->contactFor($guayaquil, $this->newCustomers($guayaquil['tenant'], 1)[0], 'p1', ['as_of' => $asOf]);
        $b = $this->contactFor($auckland, $this->newCustomers($auckland['tenant'], 1)[0], 'p1', ['as_of' => $asOf]);

        // 03:00 UTC del día siguiente al corte: en Guayaquil todavía es el día del corte (22:00 local); en Auckland ya es el día siguiente por la tarde.
        $instant = CarbonImmutable::parse($cutoff->addDay()->toDateString().' 03:00:00', 'UTC');
        $this->assertSame(0, $this->runMeasure($guayaquil, now: $instant)['measured'], 'Guayaquil: hoy local = día del corte, aún no se mide');
        $this->assertSame(1, $this->runMeasure($auckland, now: $instant)['measured'], 'Auckland: hoy local = el día siguiente al corte, ya se mide');
        $this->assertDatabaseMissing('decision_outcomes', ['recommendation_id' => $a->id]);
        $this->assertDatabaseHas('decision_outcomes', ['recommendation_id' => $b->id]);

        // 06:00 UTC: en Guayaquil ya es la 01:00 del día siguiente: se mide.
        $this->assertSame(1, $this->runMeasure($guayaquil, now: CarbonImmutable::parse($cutoff->addDay()->toDateString().' 06:00:00', 'UTC'))['measured']);
        $this->assertSame('es-EC', $guayaquil['tenant']->locale);
    }

    public function test_the_day_a_document_became_known_is_the_local_day_of_the_company(): void
    {
        $guayaquil = $this->outcomeWorld(40);                                      // UTC−5
        $tokyo = $this->outcomeWorld(40, ['timezone' => 'Asia/Tokyo']);            // UTC+9
        $cutoffDay = $this->day(7);                                                // corte de conocimiento (as_of día −40 + 30 + 3)
        $knownFrom = $cutoffDay.' 22:00:00+00';                                    // Guayaquil: 17:00 del día del corte (cuenta) · Tokio: 07:00 del día SIGUIENTE (no cuenta)
        $recs = [];
        foreach ([$guayaquil, $tokyo] as $i => $w) {
            $customer = $this->newCustomers($w['tenant'], 1, 'CLI-TZ'.$i)[0];
            $recs[$i] = $this->contactFor($w, $customer);
            $this->purchase($w['tenant'], $customer, $w['cat']['products']['p1'], $this->day(15), 2, 8.0, 6.0, $knownFrom);
        }

        $this->runMeasure($guayaquil);
        $this->runMeasure($tokyo);

        $g = $this->outcomeOf((int) $recs[0]->id);
        $t = $this->outcomeOf((int) $recs[1]->id);
        $this->assertSame('purchase', $g['observed_event'], 'en Guayaquil el documento se conoció el día del corte: cuenta');
        $this->assertSame('no_purchase', $t['observed_event'], 'en Tokio se conoció el día siguiente al corte: es tardío');
        $this->assertSame(1, $t['evidence']['source']['late_lines_not_counted']);
    }

    // ───────────────────────── inventario as-of (M7) ─────────────────────────

    private function productRecommendation(array $w, string $product): int
    {
        return (int) $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose([
            'kind' => 'replenishment', 'as_of' => $w['asOf'], 'horizon_days' => 14, 'entity_type' => 'product', 'product_id' => $w['cat']['products'][$product], 'expected_units' => 20.0,
        ]))->id;
    }

    public function test_a_snapshot_corrected_after_the_cutoff_is_unknown_not_read_with_the_corrected_value(): void
    {
        $w = $this->outcomeWorld(40);   // ventana …día −26; corte de conocimiento: día −23
        $p1 = $w['cat']['products']['p1'];
        $p2 = $w['cat']['products']['p2'];
        $row = fn (int $product, string $loaded, string $updated, float $onHand) => [
            'tenant_id' => $w['tenant']->id, 'branch_id' => $w['cat']['branch'], 'product_id' => $product, 'snapshot_date' => $this->day(30), 'on_hand' => $onHand, 'reserved' => 0, 'in_transit' => 0,
            'created_at' => $loaded, 'updated_at' => $updated,
        ];
        DB::table('inventory_snapshots')->insert([
            $row($p1, $this->iso(30), $this->iso(30), 0),   // cargada a tiempo y sin tocar: quiebre conocido
            // Se cargó a tiempo con 50 y se CORRIGIÓ después del corte a 0 (upsert: created_at no cambia, updated_at sí): el valor original ya no existe ⇒ desconocida.
            $row($p2, $this->iso(30), now()->toDateTimeString(), 0),
        ]);
        $a = $this->productRecommendation($w, 'p1');
        $b = $this->productRecommendation($w, 'p2');

        $this->runMeasure($w);

        $known = $this->outcomeOf($a);
        $this->assertSame('stockout', $known['observed_event']);
        $this->assertSame(1, $known['evidence']['inventory']['snapshot_days']);
        $corrected = $this->outcomeOf($b);
        $this->assertSame('product_sales', $corrected['observed_event'], 'la foto corregida después del corte no se lee con el valor corregido');
        $this->assertSame(0, $corrected['evidence']['inventory']['snapshot_days'], 'desconocida: ni quiebre ni «hay stock»');
        $this->assertStringContainsString('updated_at', $corrected['evidence']['inventory']['knowledge']);
    }

    public function test_a_negative_balance_in_one_branch_does_not_cancel_the_stock_of_another(): void
    {
        $w = $this->outcomeWorld(40);
        $p1 = $w['cat']['products']['p1'];
        $second = (int) DB::table('branches')->insertGetId(['tenant_id' => $w['tenant']->id, 'code' => '002', 'name' => 'Norte', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_snapshots')->insert([
            ['tenant_id' => $w['tenant']->id, 'branch_id' => $w['cat']['branch'], 'product_id' => $p1, 'snapshot_date' => $this->day(30), 'on_hand' => -20, 'reserved' => 0, 'in_transit' => 0, 'created_at' => $this->iso(30), 'updated_at' => $this->iso(30)],
            ['tenant_id' => $w['tenant']->id, 'branch_id' => $second, 'product_id' => $p1, 'snapshot_date' => $this->day(30), 'on_hand' => 10, 'reserved' => 0, 'in_transit' => 0, 'created_at' => $this->iso(30), 'updated_at' => $this->iso(30)],
        ]);
        $rec = $this->productRecommendation($w, 'p1');

        $this->runMeasure($w);

        $o = $this->outcomeOf($rec);
        $this->assertNotSame('stockout', $o['observed_event'], '−20 + 10 = −10 ≤ 0 daría un quiebre falso: con el piso por sucursal hay 10 disponibles');
        $this->assertSame(0, $o['evidence']['inventory']['stockout_days']);
        $this->assertEquals(10.0, $o['evidence']['inventory']['last_available']);
    }

    // ───────────────────────── agenda: qué cuenta como acción (M31, M32) ─────────────────────────

    public function test_only_contacted_interested_and_order_received_count_as_an_action_and_the_first_registration_wins(): void
    {
        $w = $this->outcomeWorld(40);
        $c = $this->newCustomers($w['tenant'], 6);
        $p1 = $w['cat']['products']['p1'];
        $recs = array_map(fn (int $id) => $this->contactFor($w, $id), $c);
        $feedback = function (int $i, string $state, string $key, int $daysAgo, ?string $snooze = null) use ($w, $recs): void {
            $this->withinTenant($w['tenant'], fn () => app(AgendaFeedbackService::class)->apply((int) $recs[$i]->id, $w['analyst'], $state, null, $key, $snooze));
            DB::table('decision_events')->where('recommendation_id', $recs[$i]->id)->where('event', 'feedback')->where('agenda_status', $state)->update(['created_at' => $this->iso($daysAgo)]);
        };
        $feedback(0, 'contactado', 'k0', 30);
        $feedback(1, 'pedido_recibido', 'k1', 30);
        $feedback(2, 'contactado', 'k2a', 30);       // primera: día −30
        $feedback(2, 'interesado', 'k2b', 25);       // última: día −25
        DB::table('decision_recommendations')->where('id', $recs[3]->id)->update(['expires_at' => now()->addDays(40)]);   // posponer exige una fecha anterior al vencimiento
        $feedback(3, 'pospuesto', 'k3', 30, $this->today()->addDays(10)->toDateString());
        $feedback(4, 'descartado', 'k4', 30);
        // c[5]: sin seguimiento. Compras de los seis clientes entre la primera y la última acción de c[2] (día −28).
        foreach ($c as $customer) {
            $this->purchase($w['tenant'], $customer, $p1, $this->day(28), 2, 8.0, 6.0);
        }

        $this->runMeasure($w);

        $kinds = array_map(fn ($rec) => $this->outcomeOf((int) $rec->id)['action_kind'], $recs);
        $this->assertSame(['contacted', 'contacted', 'contacted', 'not_contacted', 'rejected', 'not_contacted'], $kinds);
        $this->assertSame('agenda:contactado', $this->outcomeOf((int) $recs[0]->id)['evidence']['action']['source']);
        $this->assertSame('agenda:pedido_recibido', $this->outcomeOf((int) $recs[1]->id)['evidence']['action']['source']);
        $third = $this->outcomeOf((int) $recs[2]->id);
        $this->assertSame('agenda:contactado', $third['evidence']['action']['source'], 'cuenta la PRIMERA acción registrada, no la última');
        $this->assertSame($this->day(30), substr((string) $third['action_at'], 0, 10), 'action_at = la primera acción registrada');
        $this->assertSame('temporally_associated', $third['classification'], 'la compra del día −28 es posterior a la primera acción (día −30), anterior a la última (día −25)');
        $this->assertSame('pospuesto', $this->outcomeOf((int) $recs[3]->id)['evidence']['action']['agenda_status']);
        $this->assertNull($this->outcomeOf((int) $recs[3]->id)['action_at']);
    }

    // ───────────────────────── ejecución tardía en simulación (M39) ─────────────────────────

    public function test_dry_run_reports_the_late_execution_closure_without_moving_the_recommendation(): void
    {
        $w = $this->outcomeWorld(40);
        $c = $this->newCustomers($w['tenant'], 1);
        $rec = $this->contactFor($w, $c[0]);
        $this->runMeasure($w);
        $this->executeAt($w, $rec, $this->iso(1));   // la ejecución se registra cuando el resultado ya existe
        $events = DB::table('decision_events')->count();

        $dry = $this->runMeasure($w, true);

        $this->assertSame(1, $dry['finalized_late_execution'], 'en simulación informa lo que cerraría');
        $this->assertSame('executed', $this->statusOf((int) $rec->id), 'sin moverla');
        $this->assertSame($events, DB::table('decision_events')->count());
        $this->assertSame(1, $this->runMeasure($w)['finalized_late_execution']);
        $this->assertSame('measured', $this->statusOf((int) $rec->id));
    }

    // ───────────────────────── comando ─────────────────────────

    public function test_a_tenant_option_that_is_not_a_positive_integer_exits_with_code_2_and_a_message(): void
    {
        foreach (['abc', '1x', '-3', '0', '1.5'] as $bad) {
            $code = Artisan::call('predictily:outcomes-measure', ['--tenant' => $bad]);
            $this->assertSame(2, $code, $bad);
            $this->assertStringContainsString('--tenant debe ser el id numérico', Artisan::output());
        }
    }

    public function test_a_failing_group_makes_the_command_exit_non_zero_with_a_stable_code_and_no_raw_error(): void
    {
        $w = $this->outcomeWorld(40);
        $c = $this->newCustomers($w['tenant'], 2);
        $recs = array_map(fn (int $id) => $this->contactFor($w, $id), $c);
        $this->executeAt($w, $recs[1], $this->iso(30));
        $fail = true;
        DecisionRecommendation::updating(function (DecisionRecommendation $r) use (&$fail): void {
            if ($fail && $r->status === 'measured') {
                throw new \RuntimeException('SQLSTATE[23505]: Unique violation: select * from "decision_outcomes" where tenant_id = 1 — clave secreta xyz');
            }
        });

        $code = Artisan::call('predictily:outcomes-measure', ['--tenant' => $w['tenant']->id]);
        $out = Artisan::output();

        $this->assertSame(1, $code, 'la programación debe poder detectar el fallo');
        $this->assertStringContainsString('error='.OutcomeMeasurementService::ERROR_GROUP_FAILED, $out);
        $this->assertStringNotContainsString('SQLSTATE', $out);
        $this->assertStringNotContainsString('select *', $out);
        $this->assertStringNotContainsString('clave secreta', $out);
        $this->assertSame(0, DB::table('decision_outcomes')->count());

        $fail = false;
        $this->assertSame(0, Artisan::call('predictily:outcomes-measure', ['--tenant' => $w['tenant']->id]), 'la siguiente ejecución mide y sale con 0');
        $this->assertSame(2, DB::table('decision_outcomes')->count());
    }

    public function test_the_unique_index_exists_and_a_race_lost_is_treated_as_already_measured_without_aborting_the_group(): void
    {
        $w = $this->outcomeWorld(40);
        $c = $this->newCustomers($w['tenant'], 3);
        $recs = array_map(fn (int $id) => $this->contactFor($w, $id), $c);
        $this->runMeasure($w);
        $row = (array) DB::table('decision_outcomes')->where('recommendation_id', $recs[0]->id)->first();
        unset($row['id']);
        try {
            DB::transaction(fn () => DB::table('decision_outcomes')->insert($row));
            $this->fail('la base debe rechazar un segundo resultado de la misma recomendación');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        // Otro proceso gana la carrera: escribe el resultado de la 2.ª recomendación DESPUÉS de que este la eligió (al cargar sus eventos) y ANTES de que lo escriba. La violación real
        // de la unicidad la absorbe INSERT … ON CONFLICT DO NOTHING: se cuenta como «ya medida» y el grupo sigue.
        $conflict = $row;
        DB::table('decision_outcomes')->whereIn('recommendation_id', [$recs[1]->id, $recs[2]->id])->delete();
        $armed = true;
        DecisionEvent::retrieved(function () use (&$armed, $conflict, $recs): void {
            if ($armed) {
                $armed = false;
                DB::table('decision_outcomes')->insert(['recommendation_id' => $recs[1]->id] + $conflict);
            }
        });
        $report = $this->runMeasure($w);

        $this->assertSame(1, $report['already_measured_concurrently']);
        $this->assertSame(1, $report['measured'], 'la 3.ª sí se mide');
        $this->assertSame([], $report['errors']);
        $this->assertSame(3, DB::table('decision_outcomes')->count());
        $this->assertSame(1, DB::table('decision_outcomes')->where('recommendation_id', $recs[1]->id)->count(), 'la del otro proceso queda como estaba');
    }
}
