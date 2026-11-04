<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Contracts\Decision\RecommendationSink;
use App\Enums\TenantRole;
use App\Services\Decision\ExperimentAssigner;
use App\Services\Radar\CommercialOpportunityService;
use App\Services\Radar\MaterializationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Radar\Support\RadarFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-A + W5-B: el Radar propone por el contenedor REAL (RecommendationSink → DecisionEngine). El motor valora lo que el Radar le entrega con su propia economía unitaria
 * as-of y su disponibilidad (contribución solo con costo confiable, valor del contacto limitado a lo disponible), puntúa, asigna el experimento y deja el evento inicial; el Radar
 * lista, resume y registra el seguimiento sobre lo que el motor guardó. Se omite si el motor aún no está registrado en AppServiceProvider.
 */
class RadarDecisionEngineIntegrationTest extends TestCase
{
    use RadarFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->bound(RecommendationSink::class)) {
            $this->markTestSkipped('El Decision Engine de W5-B (RecommendationSink) aún no está registrado en AppServiceProvider: el arquitecto ejecuta esta prueba tras integrar W5-B.');
        }
    }

    /** Empresa con costo confiable para p1 (3 ventas con costo 5 en 90 días), costo no confiable para p2 (2 líneas) y stock de p1. */
    private function company(float $stockP1 = 10.0, TenantRole $role = TenantRole::Analyst): array
    {
        $c = $this->radarCompany($role);
        $this->seedCustomerSales($c['tenant'], $c['cat'], 'c1', 'p1', ['2026-08-01', '2026-08-20', '2026-09-10'], 2, 5.0);
        $this->seedCustomerSales($c['tenant'], $c['cat'], 'c1', 'p2', ['2026-08-01', '2026-08-20'], 2, 3.0);
        $this->seedStock($c['tenant'], $c['cat'], 'p1', '2026-09-30', $stockP1, 2, 5);

        return $c;
    }

    private function propose(array $c): MaterializationResult
    {
        return $this->withinTenant($c['tenant'], fn () => app(CommercialOpportunityService::class)->propose());
    }

    private function rec(array $c, string $kind, int $horizon, string $customer, string $product): object
    {
        return DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('kind', $kind)->where('horizon_days', $horizon)
            ->where('customer_id', $c['cat']['customers'][$customer])->where('product_id', $c['cat']['products'][$product])->first();
    }

    private function api(array $c): static
    {
        return $this->actingAsTenantUser($c['user'], $c['tenant']);
    }

    public function test_the_engine_values_stores_and_scores_what_the_radar_proposes_and_repeating_creates_nothing(): void
    {
        $c = $this->company();

        $first = $this->propose($c);
        $second = $this->propose($c);

        $this->assertSame('completed', $first->status(), implode(' | ', $first->errors));
        $this->assertSame([12, 12, 0, 0], [$first->candidates, $first->created, $first->existing, $first->failed]);
        $this->assertSame([0, 12], [$second->created, $second->existing], 'idempotente: mismos insumos no crean nada nuevo');
        $rows = DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->get();
        $this->assertCount(12, $rows);
        foreach ($rows as $row) {
            $this->assertSame('potential_unproven', $row->attribution_label, 'sin evidencia de uplift');
            $this->assertSame(64, strlen($row->score_formula_version));
            $this->assertSame(64, strlen($row->idempotency_key));
            $this->assertSame('system', $row->proposed_by_kind);
            $this->assertMatchesRegularExpression('/^radar-v1:[0-9a-f]{12}$/', $row->policy, 'la política del Radar viaja en cada recomendación');
            $this->assertTrue(DB::table('decision_events')->where('recommendation_id', $row->id)->exists(), 'el Decision Engine deja el evento inicial');
        }

        // c1 × p1: costo confiable (3 ventas con costo 5) y stock disponible (10 − 2 = 8 ≥ 4,32): contribución = 4,32 × (8 − 5), calculada por el motor.
        $p1 = $this->rec($c, 'contact', 30, 'c1', 'p1');
        $this->assertEqualsWithDelta(34.56, (float) $p1->expected_gross_value, 1e-6);
        $this->assertEqualsWithDelta(12.96, (float) $p1->expected_contribution, 1e-6);
        $uncertainty = json_decode($p1->uncertainty, true);
        $this->assertTrue($uncertainty['cost']['reliable']);
        $this->assertSame('calibrated', $uncertainty['calibration_status'], 'los hechos de calibración del Radar se conservan junto a los del motor');
        $availability = json_decode($p1->availability, true);
        $this->assertSame('known', $availability['status']);
        $this->assertEquals(8.0, $availability['available']);
        $this->assertEquals(5.0, $availability['in_transit']);
        $this->assertFalse($availability['in_transit_counted_as_available']);
        // c1 × p2: solo 2 líneas con costo → no confiable: SIN contribución (NULL), el valor es importe bruto y el inventario se desconoce (nunca cero).
        $p2 = $this->rec($c, 'contact', 30, 'c1', 'p2');
        $this->assertNull($p2->expected_contribution);
        $this->assertNotNull($p2->expected_gross_value);
        $this->assertFalse(json_decode($p2->uncertainty, true)['cost']['reliable']);
        $this->assertSame('unknown', json_decode($p2->availability, true)['status']);
        // La recuperación atrasada c2 × p1 también se valora con el costo de p1 (confiable): 0,8 unidades × (8 − 5).
        $recovery = $this->rec($c, 'recovery', 30, 'c2', 'p1');
        $this->assertEqualsWithDelta(2.4, (float) $recovery->expected_contribution, 1e-6);
    }

    public function test_the_radar_lists_what_the_engine_stored_with_its_cost_and_inventory_and_summarizes_it(): void
    {
        $c = $this->company();
        $this->propose($c);

        $rows = collect($this->api($c)->getJson('/api/v1/radar/opportunities?horizon=30&per_page=100')->assertOk()->json('data'))
            ->keyBy(fn ($r) => $r['kind'].':'.$r['customer']['id'].':'.($r['product']['id'] ?? '-'));

        $p1 = $rows["contact:{$c['cat']['customers']['c1']}:{$c['cat']['products']['p1']}"];
        $this->assertSame('reliable', $p1['value']['cost_status']);
        $this->assertEquals(12.96, $p1['value']['expected_contribution']);
        $this->assertEquals(8.0, $p1['inventory']['available']);
        $this->assertSame('unreliable', $rows["contact:{$c['cat']['customers']['c1']}:{$c['cat']['products']['p2']}"]['value']['cost_status']);
        $this->assertNotEmpty($rows["contact:{$c['cat']['customers']['c1']}:{$c['cat']['products']['p2']}"]['value']['cost_reasons']);
        $this->assertContains($p1['priority'], [1, 2, 3, 4, 5], 'la prioridad la fija el Decision Engine');
        $this->assertNotNull($p1['score'], 'el score lo fija el Decision Engine');
        $this->assertSame(64, strlen($p1['score_formula_version']));
        // El resumen suma la contribución solo de las valoradas con costo confiable y nunca entre horizontes.
        $summary = $this->api($c)->getJson('/api/v1/radar/summary?horizon=30')->json('data');
        $this->assertSame(2, $summary['by_horizon']['30']['value']['contribution_opportunities'], 'c1 × p1 y la recuperación de c2 × p1');
        $this->assertEqualsWithDelta(12.96 + 2.4, $summary['by_horizon']['30']['value']['contribution'], 0.01);
        $this->assertSame(['known' => 2, 'unknown' => 2, 'below_expected' => 0, 'not_applicable' => 0], $summary['by_horizon']['30']['inventory'], 'p1 con stock vigente (contacto y recuperación); p2 sin inventario → se desconoce');
    }

    public function test_a_stock_below_the_expected_units_limits_the_value_of_a_contact_and_the_radar_flags_it(): void
    {
        $c = $this->company(stockP1: 5.0);   // disponible 3 < 4,32 unidades esperadas
        $this->propose($c);

        $p1 = $this->rec($c, 'contact', 30, 'c1', 'p1');

        $this->assertEqualsWithDelta(24.0, (float) $p1->expected_gross_value, 1e-6, 'el valor del contacto se limita a lo disponible: 3 × 8');
        $this->assertEqualsWithDelta(9.0, (float) $p1->expected_contribution, 1e-6, '3 × (8 − 5)');
        $codes = array_column(json_decode($p1->constraints, true), 'code');
        $this->assertContains('stock_below_expected_units', $codes);
        $summary = $this->api($c)->getJson('/api/v1/radar/summary?horizon=30')->json('data');
        $this->assertGreaterThanOrEqual(1, $summary['by_horizon']['30']['inventory']['below_expected']);
    }

    public function test_a_control_unit_assigned_by_the_engine_is_never_shown_nor_contacted(): void
    {
        $c = $this->company();
        $key = 'contact-default';
        // Una semilla que deje a c1 en control y a c2 en tratamiento (fracción 0,5): la asignación es reproducible, no aleatoria en la prueba.
        $seed = collect(range(1, 400))->first(fn (int $i) => ExperimentAssigner::uniform("s{$i}", $key, 'customer:'.$c['cat']['customers']['c1']) < 0.5
            && ExperimentAssigner::uniform("s{$i}", $key, 'customer:'.$c['cat']['customers']['c2']) >= 0.5);
        $this->assertNotNull($seed);
        $c['tenant']->forceFill(['settings' => array_merge($c['tenant']->settings ?? [], ['decision' => ['control_fraction' => 0.5, 'experiment_seed' => "s{$seed}"]])])->save();
        $this->propose($c);

        $controlIds = DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('experiment_group', 'control')->pluck('id')->all();
        $this->assertCount(6, $controlIds, 'todas las oportunidades de c1 (2 productos × 3 horizontes) quedan en control: la unidad es el cliente');
        $listed = collect($this->api($c)->getJson('/api/v1/radar/opportunities?per_page=100')->json('data'));
        $this->assertCount(6, $listed, 'solo las de c2: contactos y recuperación de la relación');
        $this->assertEmpty(array_intersect($controlIds, $listed->pluck('id')->all()));
        $this->assertArrayNotHasKey('control_withheld', $this->api($c)->getJson('/api/v1/radar/summary')->json('data'), 'el analista no ve cuántas hay en control');
        $admin = $this->actingAsTenantUser($this->addUserToTenant($c['tenant'], TenantRole::Admin), $c['tenant']);
        $this->assertSame(6, $admin->getJson('/api/v1/radar/summary')->json('data.control_withheld'), 'quien puede aprobar sí, sin filtros de entidad');
        $this->assertArrayNotHasKey('control_withheld', $admin->getJson('/api/v1/radar/summary?customer_id='.$c['cat']['customers']['c1'])->json('data'));
        // Sin contacto: el seguimiento y el detalle de una unidad de control no existen para el Radar.
        $this->api($c)->getJson("/api/v1/radar/opportunities/{$controlIds[0]}")->assertNotFound();
        $this->api($c)->postJson("/api/v1/radar/opportunities/{$controlIds[0]}/feedback", ['state' => 'contactado', 'idempotency_key' => 'control-no-se-contacta'])->assertNotFound();
        $this->assertSame(0, DB::table('decision_events')->where('recommendation_id', $controlIds[0])->where('event', 'feedback')->count());
        // La agenda tampoco las muestra.
        $agenda = $this->api($c)->getJson('/api/v1/radar/agenda?date=2026-10-02&per_page=100')->json('data');
        $this->assertSame([$c['cat']['customers']['c2']], collect($agenda)->pluck('customer.id')->all());
    }

    public function test_feedback_on_an_engine_proposal_is_audited_idempotent_and_keeps_the_engine_history(): void
    {
        $c = $this->company();
        $this->propose($c);
        $rec = $this->rec($c, 'contact', 30, 'c1', 'p1');
        $before = DB::table('decision_events')->where('recommendation_id', $rec->id)->count();

        $first = $this->api($c)->postJson("/api/v1/radar/opportunities/{$rec->id}/feedback", ['state' => 'contactado', 'note' => 'Llamada realizada', 'idempotency_key' => 'motor-real-0001'])->assertCreated();
        $retry = $this->api($c)->postJson("/api/v1/radar/opportunities/{$rec->id}/feedback", ['state' => 'contactado', 'note' => 'Llamada realizada', 'idempotency_key' => 'motor-real-0001'])->assertOk();

        $this->assertSame($first->json('data.event.id'), $retry->json('data.event.id'));
        // El evento inicial del motor se conserva y se suma el de seguimiento + un comentario con el resultado de la sincronización con el workflow (el seguimiento no se modifica).
        $this->assertSame($before + 2, DB::table('decision_events')->where('recommendation_id', $rec->id)->count());
        $this->assertSame(1, DB::table('decision_events')->where('recommendation_id', $rec->id)->where('event', 'comment')->count());
        $history = $this->api($c)->getJson("/api/v1/radar/opportunities/{$rec->id}")->json('data.history');
        $this->assertSame($before + 1, count($history), 'el historial incluye los eventos del motor y el seguimiento (los comentarios del sistema no son pasos)');
        $this->assertSame('skipped', end($history)['workflow_sync'], 'suggested → in_progress no existe: el seguimiento se registró y el workflow no se movió');
        $this->assertSame('transition_not_defined', end($history)['workflow_sync_reason']);
        $this->assertSame('feedback', end($history)['event']);
        $this->assertSame('suggested', $first->json('data.opportunity.status'), 'la transición del workflow no la hace la agenda');
        $this->assertSame('contactado', $first->json('data.opportunity.state'));
    }
}
