<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Contracts\Decision\RecommendationSink;
use App\Models\DecisionRecommendation;
use App\Services\Radar\CommercialOpportunityService;
use App\Services\Radar\Exceptions\RadarException;
use App\Services\Radar\OpportunityCandidate;
use App\Services\Radar\RadarPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Radar\Support\RadarFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-A: el servicio productor del Revenue Radar convierte las predicciones vigentes del Customer Core (probabilidad, nº de pedidos, cantidad, ventana) en
 * oportunidades por relación cliente × producto y las propone SOLO por RecommendationSink, de forma idempotente. La economía, la disponibilidad, el score y la prioridad
 * son del Decision Engine (ver RadarDecisionEngineIntegrationTest). Datos ficticios: ver RadarFixtures.
 */
class CommercialOpportunityServiceTest extends TestCase
{
    use RadarFixtures;
    use RefreshDatabase;

    /** @return list<OpportunityCandidate> */
    private function build(array $c, ?array $horizons = null): array
    {
        return $this->withinTenant($c['tenant'], fn () => app(CommercialOpportunityService::class)->build(null, $horizons));
    }

    /** @param list<OpportunityCandidate> $candidates */
    private function pick(array $candidates, string $kind, int $horizon, int $customerId, int $productId): OpportunityCandidate
    {
        foreach ($candidates as $x) {
            if ($x->kind === $kind && $x->horizonDays === $horizon && $x->customerId === $customerId && $x->productId === $productId) {
                return $x;
            }
        }
        $this->fail("No hay candidata {$kind} h{$horizon} cliente {$customerId} producto {$productId}");
    }

    public function test_it_builds_contact_and_recovery_per_relation_from_the_current_prediction_run(): void
    {
        $c = $this->radarCompany();

        $candidates = collect($this->build($c));

        $this->assertCount(12, $candidates);
        $this->assertEquals(['contact' => 9, 'recovery' => 3], $candidates->countBy('kind')->all());
        $this->assertEqualsCanonicalizing([7, 30, 90], $candidates->pluck('horizonDays')->unique()->values()->all());
        // El consumidor final (genérico) nunca es un comprador recurrente: no origina oportunidades.
        $this->assertNotContains($c['cat']['customers']['c3'], $candidates->pluck('customerId')->all());
        // Toda oportunidad del Radar es de una relación cliente × producto; el riesgo y la recuperación A NIVEL DE CLIENTE son del Decision Engine.
        $this->assertSame(['customer_product'], $candidates->map(fn (OpportunityCandidate $x) => $x->attributes['entity_type'])->unique()->values()->all());
        $this->assertNotContains('customer_risk', $candidates->pluck('kind')->all());
        $this->assertSame(RadarPolicy::PRODUCED_KINDS, ['contact', 'recovery']);
        // c2 × p1 está atrasada (overdue) con 6 compras → recuperación; el resto con P ≥ 0,20 → contacto.
        foreach ([7, 30, 90] as $h) {
            $this->assertSame('recovery', $this->pick($this->build($c), 'recovery', $h, $c['cat']['customers']['c2'], $c['cat']['products']['p1'])->kind);
            $this->assertSame('contact', $this->pick($this->build($c), 'contact', $h, $c['cat']['customers']['c1'], $c['cat']['products']['p1'])->kind);
        }
    }

    public function test_each_relation_appears_in_only_one_kind_per_horizon(): void
    {
        $c = $this->radarCompany();

        foreach (collect($this->build($c))->groupBy(fn (OpportunityCandidate $x) => "{$x->horizonDays}:{$x->customerId}:{$x->productId}") as $key => $group) {
            $this->assertCount(1, $group, "la relación {$key} se propuso más de una vez en el horizonte");
        }
    }

