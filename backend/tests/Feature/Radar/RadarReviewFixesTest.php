<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Contracts\Decision\RecommendationSink;
use App\Enums\TenantRole;
use App\Models\DecisionRecommendation;
use App\Services\Radar\CommercialOpportunityService;
use App\Services\Radar\MaterializationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Radar\Support\FakeRecommendationSink;
use Tests\Feature\Radar\Support\RadarFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-A + W5-B (revisión conjunta): lo que el Radar muestra y registra cuando hay trabajo en curso, duplicados defensivos, cifras de nivel de cliente aparte
 * del pronóstico, bordes de la agenda en la zona horaria de la empresa, proponente real de una materialización manual, pospuestas que no sobreviven a la
 * oportunidad y fallos visibles del comando.
 */
class RadarReviewFixesTest extends TestCase
{
    use RadarFixtures;
    use RefreshDatabase;

    /** Empresa con costo confiable para p1, costo no confiable para p2 y stock de p1 (como en la integración con el motor real). */
    private function company(TenantRole $role = TenantRole::Analyst): array
    {
        $c = $this->radarCompany($role);
        $this->seedCustomerSales($c['tenant'], $c['cat'], 'c1', 'p1', ['2026-08-01', '2026-08-20', '2026-09-10'], 2, 5.0);
        $this->seedCustomerSales($c['tenant'], $c['cat'], 'c1', 'p2', ['2026-08-01', '2026-08-20'], 2, 3.0);
        $this->seedStock($c['tenant'], $c['cat'], 'p1', '2026-09-30', 10.0, 2, 5);

        return $c;
    }

    private function propose(array $c): MaterializationResult
    {
        return $this->withinTenant($c['tenant'], fn () => app(CommercialOpportunityService::class)->propose());
    }

    private function api(array $c): static
    {
        return $this->actingAsTenantUser($c['user'], $c['tenant']);
    }

    private function recId(array $c, string $kind, int $horizon, string $customer, string $product): int
    {
        return (int) DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('kind', $kind)->where('horizon_days', $horizon)
            ->where('customer_id', $c['cat']['customers'][$customer])->where('product_id', $c['cat']['products'][$product])->orderByDesc('id')->value('id');
    }

    /** @return list<int> ids de las oportunidades de la lista de trabajo */
    private function listedIds(array $c, string $query = ''): array
    {
        return collect($this->api($c)->getJson('/api/v1/radar/opportunities?per_page=100'.$query)->assertOk()->json('data'))->pluck('id')->all();
    }

    // ───────────────────────── H2: la predicción nueva no pisa lo que ya se está trabajando ─────────────────────────

