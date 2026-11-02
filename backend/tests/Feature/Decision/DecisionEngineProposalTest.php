<?php

declare(strict_types=1);

namespace Tests\Feature\Decision;

use App\Contracts\Decision\RecommendationSink;
use App\Models\DecisionEvent;
use App\Models\DecisionRecommendation;
use App\Services\Decision\DecisionEngine;
use App\Services\Decision\DecisionSettings;
use App\Services\Decision\ScoreFormula;
use App\Services\Decision\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Decision\Support\DecisionFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-B (ADR-V25-13; §11): el DecisionEngine implementa RecommendationSink. Contrato completo de §11, idempotencia por huella, insumos nuevos que
 * reemplazan a los viejos, valores económicos solo con fuente (contribución únicamente con costo confiable) y etiqueta de atribución honesta.
 */
final class DecisionEngineProposalTest extends TestCase
{
    use DecisionFixtures;
    use RefreshDatabase;

    private function sink(): RecommendationSink
    {
        return app(RecommendationSink::class);
    }

    public function test_the_sink_is_bound_to_the_decision_engine(): void
    {
        $this->assertInstanceOf(DecisionEngine::class, $this->sink());
    }

    public function test_a_proposal_gets_every_field_of_the_section_11_contract(): void
    {
        $w = $this->decisionWorld();

        $rec = $this->withinTenant($w['tenant'], fn () => $this->sink()->propose($this->contactProposal($w, [
            'model_versions' => ['customer' => ['probability' => 'v1']], 'interval' => ['quantity_if_purchase' => ['lo' => 2, 'hi' => 8]],
            'assumptions' => ['supuesto del proponente'], 'explanation' => [['code' => 'purchase_probability', 'value' => 0.65]],
        ])));

        $this->assertSame('contact', $rec->kind);
        $this->assertSame($w['asOf'], $rec->as_of->toDateString());
        $this->assertSame(30, $rec->horizon_days);
        $this->assertSame('customer_product', $rec->entity_type);
        $this->assertSame($w['run'], $rec->source_core_run_id);
        $this->assertSame(['customer' => ['probability' => 'v1']], $rec->model_versions);
        $this->assertEqualsWithDelta(0.65, $rec->probability, 1e-9);
        $this->assertEquals(['quantity_if_purchase' => ['lo' => 2, 'hi' => 8]], $rec->interval);
        $this->assertContains('supuesto del proponente', $rec->assumptions);
        $this->assertSame('unknown', $rec->availability['status'], 'sin foto de inventario vigente la disponibilidad es desconocida, no cero');
        $this->assertNotNull($rec->expected_gross_value);
        $this->assertNotNull($rec->score);
        $this->assertSame(64, strlen($rec->score_formula_version));
        $this->assertNotEmpty($rec->uncertainty);
        $this->assertNotEmpty($rec->explanation);
        $this->assertNotNull($rec->policy);
        $this->assertSame(1, $rec->priority, 'sola en su grupo (tipo, entidad, corte, horizonte) es la primera por posición del score');
        $this->assertSame('suggested', $rec->status);
        $this->assertNotNull($rec->expires_at);
        $this->assertNotEmpty($rec->next_step);
        $this->assertSame(64, strlen($rec->idempotency_key));
        // Etiqueta honesta: sin evidencia de uplift no hay estimación incremental.
        $this->assertSame('potential_unproven', $rec->attribution_label);
        $this->assertNull($rec->incremental_estimate);
        $this->assertNull($rec->incremental_evidence);
        $this->assertSame('system', $rec->proposed_by_kind);
        // El evento inicial queda en decision_events.
        $events = $this->withinTenant($w['tenant'], fn () => DecisionEvent::query()->where('recommendation_id', $rec->id)->get());
        $this->assertCount(1, $events);
        $this->assertSame([null, 'suggested', 'system'], [$events[0]->from_status, $events[0]->to_status, $events[0]->actor_kind]);
    }