    public function test_the_policy_thresholds_and_the_cap_are_configurable_and_versioned(): void
    {
        $c = $this->radarCompany();
        $default = $this->withinTenant($c['tenant'], fn () => app(RadarPolicy::class)->version());

        config(['radar.min_probability' => 0.5]);
        $strict = collect($this->build($c));
        // Con P ≥ 0,5: c1 × p1 (0,60–0,70) en los tres horizontes y c2 × p2 (0,50–0,55) en 30 y 90.
        $this->assertSame(5, $strict->where('kind', 'contact')->count());
        $this->assertSame(3, $strict->where('kind', 'recovery')->count(), 'la recuperación no depende de la probabilidad mínima');

        config(['radar.max_per_kind_horizon' => 1]);
        $capped = collect($this->build($c));
        $this->assertSame(3, $capped->where('kind', 'contact')->count(), 'un contacto por horizonte');
        $this->assertNotSame($default, app(RadarPolicy::class)->version(), 'cambiar un parámetro cambia la huella de la política');
        $this->assertMatchesRegularExpression('/^radar-v1:[0-9a-f]{12}$/', app(RadarPolicy::class)->version());
        $this->assertSame(64, strlen(app(RadarPolicy::class)->fingerprint()));
    }

    public function test_an_overdue_relation_needs_history_to_be_a_recovery(): void
    {
        $c = $this->radarCompany();
        // Con una sola compra previa, un atraso no es información: la relación deja de ser recuperación (y solo es contacto si su probabilidad alcanza el mínimo).
        DB::table('customer_predictions')->where('core_run_id', $c['run'])->where('customer_id', $c['cat']['customers']['c2'])->update(['n_purchases' => 1]);

        $candidates = collect($this->build($c));

        $this->assertSame(0, $candidates->where('kind', 'recovery')->count());
        $p1 = $candidates->where('customerId', $c['cat']['customers']['c2'])->where('productId', $c['cat']['products']['p1']);
        $this->assertSame([90], $p1->pluck('horizonDays')->all(), 'solo en 90 días alcanza P = 0,20');
        $this->assertSame('contact', $p1->first()->kind);
    }

    public function test_the_payload_carries_the_prediction_facts_and_nothing_the_decision_engine_owns(): void
    {
        $c = $this->radarCompany();
        $x = $this->pick($this->build($c), 'contact', 30, $c['cat']['customers']['c1'], $c['cat']['products']['p1']);
        $a = $x->toSinkAttributes();

        $this->assertEmpty(array_diff(array_keys($a), CommercialOpportunityService::SINK_FIELDS), 'solo claves del contrato de la fachada');
        // Lo que decide el Decision Engine no lo propone el Radar: ni contribución, ni score, ni prioridad, ni vencimiento, ni disponibilidad, ni siguiente paso, ni etiqueta.
        foreach (['tenant_id', 'idempotency_key', 'score', 'score_formula_version', 'priority', 'expires_at', 'status', 'attribution_label', 'incremental_estimate', 'experiment_group',
            'expected_contribution', 'availability', 'action_cost', 'next_step', 'owner_user_id'] as $key) {
            $this->assertArrayNotHasKey($key, $a, "el Radar no debe proponer {$key}");
        }
        $this->assertSame('contact', $a['kind']);
        $this->assertSame($this->asOf, $a['as_of']);
        $this->assertSame(30, $a['horizon_days']);
        $this->assertSame('customer_product', $a['entity_type']);
        $this->assertSame($c['run'], $a['source_core_run_id']);
        $this->assertEqualsWithDelta(0.65, $a['probability'], 1e-9);
        // Unidades esperadas = E(N) × cantidad por pedido (4,32), a último precio neto de la relación (8): supuestos del Customer Core que el motor valora.
        $this->assertSame(4.32, $a['expected_units']);
        $this->assertSame(8.0, $a['unit_price']);
        $this->assertEqualsWithDelta(34.56, $a['expected_gross_value'], 1e-6);
        $this->assertSame(['status' => 'estimated', 'p10' => 6.0, 'p50' => 11.5, 'p90' => 19.0], $a['interval']['window_days']);
        $factors = collect($a['explanation'])->keyBy('code');
        foreach (['purchase_probability', 'expected_orders', 'quantity_per_order', 'expected_units', 'window', 'units_basis'] as $code) {
            $this->assertTrue($factors->has($code), "falta el factor {$code}");
        }
        $this->assertSame(1.35, $factors['expected_orders']['value'], 'E(N) servido por el modelo de conteo, no derivado de la probabilidad');
        $this->assertSame('s05_expected_units', $factors['units_basis']['value']);
        $this->assertContains('Oportunidad potencial, atribución no demostrada: no implica ventas causadas por el contacto.', $a['assumptions']);
        $this->assertMatchesRegularExpression('/^radar-v1:[0-9a-f]{12}$/', $a['policy']);
        $this->assertSame($c['run'], $a['model_versions']['customer_core']['run_id']);
        $this->assertSame('calibrated', $a['uncertainty']['calibration_status']);
        // Sin PII: ni el código del comprador viaja en el texto de la explicación.
        $this->assertStringNotContainsString('CLI-0001', json_encode(array_diff_key($a, ['customer_id' => 1])));
    }