    public function test_newer_predictions_refresh_the_opportunities_in_flight_instead_of_replacing_them(): void
    {
        $c = $this->company();
        $this->travelTo('2026-10-01 12:00:00');
        $this->assertSame(12, $this->propose($c)->created);
        $contacted = $this->recId($c, 'contact', 30, 'c1', 'p1');
        $postponed = $this->recId($c, 'contact', 30, 'c1', 'p2');
        $untouched = $this->recId($c, 'contact', 90, 'c1', 'p1');
        $this->api($c)->postJson("/api/v1/radar/opportunities/{$contacted}/feedback", ['state' => 'contactado', 'idempotency_key' => 'h2-contactado-01'])->assertCreated();
        $this->api($c)->postJson("/api/v1/radar/opportunities/{$postponed}/feedback", ['state' => 'pospuesto', 'snoozed_until' => '2026-10-08', 'idempotency_key' => 'h2-pospuesto-01'])->assertCreated();
        $versionBefore = (int) $this->api($c)->getJson("/api/v1/decisions/{$contacted}")->json('data.version');
        $original = DB::table('decision_recommendations')->whereIn('id', [$contacted, $postponed])->orderBy('id')->get(['id', 'as_of', 'source_core_run_id', 'score', 'expected_gross_value', 'status', 'agenda_status'])->all();

        // Al día siguiente llega una predicción más nueva y el Radar vuelve a proponer.
        $this->travelTo('2026-10-02 12:00:00');
        DB::table('core_runs')->where('id', $c['run'])->update(['is_current' => false]);
        $newRun = $this->seedPredictionRun($c['tenant'], $c['cat'], [7, 30, 90], '2026-10-02');
        $this->seedCustomerS05($c['tenant']->id, $newRun, $c['cat']);
        DB::table('customer_predictions')->where('core_run_id', $newRun)->where('customer_id', $c['cat']['customers']['c2'])->where('product_id', $c['cat']['products']['p1'])
            ->update(['window_status' => 'overdue', 'window_p10_days' => 5, 'window_p50_days' => 9, 'window_p90_days' => 14]);
        $second = $this->propose($c);

        $this->assertSame([12, 10, 2, 0], [$second->candidates, $second->created, $second->existing, $second->failed], 'las 2 en curso se refrescan; las otras 10 sí se reemplazan');
        // Lo que se estaba trabajando sigue siendo la misma recomendación, con su seguimiento intacto y sin sucesora.
        $this->assertEquals($original, DB::table('decision_recommendations')->whereIn('id', [$contacted, $postponed])->orderBy('id')->get(['id', 'as_of', 'source_core_run_id', 'score', 'expected_gross_value', 'status', 'agenda_status'])->all());
        foreach ([[$contacted, 'c1', 'p1', 'contactado'], [$postponed, 'c1', 'p2', 'pospuesto']] as [$id, $customer, $product, $state]) {
            $live = DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('kind', 'contact')->where('horizon_days', 30)
                ->where('customer_id', $c['cat']['customers'][$customer])->where('product_id', $c['cat']['products'][$product])->whereIn('status', DecisionRecommendation::LIVE_STATUSES)->get();
            $this->assertCount(1, $live, 'una sola accionable por alcance');
            $this->assertSame($id, (int) $live->first()->id);
            $this->assertSame($state, $live->first()->agenda_status);
        }
        // El refresco queda como comentario de linaje con la fuente nueva y NO cambia la versión del bloqueo optimista.
        $refresh = DB::table('decision_events')->where('recommendation_id', $contacted)->where('event', 'comment')->get()->map(fn ($e) => json_decode($e->payload, true))->firstWhere('kind', 'refresh');
        $this->assertNotNull($refresh);
        $this->assertSame($newRun, $refresh['source']['core_run_id']);
        $this->assertSame('2026-10-02', $refresh['as_of']);
        $this->assertArrayHasKey('score', $refresh['values']);
        $this->assertSame($versionBefore, (int) $this->api($c)->getJson("/api/v1/decisions/{$contacted}")->json('data.version'));
        // La no trabajada se reemplazó: la anterior expiró (reemplazada) y la nueva la sustituye.
        $this->assertSame('expired', DB::table('decision_recommendations')->where('id', $untouched)->value('status'));
        $replacement = DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('kind', 'contact')->where('horizon_days', 90)
            ->where('customer_id', $c['cat']['customers']['c1'])->where('product_id', $c['cat']['products']['p1'])->where('status', 'suggested')->first();
        $this->assertNotNull($replacement);
        $this->assertSame('2026-10-02', substr((string) $replacement->as_of, 0, 10));
        // La lista de trabajo las muestra una vez, con su estado de seguimiento; la pospuesta reaparece en su fecha de reactivación.
        $ids = $this->listedIds($c, '&horizon=30');
        $this->assertSame(array_values(array_unique($ids)), $ids);
        $this->assertContains($contacted, $ids);
        $this->assertContains($postponed, $ids);
        $agenda = fn (string $date) => collect($this->api($c)->getJson("/api/v1/radar/agenda?date={$date}&per_page=100")->json('data'))->pluck('opportunities')->flatten(1)->pluck('id')->all();
        $this->assertNotContains($postponed, $agenda('2026-10-07'));
        $this->assertContains($postponed, $agenda('2026-10-08'));
        $this->assertContains($contacted, $agenda('2026-10-08'));
    }

    // ───────────────────────── H3: nunca dos accionables del mismo alcance en lo que se muestra ─────────────────────────