    public function test_the_same_inputs_return_the_same_recommendation_without_duplicating_anything(): void
    {
        $w = $this->decisionWorld();

        [$first, $second, $third] = $this->withinTenant($w['tenant'], fn () => [
            $this->sink()->propose($this->contactProposal($w)),
            $this->sink()->propose($this->contactProposal($w)),
            // Campos que NO forman parte de la huella no crean otra recomendación.
            $this->sink()->propose($this->contactProposal($w, ['probability' => 0.7, 'assumptions' => ['otro supuesto']])),
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        // Quien propone (el Radar) distingue lo creado de lo que ya existía con wasRecentlyCreated.
        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertFalse($third->wasRecentlyCreated);
        $this->withinTenant($w['tenant'], function () use ($first): void {
            $this->assertSame(1, DecisionRecommendation::query()->count());
            $this->assertSame(1, DecisionEvent::query()->where('recommendation_id', $first->id)->count());
        });
        // La huella es SHA-256 (kind, as_of, horizonte, entidad, fuente).
        $this->assertSame(
            DecisionEngine::idempotencyKey('contact', $w['asOf'], 30, ['customer_product', $w['cat']['customers']['c1'], $w['cat']['products']['p1'], null], [$w['run'], null]),
            $first->idempotency_key,
        );
    }

    public function test_new_inputs_create_a_new_recommendation_and_expire_the_previous_one_that_was_not_decided(): void
    {
        $w = $this->decisionWorld();
        $newRun = $this->seedPredictionRun($w['tenant'], $w['cat'], [7, 30, 90], $w['asOf'], false);

        $this->withinTenant($w['tenant'], function () use ($w, $newRun): void {
            $old = $this->sink()->propose($this->contactProposal($w));
            $new = $this->sink()->propose($this->contactProposal($w, ['source_core_run_id' => $newRun]));

            $this->assertNotSame($old->id, $new->id);
            $this->assertNotSame($old->idempotency_key, $new->idempotency_key);
            $this->assertSame('suggested', $new->status);
            $old->refresh();
            $this->assertSame('expired', $old->status);
            $event = DecisionEvent::query()->where('recommendation_id', $old->id)->where('to_status', 'expired')->firstOrFail();
            $this->assertSame(['system', 'suggested'], [$event->actor_kind, $event->from_status]);
            $this->assertSame('superseded', $event->payload['reason_code']);
            $this->assertSame($new->id, $event->payload['superseded_by']);
            // Repetir la propuesta vieja ya no la resucita: devuelve la misma (vencida), sin otra fila ni otro evento.
            $again = $this->sink()->propose($this->contactProposal($w));
            $this->assertSame($old->id, $again->id);
            $this->assertSame(2, DecisionRecommendation::query()->count());
            $this->assertSame(1, DecisionEvent::query()->where('recommendation_id', $old->id)->where('to_status', 'expired')->count());
        });
    }

    /** @return array<string, array{0: array<string, mixed>}> estados «en curso»: seguimiento de la agenda o trabajo del workflow */
    public static function inFlightStates(): array
    {
        return [
            'asignada' => [['status' => 'assigned']], 'revisada' => [['status' => 'reviewed']], 'aprobada' => [['status' => 'approved']], 'en ejecución' => [['status' => 'in_progress']],
            'contactada' => [['agenda_status' => 'contactado']], 'interesada' => [['agenda_status' => 'interesado']], 'pospuesta' => [['agenda_status' => 'pospuesto']],
        ];
    }

    /** @param array<string, mixed> $state */
    #[DataProvider('inFlightStates')]
    public function test_while_a_recommendation_is_in_flight_newer_inputs_refresh_it_instead_of_replacing_it(array $state): void
    {
        $w = $this->decisionWorld();
        $newRun = $this->seedPredictionRun($w['tenant'], $w['cat'], [7, 30, 90], $w['asOf'], false);

        $this->withinTenant($w['tenant'], function () use ($w, $newRun, $state): void {
            $old = $this->sink()->propose($this->contactProposal($w));
            DB::table('decision_recommendations')->where('id', $old->id)->update($state);
            $versionBefore = app(Workflow::class)->versionOf($old->id);

            $same = $this->sink()->propose($this->contactProposal($w, ['source_core_run_id' => $newRun]));

            $this->assertSame($old->id, $same->id, 'se devuelve la que está en curso, no una sucesora');
            $this->assertFalse($same->wasRecentlyCreated);
            $this->assertSame(1, DecisionRecommendation::query()->where('kind', 'contact')->count());
            $fresh = $old->refresh();
            $this->assertSame(array_values($state)[0], $fresh->{array_key_first($state)}, 'no se expira ni se cambia');
            $this->assertNotSame('expired', $fresh->status);
            // El refresco deja el linaje: un comentario con la huella de la nueva fuente y los valores refrescados.
            $refresh = DecisionEvent::query()->where('recommendation_id', $old->id)->where('event', 'comment')->firstOrFail();
            $this->assertSame('refresh', $refresh->payload['kind']);
            $this->assertSame($newRun, $refresh->payload['source']['core_run_id']);
            $this->assertSame($fresh->status, $refresh->from_status);
            $this->assertArrayHasKey('score', $refresh->payload['values']);
            $this->assertSame($versionBefore, app(Workflow::class)->versionOf($old->id), 'un comentario de refresco no cambia la versión del bloqueo optimista');
            // Repetir la propuesta no repite el comentario.
            $this->sink()->propose($this->contactProposal($w, ['source_core_run_id' => $newRun]));
            $this->assertSame(1, DecisionEvent::query()->where('recommendation_id', $old->id)->where('event', 'comment')->count());
        });
    }

    public function test_once_the_in_flight_one_is_terminal_or_expired_the_next_run_creates_a_new_one(): void
    {
        $w = $this->decisionWorld();
        $newRun = $this->seedPredictionRun($w['tenant'], $w['cat'], [7, 30, 90], $w['asOf'], false);

        $this->withinTenant($w['tenant'], function () use ($w, $newRun): void {
            $old = $this->sink()->propose($this->contactProposal($w));
            DB::table('decision_recommendations')->where('id', $old->id)->update(['status' => 'executed', 'agenda_status' => 'contactado']);   // ya ejecutada: no está en curso
            $next = $this->sink()->propose($this->contactProposal($w, ['source_core_run_id' => $newRun]));

            $this->assertNotSame($old->id, $next->id);
            $this->assertTrue($next->wasRecentlyCreated);
            $this->assertSame('executed', $old->refresh()->status, 'lo ejecutado no se toca');
        });
        $this->withinTenant($w['tenant'], function () use ($w): void {
            $third = $this->seedPredictionRun($w['tenant'], $w['cat'], [7, 30, 90], $w['asOf'], false);
            $current = DecisionRecommendation::query()->where('source_core_run_id', '<>', $w['run'])->firstOrFail();
            DB::table('decision_recommendations')->where('id', $current->id)->update(['status' => 'expired', 'agenda_status' => 'pospuesto']);   // vencida: tampoco está en curso
            $again = $this->sink()->propose($this->contactProposal($w, ['source_core_run_id' => $third]));
            $this->assertTrue($again->wasRecentlyCreated);
        });
    }

    public function test_contribution_is_derived_from_a_reliable_cost_and_follows_orders_times_per_order_contribution(): void
    {
        $w = $this->decisionWorld();

        $rec = $this->withinTenant($w['tenant'], fn () => $this->sink()->propose($this->contactProposal($w)));   // p1: precio 8, costo conocido 6 en 4 líneas

        $this->assertEqualsWithDelta(4.32 * 8.0, $rec->expected_gross_value, 1e-6);
        $this->assertEqualsWithDelta(4.32 * (8.0 - 6.0), $rec->expected_contribution, 1e-6);
        $this->assertTrue($rec->uncertainty['cost']['reliable']);
        $this->assertSame('sales_lines', $rec->uncertainty['cost']['source']);
        $this->assertSame('contribution', $rec->uncertainty['value']['basis']);
        $this->assertFalse($rec->uncertainty['value']['cost_unreliable']);
        // Sin costo de contacto configurado, el score no resta nada y la incertidumbre lo declara.
        $this->assertNull($rec->action_cost);
        $this->assertSame('not_configured', $rec->uncertainty['action_cost']['status']);
        $this->assertEqualsWithDelta($rec->expected_contribution, $rec->score, 1e-6);
    }

    public function test_without_a_reliable_cost_the_contribution_is_null_and_the_uncertainty_says_why(): void
    {
        $w = $this->decisionWorld();

        $rec = $this->withinTenant($w['tenant'], fn () => $this->sink()->propose($this->contactProposal($w, ['product_id' => $w['cat']['products']['p2']])));

        $this->assertNull($rec->expected_contribution);
        $this->assertNotNull($rec->expected_gross_value);
        $this->assertFalse($rec->uncertainty['cost']['reliable']);
        $this->assertContains('no_cost_in_sales_lines', $rec->uncertainty['cost']['reasons']);
        $this->assertContains('no_cost_in_sales_lines', $rec->uncertainty['expected_contribution_null_because']);
        // El score usa el importe con el peso de ranking y la marca cost_unreliable.
        $this->assertTrue($rec->uncertainty['value']['cost_unreliable']);
        $this->assertSame('gross_weighted_cost_unreliable', $rec->uncertainty['value']['basis']);
        $this->assertEqualsWithDelta($rec->expected_gross_value * 0.2, $rec->score, 1e-6);
    }

    public function test_a_contribution_supplied_by_the_proposer_is_ignored(): void
    {
        $w = $this->decisionWorld();

        $rec = $this->withinTenant($w['tenant'], fn () => $this->sink()->propose($this->contactProposal($w, [
            'product_id' => $w['cat']['products']['p2'], 'expected_contribution' => 999.0, 'incremental_estimate' => 500.0, 'attribution_label' => 'causally_attributable',
        ])));

        $this->assertNull($rec->expected_contribution, 'la contribución siempre la deriva el motor y p2 no tiene costo confiable');
        $this->assertNull($rec->incremental_estimate, 'el incremental solo existe con evidencia');
        $this->assertSame('potential_unproven', $rec->attribution_label);
    }

    /** @return array<string, array{0: list<array{0: float, 1: float|null}>, 1: string}> */
    public static function unreliableCostCases(): array
    {
        return [
            'pocas observaciones' => [[[2, 6.0], [2, 6.0]], 'insufficient_cost_observations'],
            'cobertura baja' => [[[2, 6.0], [2, 6.0], [2, 6.0], [2, null], [2, null], [2, null]], 'cost_coverage_low'],
            'dispersión alta' => [[[2, 3.0], [2, 6.0], [2, 9.0], [2, 12.0]], 'cost_dispersion_high'],
        ];
    }

    /** @param list<array{0: float, 1: float|null}> $lines */
    #[DataProvider('unreliableCostCases')]
    public function test_each_cost_reliability_rule_withholds_the_contribution(array $lines, string $reason): void
    {
        $w = $this->decisionWorld();
        $this->withinTenant($w['tenant'], function () use ($w, $lines, $reason): void {
            // Producto nuevo (solo con estas líneas) para no mezclarlo con las del mundo base.
            $productId = (int) DB::table('products')->insertGetId(['tenant_id' => $w['tenant']->id, 'sku' => 'NEW-1', 'name' => 'Nuevo', 'unit' => 'unidad', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($lines as $i => [$qty, $cost]) {
                $this->saleLine($w['tenant'], $w['cat']['branch'], $productId, CarbonImmutable::parse($w['asOf'])->subDays($i + 1)->toDateString(), $qty, 8.0, $cost);
            }
            $rec = $this->sink()->propose($this->contactProposal($w, ['product_id' => $productId]));

            $this->assertNull($rec->expected_contribution);
            $this->assertContains($reason, $rec->uncertainty['cost']['reasons']);
        });
    }

    public function test_a_cost_line_known_after_the_cutoff_is_not_used(): void
    {
        $w = $this->decisionWorld();
        $this->withinTenant($w['tenant'], function () use ($w): void {
            $productId = (int) DB::table('products')->insertGetId(['tenant_id' => $w['tenant']->id, 'sku' => 'LATE-1', 'name' => 'Tardío', 'unit' => 'unidad', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            // 3 líneas con costo, pero registradas DESPUÉS del corte (documento tardío): para el corte no existían.
            $late = CarbonImmutable::parse($w['asOf'])->addDays(3)->toDateTimeString();
            foreach ([1, 2, 3] as $d) {
                $this->saleLine($w['tenant'], $w['cat']['branch'], $productId, CarbonImmutable::parse($w['asOf'])->subDays($d)->toDateString(), 2, 8.0, 6.0, $late);
            }

            $rec = $this->sink()->propose($this->contactProposal($w, ['product_id' => $productId]));

            $this->assertNull($rec->expected_contribution);
            $this->assertContains('no_sales_history', $rec->uncertainty['cost']['reasons']);
        });
    }

    public function test_purchase_cost_history_is_a_fallback_only_when_known_before_the_cutoff(): void
    {
        $w = $this->decisionWorld();
        $this->withinTenant($w['tenant'], function () use ($w): void {
            $productId = (int) DB::table('products')->insertGetId(['tenant_id' => $w['tenant']->id, 'sku' => 'PUR-1', 'name' => 'Compra', 'unit' => 'unidad', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach ([1, 2, 3] as $i) {
                $this->saleLine($w['tenant'], $w['cat']['branch'], $productId, CarbonImmutable::parse($w['asOf'])->subDays($i)->toDateString(), 2, 8.0, null);
            }
            $knownAt = fn (int $days) => CarbonImmutable::parse($w['asOf'])->addDays($days)->toDateTimeString();
            foreach ([[10, -20], [20, -15], [30, -10]] as [$days, $offset]) {
                DB::table('purchase_cost_history')->insert(['tenant_id' => $w['tenant']->id, 'product_id' => $productId, 'unit_cost' => 5.0, 'currency' => 'USD',
                    'valid_from' => CarbonImmutable::parse($w['asOf'])->addDays($offset)->toDateString(), 'known_at' => $knownAt($offset), 'created_at' => now()]);
            }

            $rec = $this->sink()->propose($this->contactProposal($w, ['product_id' => $productId]));

            $this->assertSame('purchase_cost_history', $rec->uncertainty['cost']['source']);
            $this->assertEqualsWithDelta(4.32 * (8.0 - 5.0), $rec->expected_contribution, 1e-6);

            // known_at NULL = desconocido: no se puede probar que se conocía al corte → no entra.
            DB::table('purchase_cost_history')->where('product_id', $productId)->update(['known_at' => null]);
            app(DecisionEngine::class)->flush();   // la economía unitaria se memoiza por proceso: se descarta para releer los datos modificados
            $other = $this->sink()->propose($this->contactProposal($w, ['product_id' => $productId, 'horizon_days' => 7]));
            $this->assertNull($other->expected_contribution);
            $this->assertContains('no_purchase_cost_history', $other->uncertainty['cost']['reasons']);
        });
    }

    public function test_the_contact_value_is_limited_to_the_available_stock_and_unknown_stock_is_not_zero(): void
    {
        $w = $this->decisionWorld();
        $this->inventory($w['tenant'], $w['cat'], 'p1', $w['asOf'], 3.0, 1.0, 50.0);   // disponible 2 (el tránsito no cuenta)

        $this->withinTenant($w['tenant'], function () use ($w): void {
            $limited = $this->sink()->propose($this->contactProposal($w));
            $this->assertSame('known', $limited->availability['status']);
            $this->assertEquals(2.0, $limited->availability['available']);
            $this->assertEquals(50.0, $limited->availability['in_transit']);
            $this->assertFalse($limited->availability['in_transit_counted_as_available']);
            $this->assertEqualsWithDelta(2 * 8.0, $limited->expected_gross_value, 1e-6, 'el valor se limita a las 2 unidades disponibles');
            $this->assertEqualsWithDelta(2 * 2.0, $limited->expected_contribution, 1e-6);
            $this->assertContains('stock_below_expected_units', collect($limited->constraints)->pluck('code')->all());
            $this->assertTrue($limited->uncertainty['availability']['capped_units']);

            // Sin foto de inventario: disponibilidad desconocida, NO cero; el valor no se limita.
            $unknown = $this->sink()->propose($this->contactProposal($w, ['product_id' => $w['cat']['products']['p2']]));
            $this->assertSame('unknown', $unknown->availability['status']);
            $this->assertEqualsWithDelta(4.32 * 8.0, $unknown->expected_gross_value, 1e-6);
            $this->assertEmpty($unknown->constraints);
        });
    }

    public function test_an_out_of_stock_product_has_no_sellable_value(): void
    {
        $w = $this->decisionWorld();
        $this->inventory($w['tenant'], $w['cat'], 'p1', $w['asOf'], 0.0, 0.0, 30.0);   // sin existencias; hay tránsito, que no cuenta

        $rec = $this->withinTenant($w['tenant'], fn () => $this->sink()->propose($this->contactProposal($w)));

        $this->assertEquals(0.0, $rec->availability['available']);
        $this->assertEquals(0.0, $rec->expected_gross_value);
        $this->assertEquals(0.0, $rec->expected_contribution);
        $this->assertEquals(0.0, $rec->score);
        $this->assertContains('out_of_stock', collect($rec->constraints)->pluck('code')->all());
    }

    public function test_the_score_formula_version_is_the_sha256_of_its_parameters_and_changes_with_them(): void
    {
        $w = $this->decisionWorld();

        $this->withinTenant($w['tenant'], function () use ($w): void {
            $settings = DecisionSettings::forTenant($w['tenant']);
            $formula = ScoreFormula::fromSettings($settings);
            $rec = $this->sink()->propose($this->contactProposal($w));

            $this->assertSame(hash('sha256', ScoreFormula::canonicalJson($formula->parameters())), $formula->version());
            $this->assertSame($formula->version(), $rec->score_formula_version);
            $this->assertSame(0.2, $formula->parameters()['unreliable_cost_weight']);

            // Cambiar un parámetro de la empresa cambia la versión; cada recomendación conserva la suya.
            $tenant = $w['tenant']->refresh();
            $tenant->forceFill(['settings' => array_merge($tenant->effectiveSettings(), ['decision' => ['unreliable_cost_weight' => 0.4]])])->save();
            $changed = ScoreFormula::fromSettings(DecisionSettings::forTenant($tenant->refresh()));
            $this->assertNotSame($formula->version(), $changed->version());
            $this->assertSame($formula->version(), $rec->refresh()->score_formula_version);
        });
    }

    public function test_the_stored_explanation_is_enough_to_verify_the_score(): void
    {
        $w = $this->decisionWorld();

        $rec = $this->withinTenant($w['tenant'], fn () => $this->sink()->propose($this->contactProposal($w)));

        $calc = collect($rec->explanation)->firstWhere('code', 'value_calculation')['value'];
        $this->assertEqualsWithDelta($calc['expected_units'] * ($calc['unit_price'] - $calc['unit_cost']), $calc['expected_contribution'], 1e-6);
        $formula = ScoreFormula::fromSettings(DecisionSettings::forTenant($w['tenant']));
        $evaluation = $formula->evaluate('contact', $rec->expected_contribution, $rec->expected_gross_value, true, $rec->action_cost);
        $this->assertEqualsWithDelta($evaluation['score'], $rec->score, 1e-6);
        $this->assertEqualsWithDelta($calc['score'], $rec->score, 1e-6);
        // Sin PII: ni nombres ni correos en la recomendación persistida.
        $json = json_encode($rec->toArray());
        $this->assertStringNotContainsString('@', (string) $json);
    }

    public function test_a_contact_cost_configured_by_the_company_is_subtracted_from_the_score(): void
    {
        $w = $this->decisionWorld();
        $w['tenant']->forceFill(['settings' => array_merge($w['tenant']->effectiveSettings(), ['decision' => ['contact_cost' => 1.5]])])->save();

        $rec = $this->withinTenant($w['tenant'], fn () => $this->sink()->propose($this->contactProposal($w)));

        $this->assertEquals(1.5, $rec->action_cost);
        $this->assertEqualsWithDelta($rec->expected_contribution - 1.5, $rec->score, 1e-6);
    }

    public function test_invalid_proposals_and_ids_from_another_company_are_rejected(): void
    {
        $w = $this->decisionWorld();
        $other = $this->decisionWorld();

        $this->withinTenant($w['tenant'], function () use ($w, $other): void {
            foreach ([
                'kind' => ['kind' => 'invento'],
                'entity_type' => ['entity_type' => 'planeta'],
                'horizon_days' => ['horizon_days' => 0],
                'customer_id' => ['customer_id' => $other['cat']['customers']['c1']],      // cliente de otra empresa
                'product_id' => ['product_id' => $other['cat']['products']['p1']],         // producto de otra empresa
                'source_core_run_id' => ['source_core_run_id' => $other['run']],           // corrida de otra empresa
                'probability' => ['probability' => 1.5],
                'expected_units' => ['expected_units' => -3],
            ] as $field => $override) {
                try {
                    $this->sink()->propose($this->contactProposal($w, $override));
                    $this->fail("Debió rechazar {$field}.");
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey($field, $e->errors());
                }
            }
            $this->assertSame(0, DecisionRecommendation::query()->count());
        });
        // Un proponente que no es miembro de la empresa tampoco.
        $this->withinTenant($w['tenant'], function () use ($w, $other): void {
            $this->expectException(ValidationException::class);
            $this->sink()->propose($this->contactProposal($w, ['proposed_by_user_id' => $other['owner']->id]));
        });
    }

    public function test_the_recommendations_are_isolated_between_companies(): void
    {
        $a = $this->decisionWorld();
        $b = $this->decisionWorld();

        $this->withinTenant($a['tenant'], fn () => $this->sink()->propose($this->contactProposal($a)));

        $this->assertSame(1, $this->withinTenant($a['tenant'], fn () => DecisionRecommendation::query()->count()));
        $this->assertSame(0, $this->withinTenant($b['tenant'], fn () => DecisionRecommendation::query()->count()));
        $this->assertSame(0, $this->withinTenant($b['tenant'], fn () => DecisionEvent::query()->count()));
    }
}