    public function test_window_figures_travel_only_with_a_state_that_backs_them(): void
    {
        $c = $this->radarCompany();
        // c2 × p2 (contacto) tiene estado insufficient_data; aunque la columna trajera cuantiles, no se proponen.
        DB::table('customer_predictions')->where('core_run_id', $c['run'])->where('customer_id', $c['cat']['customers']['c2'])->where('product_id', $c['cat']['products']['p2'])
            ->update(['window_p10_days' => 5, 'window_p50_days' => 9, 'window_p90_days' => 14]);

        $candidates = $this->build($c);
        $insufficient = $this->pick($candidates, 'contact', 30, $c['cat']['customers']['c2'], $c['cat']['products']['p2']);
        $this->assertSame(['status' => 'insufficient_data', 'p10' => null, 'p50' => null, 'p90' => null], $insufficient->attributes['interval']['window_days']);
        $this->assertTrue(collect($insufficient->attributes['constraints'])->contains(fn (array $c) => $c['code'] === 'window_without_figures' && str_contains($c['note'], 'Ventana de recompra sin cifras')), 'las restricciones son objetos con code');
        // «Atrasado» (c2 × p1, recuperación) sí lleva cifras anchas y no lleva la advertencia.
        $overdue = $this->pick($candidates, 'recovery', 30, $c['cat']['customers']['c2'], $c['cat']['products']['p1']);
        $this->assertEquals(['status' => 'overdue', 'p10' => 5, 'p50' => 9, 'p90' => 14], $overdue->attributes['interval']['window_days']);
        $this->assertSame([], $overdue->attributes['constraints']);
    }

    public function test_a_run_without_s05_falls_back_to_the_single_event_expectation_and_says_so(): void
    {
        $c = $this->radarCompany(s05: false);

        $candidates = $this->build($c);
        $x = $this->pick($candidates, 'contact', 30, $c['cat']['customers']['c1'], $c['cat']['products']['p1']);

        // expected_quantity = 0,65 × 4,0 = 2,6 unidades a 8 = 20,8; ventana sin estado → sin cifras.
        $this->assertEqualsWithDelta(2.6, $x->attributes['expected_units'], 1e-9);
        $this->assertEqualsWithDelta(20.8, $x->attributes['expected_gross_value'], 1e-6);
        $this->assertSame('single_event_expected_quantity', collect($x->attributes['explanation'])->firstWhere('code', 'units_basis')['value']);
        $this->assertNotContains('expected_orders', array_column($x->attributes['explanation'], 'code'), 'sin E(N) estimado no se muestra un nº de pedidos');
        $this->assertSame(['status' => null, 'p10' => null, 'p50' => null, 'p90' => null], $x->attributes['interval']['window_days']);
        // Sin estado de ventana (NULL) nada se atrasa: no hay recuperaciones y las relaciones con P ≥ 0,20 siguen siendo contacto.
        $this->assertSame(0, collect($candidates)->where('kind', 'recovery')->count());
        $this->assertSame(10, collect($candidates)->where('kind', 'contact')->count());
    }