    public function test_duplicates_of_the_same_scope_are_shown_once_preferring_the_one_in_flight(): void
    {
        $c = $this->radarCompany();
        $this->materialize($c['tenant']);
        $id = $this->recId($c, 'contact', 30, 'c1', 'p1');
        $row = (array) DB::table('decision_recommendations')->where('id', $id)->first();
        unset($row['id']);
        // Un duplicado antiguo (otro corte, otra huella) que ya está en curso: gana sobre la más reciente aunque tenga menor id... y con id mayor sin seguimiento no gana.
        $old = DB::table('decision_recommendations')->insertGetId(array_merge($row, ['as_of' => '2026-09-30', 'idempotency_key' => hash('sha256', 'dup-old'), 'agenda_status' => 'contactado', 'status' => 'suggested']));
        $this->assertGreaterThan($id, $old);

        $this->assertSame(1, $this->countForScope($this->listedIds($c, '&horizon=30'), $c, 'contact', 'c1', 'p1'));
        $this->assertContains($old, $this->listedIds($c, '&horizon=30'), 'prefiere la que está en curso');
        $this->assertNotContains($id, $this->listedIds($c, '&horizon=30'));
        $summary = $this->api($c)->getJson('/api/v1/radar/summary?horizon=30')->json('data');
        $this->assertSame(DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('horizon_days', 30)->whereIn('status', DecisionRecommendation::LIVE_STATUSES)
            ->select('kind', 'customer_id', 'product_id')->distinct()->get()->count(), $summary['by_horizon']['30']['opportunities'], 'el resumen cuenta alcances, no filas');
        $agenda = collect($this->api($c)->getJson('/api/v1/radar/agenda?date=2026-10-01&per_page=100')->json('data'))->pluck('opportunities')->flatten(1)->where('horizon_days', 30)
            ->where('kind', 'contact')->where('customer.id', $c['cat']['customers']['c1'])->where('product.id', $c['cat']['products']['p1']);
        $this->assertCount(1, $agenda);

        // Sin ninguna en curso gana la más reciente.
        DB::table('decision_recommendations')->where('id', $old)->update(['agenda_status' => null]);
        $this->assertContains($old, $this->listedIds($c, '&horizon=30'), 'la más reciente (mayor id)');
        $this->assertNotContains($id, $this->listedIds($c, '&horizon=30'));
    }

    /** @param list<int> $ids */
    private function countForScope(array $ids, array $c, string $kind, string $customer, string $product): int
    {
        return DB::table('decision_recommendations')->whereIn('id', $ids)->where('kind', $kind)->where('customer_id', $c['cat']['customers'][$customer])
            ->where('product_id', $c['cat']['products'][$product])->count();
    }

    // ───────────────────────── H4: la caída observada no se suma al pronóstico ─────────────────────────

    public function test_the_observed_spend_decline_is_reported_apart_with_its_own_basis_and_never_added_to_the_forecast(): void
    {
        $c = $this->radarCompany();
        $this->materializeAll($c);
        DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('entity_type', 'customer')->where('kind', 'recovery')->update(['expected_gross_value' => 500]);
        DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('entity_type', 'customer')->where('kind', 'customer_risk')->update(['expected_gross_value' => 300]);
        $pairs = (float) DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('horizon_days', 30)->where('entity_type', 'customer_product')->sum('expected_gross_value');

        $h30 = $this->api($c)->getJson('/api/v1/radar/summary?horizon=30')->json('data.by_horizon.30');

        $this->assertEqualsWithDelta(800.0, $h30['customer_level']['observed_spend_decline'], 0.01);
        $this->assertSame(2, $h30['customer_level']['observed_spend_decline_opportunities']);
        $this->assertEqualsWithDelta($pairs, $h30['value']['gross_value'], 0.01, 'el pronóstico suma SOLO las relaciones cliente × producto');
        $this->assertNotEqualsWithDelta($pairs + 800.0, $h30['value']['gross_value'], 0.01);
        // Cada fila dice con qué base se valoró.
        $rows = collect($this->api($c)->getJson('/api/v1/radar/opportunities?per_page=100&horizon=30')->json('data'));
        $this->assertSame(['observed_spend_decline'], $rows->where('entity_type', 'customer')->pluck('value.basis_code')->unique()->values()->all());
        $this->assertSame(['forecast_units_x_last_net_price'], $rows->where('entity_type', 'customer_product')->pluck('value.basis_code')->unique()->values()->all());
        $this->assertStringContainsString('no se suma', (string) $rows->firstWhere('entity_type', 'customer')['value']['basis']);
    }