    public function test_materialize_proposes_every_candidate_through_the_sink_and_is_idempotent(): void
    {
        $c = $this->radarCompany();
        $sink = $this->fakeSink();

        $first = $this->withinTenant($c['tenant'], fn () => app(CommercialOpportunityService::class)->materialize($sink));
        $second = $this->withinTenant($c['tenant'], fn () => app(CommercialOpportunityService::class)->materialize($sink));

        $this->assertSame('completed', $first->status());
        $this->assertSame([12, 12, 0, 0], [$first->candidates, $first->created, $first->existing, $first->failed]);
        $this->assertSame([12, 0, 12, 0], [$second->candidates, $second->created, $second->existing, $second->failed], 'mismos insumos: no se duplica nada');
        $this->assertSame(24, count($sink->calls));
        $this->assertSame(12, $this->withinTenant($c['tenant'], fn () => DecisionRecommendation::query()->count()));
        $this->assertEquals(['contact' => 9, 'recovery' => 3], $first->byKind);
        $this->assertSame([7, 30, 90], $first->horizons);
        $this->assertSame($c['run'], $first->runId);
    }

    public function test_a_failing_proposal_is_reported_and_does_not_stop_the_others(): void
    {
        $c = $this->radarCompany();
        $sink = $this->fakeSink();
        $sink->failKind = 'recovery';

        $result = $this->withinTenant($c['tenant'], fn () => app(CommercialOpportunityService::class)->materialize($sink));

        $this->assertSame('partial', $result->status());
        $this->assertSame([12, 9, 3], [$result->candidates, $result->created, $result->failed]);
        $this->assertCount(3, $result->errors);
        $this->assertStringContainsString('recovery', $result->errors[0]);
    }

    public function test_only_the_requested_horizons_that_the_plan_allows_are_used(): void
    {
        $c = $this->radarCompany();
        $this->assertSame([30], collect($this->build($c, [30]))->pluck('horizonDays')->unique()->values()->all());

        // Un plan sin 90 días: la corrida lo trae, pero el Radar no lo propone.
        $c['tenant']->forceFill(['plan_overrides' => ['horizons' => [7, 30]]])->save();
        $this->assertEqualsCanonicalizing([7, 30], collect($this->build($c))->pluck('horizonDays')->unique()->values()->all());
    }

    public function test_a_plan_without_customer_gets_no_opportunities_and_no_run_means_nothing_to_propose(): void
    {
        $c = $this->radarCompany();
        $c['tenant']->forceFill(['plan_overrides' => ['features' => ['customer' => false]]])->save();
        $this->assertSame([], $this->build($c));

        [$tenant] = $this->createTenantWithUser();
        $sink = $this->fakeSink();
        $result = $this->withinTenant($tenant, fn () => app(CommercialOpportunityService::class)->materialize($sink));
        $this->assertSame('no_run', $result->status());
        $this->assertSame([], $sink->calls);
    }

    public function test_a_historical_run_is_never_the_source_of_opportunities(): void
    {
        [$tenant] = $this->createTenantWithUser();
        $cat = $this->seedCustomerCatalog($tenant);
        $run = $this->seedPredictionRun($tenant, $cat);
        DB::table('core_runs')->where('id', $run)->update(['run_type' => 'historical', 'is_current' => false]);

        $this->assertSame([], $this->withinTenant($tenant, fn () => app(CommercialOpportunityService::class)->build()));
    }

    public function test_deleted_customers_and_inactive_products_do_not_originate_opportunities(): void
    {
        $c = $this->radarCompany();
        DB::table('customers')->where('id', $c['cat']['customers']['c2'])->update(['deleted_at' => now()]);
        DB::table('products')->where('id', $c['cat']['products']['p2'])->update(['is_active' => false]);

        $candidates = collect($this->build($c));

        $this->assertSame([$c['cat']['customers']['c1']], $candidates->pluck('customerId')->unique()->values()->all());
        $this->assertSame([$c['cat']['products']['p1']], $candidates->pluck('productId')->unique()->values()->all());
    }

    public function test_proposing_requires_the_decision_engine_binding_and_the_radar_never_stands_in_for_it(): void
    {
        $c = $this->radarCompany();
        $this->app->offsetUnset(RecommendationSink::class);   // sin motor de decisiones registrado

        try {
            $this->withinTenant($c['tenant'], fn () => app(CommercialOpportunityService::class)->propose());
            $this->fail('Debió lanzar RadarException');
        } catch (RadarException $e) {
            $this->assertSame('decision_engine_unavailable', $e->errorCode);
            $this->assertSame(503, $e->status);
        }
        $this->assertSame(0, DB::table('decision_recommendations')->count(), 'el Radar no escribe recomendaciones por su cuenta');
    }
}