    public function test_the_summary_says_null_not_zero_when_nothing_is_valued(): void
    {
        $c = $this->radarCompany();
        $this->materializeAll($c);
        DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->update(['expected_gross_value' => null, 'expected_contribution' => null]);

        $h30 = $this->api($c)->getJson('/api/v1/radar/summary?horizon=30')->json('data.by_horizon.30');

        $this->assertNull($h30['value']['gross_value'], 'sin ninguna valorada no hay cifra (no un cero)');
        $this->assertSame(0, $h30['value']['gross_value_opportunities']);
        $this->assertNull($h30['value']['contribution']);
        $this->assertNull($h30['customer_level']['observed_spend_decline']);
        $this->assertSame(0, $h30['customer_level']['observed_spend_decline_opportunities']);
    }

    // ───────────────────────── M3: la agenda corta el día en la zona horaria de la empresa ─────────────────────────

    public function test_the_agenda_boundaries_use_the_company_time_zone_not_utc(): void
    {
        $c = $this->radarCompany();
        $this->materializeAll($c);
        $id = $this->recId($c, 'contact', 30, 'c1', 'p1');
        // Vence el 3 de noviembre a las 23:59:59 hora de Guayaquil (= 4 de noviembre 04:59:59 UTC).
        DB::table('decision_recommendations')->where('id', $id)->update(['expires_at' => '2026-11-04 04:59:59+00']);
        $on = fn (string $date) => collect($this->api($c)->getJson("/api/v1/radar/agenda?date={$date}&per_page=100")->json('data'))->pluck('opportunities')->flatten(1)->pluck('id')->all();

        $this->assertContains($id, $on('2026-11-03'), 'el último día local todavía se gestiona');
        $this->assertNotContains($id, $on('2026-11-04'), 'el 4 local ya venció (en UTC aún parecería vigente)');
    }

    // ───────────────────────── posponer más allá del vencimiento ─────────────────────────

    public function test_postponing_beyond_the_expiry_of_the_opportunity_is_refused_without_changing_anything(): void
    {
        $c = $this->radarCompany();
        $this->materialize($c['tenant']);
        $id = $this->recId($c, 'contact', 30, 'c1', 'p1');
        DB::table('decision_recommendations')->where('id', $id)->update(['expires_at' => '2026-10-31 23:59:59-05']);
        $this->travelTo('2026-10-20 12:00:00');
        $events = DB::table('decision_events')->where('recommendation_id', $id)->count();

        $this->api($c)->postJson("/api/v1/radar/opportunities/{$id}/feedback", ['state' => 'pospuesto', 'snoozed_until' => '2026-11-01', 'idempotency_key' => 'snooze-vencida-1'])
            ->assertStatus(422)->assertJsonPath('code', 'snooze_beyond_expiry');
        $this->assertSame($events, DB::table('decision_events')->where('recommendation_id', $id)->count());
        $this->assertNull(DB::table('decision_recommendations')->where('id', $id)->value('agenda_status'));
        // El último día vigente sí se puede.
        $this->api($c)->postJson("/api/v1/radar/opportunities/{$id}/feedback", ['state' => 'pospuesto', 'snoozed_until' => '2026-10-31', 'idempotency_key' => 'snooze-ultimo-dia'])->assertCreated();
    }

    // ───────────────────────── M7: quien materializa manualmente es el proponente ─────────────────────────

    public function test_a_manual_materialization_records_the_user_as_the_proposer_and_the_proposer_cannot_approve_it(): void
    {
        $c = $this->company(TenantRole::Admin);
        $analyst = $this->addUserToTenant($c['tenant'], TenantRole::Analyst);
        $otherAdmin = $this->addUserToTenant($c['tenant'], TenantRole::Admin);
        $this->api($c)->postJson('/api/v1/radar/opportunities/materialize')->assertOk();
        $id = $this->recId($c, 'contact', 30, 'c1', 'p1');
        $rec = DB::table('decision_recommendations')->where('id', $id)->first();
        $this->assertSame(['user', $c['user']->id], [$rec->proposed_by_kind, (int) $rec->proposed_by_user_id]);
        $event = DB::table('decision_events')->where('recommendation_id', $id)->orderBy('id')->first();
        $this->assertSame($c['user']->id, (int) $event->actor_user_id);
        $step = fn ($user, string $to, string $key, array $extra = []) => $this->actingAsTenantUser($user, $c['tenant'])->postJson("/api/v1/decisions/{$id}/transition", [
            'to_status' => $to, 'idempotency_key' => $key, 'expected_version' => (int) $this->actingAsTenantUser($user, $c['tenant'])->getJson("/api/v1/decisions/{$id}")->json('data.version'),
        ] + $extra);
        $step($c['user'], 'assigned', 'm7-assign-0001', ['assignee_user_id' => $analyst->id])->assertOk();
        $step($analyst, 'reviewed', 'm7-review-0001')->assertOk();

        $step($c['user'], 'approved', 'm7-approve-self')->assertStatus(403)->assertJsonPath('code', 'decision_segregation_of_duties');
        $step($otherAdmin, 'approved', 'm7-approve-other')->assertOk();
    }

    public function test_the_scheduled_command_proposes_as_the_system(): void
    {
        $c = $this->company();

        $this->artisan('predictily:radar-materialize', ['--tenant' => $c['tenant']->id])->assertExitCode(0);

        $rec = DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->first();
        $this->assertSame(['system', null], [$rec->proposed_by_kind, $rec->proposed_by_user_id]);
    }

    // ───────────────────────── L10: fallos visibles y propuestas por lotes ─────────────────────────

    public function test_the_command_exits_non_zero_when_the_engine_rejects_proposals(): void
    {
        $c = $this->radarCompany();
        $sink = new FakeRecommendationSink;
        $sink->failKind = 'contact';
        $this->app->instance(RecommendationSink::class, $sink);

        $this->artisan('predictily:radar-materialize', ['--tenant' => $c['tenant']->id])->assertExitCode(1)->expectsOutputToContain('con error');
    }

    public function test_the_service_hands_the_whole_batch_to_an_engine_that_proposes_in_batches(): void
    {
        $c = $this->radarCompany();
        $spy = new class implements RecommendationSink
        {
            public int $singles = 0;

            /** @var list<int> */
            public array $batches = [];

            public function propose(array $attributes): DecisionRecommendation
            {
                $this->singles++;
                throw new \LogicException('No debería proponer una por una.');
            }

            /** @return list<array{recommendation: null, error: string}> */
            public function proposeMany(array $batch): array
            {
                $this->batches[] = count($batch);

                return array_map(fn () => ['recommendation' => null, 'error' => 'prueba'], $batch);
            }
        };
        $this->app->instance(RecommendationSink::class, $spy);

        $result = $this->withinTenant($c['tenant'], fn () => app(CommercialOpportunityService::class)->propose());

        $this->assertSame([12], $spy->batches, 'un solo lote con las 12 candidatas');
        $this->assertSame(0, $spy->singles);
        $this->assertSame(12, $result->failed);
    }

    public function test_the_presenter_loads_the_predictions_by_exact_tuple_in_one_query(): void
    {
        $c = $this->radarCompany();
        $this->materializeAll($c);
        DB::enableQueryLog();
        $this->api($c)->getJson('/api/v1/radar/opportunities?per_page=100')->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $predictions = $queries->filter(fn (string $q) => str_contains($q, 'from "customer_predictions"'));
        $this->assertCount(1, $predictions, 'una consulta por tanda, no una por oportunidad');
        $this->assertStringContainsString('(core_run_id, customer_id, product_id, horizon_days) in (', strtolower((string) $predictions->first()), 'pares exactos, no el producto cartesiano de clientes × productos');
    }

    public function test_the_real_engine_prepares_a_batch_with_a_bounded_number_of_queries(): void
    {
        $c = $this->company();
        DB::enableQueryLog();
        $result = $this->propose($c);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertSame(12, $result->created);
        // Las consultas de apoyo (existencia de clientes y productos, economía unitaria, disponibilidad) son por lote: no crecen con las candidatas.
        $this->assertGreaterThanOrEqual(1, $queries->filter(fn (string $q) => str_contains($q, 'from "customers"'))->count(), 'la consulta de apoyo se hace (la prueba no es vacía)');
        $this->assertLessThanOrEqual(2, $queries->filter(fn (string $q) => str_contains($q, 'from "customers"'))->count());
        $this->assertLessThanOrEqual(2, $queries->filter(fn (string $q) => str_contains($q, 'from "products"'))->count());
        $this->assertLessThanOrEqual(3, $queries->filter(fn (string $q) => str_contains($q, 'from "inventory_snapshots"'))->count());
    }
}
