<?php

declare(strict_types=1);

namespace Tests\Feature\Decision;

use App\Models\DecisionEvent;
use App\Models\DecisionRecommendation;
use App\Models\Tenant;
use App\Services\Decision\DecisionGenerator;
use App\Services\Decision\DecisionSettings;
use App\Services\Decision\ScoreFormula;
use App\Services\Decision\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Decision\Support\DecisionFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-B (§11): el Decision Engine genera recomendaciones de contacto, recuperación, riesgo de cliente, reposición, inventario-demanda y presupuesto
 * a partir de lo YA persistido (Customer, recomendaciones de reposición, plan de abastecimiento, inventario), de forma idempotente, reproducible, por
 * empresa y sin recalcular nada.
 */
final class DecisionGenerationTest extends TestCase
{
    use DecisionFixtures;
    use RefreshDatabase;

    /**
     * Genera con el Decision Engine. Por defecto el productor de contactos cliente × producto es el Radar (`contact_source = radar`); estas pruebas ejercitan la
     * alternativa `engine` salvo que se pida lo contrario o la prueba la fije.
     *
     * @return array<string, mixed>
     */
    private function generate(Tenant $tenant, bool $dryRun = false, bool $engineContacts = true): array
    {
        if ($engineContacts) {
            $tenant->refresh();
            $decision = (array) ($tenant->settings['decision'] ?? []);
            if (! array_key_exists('contact_source', $decision)) {
                $tenant->forceFill(['settings' => array_merge($tenant->effectiveSettings(), ['decision' => $decision + ['contact_source' => 'engine']])])->save();
            }
        }

        return app(DecisionGenerator::class)->generate($tenant, now('America/Guayaquil')->toImmutable(), $dryRun);
    }

    /** @return Collection<int, DecisionRecommendation> */
    private function recs(Tenant $tenant, ?string $kind = null)
    {
        return $this->withinTenant($tenant, fn () => DecisionRecommendation::query()->when($kind, fn ($q) => $q->where('kind', $kind))->orderBy('id')->get());
    }

    // ───────────────────────── cliente: contacto, recuperación y riesgo ─────────────────────────

    public function test_customer_predictions_become_contacts_and_inactive_buyers_become_recovery(): void
    {
        $w = $this->decisionWorld();

        $report = $this->generate($w['tenant']);

        $this->assertSame(['contact' => 3, 'recovery' => 1], $report['created'], 'c2 × p1 (P = 0,15) queda bajo el mínimo de la empresa (0,20)');
        $this->assertSame([], $report['errors']);
        $contacts = $this->recs($w['tenant'], 'contact');
        $this->assertCount(3, $contacts);
        $this->assertEqualsCanonicalizing(['customer_product'], $contacts->pluck('entity_type')->unique()->all());
        $this->assertEqualsCanonicalizing([$w['run']], $contacts->pluck('source_core_run_id')->unique()->all());
        // Cada contacto trae probabilidad, intervalo, ventana honesta, supuestos y explicación con su fuente.
        $c1p1 = $contacts->first(fn ($r) => $r->customer_id === $w['cat']['customers']['c1'] && $r->product_id === $w['cat']['products']['p1']);
        $this->assertEqualsWithDelta(0.65, $c1p1->probability, 1e-9);
        $this->assertSame('estimated', $c1p1->interval['window']['status']);
        $this->assertEqualsWithDelta(4.32, collect($c1p1->explanation)->firstWhere('code', 'value_calculation')['value']['expected_units'], 1e-9);
        $this->assertSame('expected_orders_x_quantity_per_order', collect($c1p1->explanation)->firstWhere('code', 'orders_basis')['value']);
        $this->assertStringContainsString('customer_predictions', collect($c1p1->explanation)->firstWhere('code', 'purchase_probability')['source']);
        $this->assertEqualsWithDelta(4.32 * 2.0, $c1p1->expected_contribution, 1e-6, 'E(N)·cantidad por pedido × (precio − costo confiable)');
        $c2p2 = $contacts->first(fn ($r) => $r->customer_id === $w['cat']['customers']['c2'] && $r->product_id === $w['cat']['products']['p2']);
        $this->assertSame('insufficient_data', $c2p2->interval['window']['status']);
        $this->assertNull($c2p2->interval['window']['p50_days'], 'la ventana sin respaldo no trae cifras');
        // p2 no tiene costo confiable: contribución NULL, importe con la marca.
        $c1p2 = $contacts->first(fn ($r) => $r->customer_id === $w['cat']['customers']['c1'] && $r->product_id === $w['cat']['products']['p2']);
        $this->assertNull($c1p2->expected_contribution);
        $this->assertTrue($c1p2->uncertainty['value']['cost_unreliable']);
        // Vencen al final del horizonte.
        $this->assertSame(CarbonImmutableHelper::endOfDay($w['asOf'], 30), $c1p1->expires_at->setTimezone('America/Guayaquil')->toDateString());
        // La ausencia respecto al patrón NO es abandono; el valor en riesgo es observado, no pronosticado.
        $recovery = $this->recs($w['tenant'], 'recovery')->sole();
        $this->assertSame($w['cat']['customers']['c2'], $recovery->customer_id);
        $this->assertSame('customer', $recovery->entity_type);
        $this->assertNull($recovery->probability, 'inactivity_risk es un percentil, no una probabilidad de abandono');
        $this->assertNull($recovery->expected_contribution);
        $this->assertContains('no_product_scope', $recovery->uncertainty['cost']['reasons']);
        $this->assertStringContainsString('no implica abandono', implode(' ', $recovery->assumptions));
        // Todas con etiqueta honesta.
        $this->assertSame(['potential_unproven'], $this->recs($w['tenant'])->pluck('attribution_label')->unique()->all());
        $this->assertSame([null], $this->recs($w['tenant'])->pluck('incremental_estimate')->unique()->all());
    }

    public function test_customer_risk_covers_at_risk_or_declining_buyers_but_never_insufficient_history_or_generic_buyers(): void
    {
        $w = $this->decisionWorld();
        $c = $w['cat']['customers'];
        DB::table('customer_activity')->where('core_run_id', $w['run'])->where('customer_id', $c['c1'])->update(['inactivity_status' => 'active', 'behavior_change' => 'decrease', 'spend_prior' => 100, 'spend_recent' => 60]);
        DB::table('customer_activity')->where('core_run_id', $w['run'])->where('customer_id', $c['c2'])->update(['inactivity_status' => 'insufficient_history', 'behavior_change' => 'insufficient_history']);
        $generic = (array) DB::table('customer_activity')->where('core_run_id', $w['run'])->where('customer_id', $c['c1'])->first();
        unset($generic['id']);
        DB::table('customer_activity')->insert(['customer_id' => $c['c3'], 'inactivity_status' => 'inactive_by_pattern'] + $generic);   // consumidor final

        $report = $this->generate($w['tenant']);

        $this->assertSame(['contact' => 3, 'customer_risk' => 1], $report['created'], 'sin recuperación: el único inactivo es genérico o sin historial');
        $risk = $this->recs($w['tenant'], 'customer_risk')->sole();
        $this->assertSame($c['c1'], $risk->customer_id);
        $this->assertEqualsWithDelta(40.0, $risk->expected_gross_value, 1e-6, 'caída observada del gasto: 100 → 60');
        $this->assertNull($risk->expected_contribution);
        $this->assertSame('decrease', collect($risk->explanation)->firstWhere('code', 'behavior_change')['value']);
        $this->assertStringContainsString('no implica abandono', implode(' ', $risk->assumptions));
    }

    public function test_the_priority_follows_the_score_rank_within_each_batch(): void
    {
        $w = $this->decisionWorld();

        $this->generate($w['tenant']);

        $contacts = $this->recs($w['tenant'], 'contact')->sortByDesc('score')->values();
        $this->assertSame(1, $contacts[0]->priority, 'el de mayor score es prioridad 1');
        foreach ($contacts as $i => $rec) {
            if ($i > 0) {
                $this->assertGreaterThanOrEqual($contacts[$i - 1]->priority, $rec->priority, 'la prioridad nunca mejora al bajar el score');
            }
        }
        $this->assertGreaterThan(1, $contacts->pluck('priority')->unique()->count(), 'el lote se reparte en varias prioridades');
    }

    public function test_generation_is_idempotent_the_second_run_creates_nothing(): void
    {
        $w = $this->decisionWorld();

        $first = $this->generate($w['tenant']);
        $before = [$this->recs($w['tenant'])->pluck('idempotency_key')->sort()->values()->all(), $this->withinTenant($w['tenant'], fn () => DecisionEvent::query()->count())];
        $second = $this->generate($w['tenant']);
        $third = $this->generate($w['tenant']);

        $this->assertSame([], $second['created']);
        $this->assertSame([], $third['created']);
        $this->assertSame(array_sum($first['created']), array_sum($second['existing']));
        $this->assertSame($before, [$this->recs($w['tenant'])->pluck('idempotency_key')->sort()->values()->all(), $this->withinTenant($w['tenant'], fn () => DecisionEvent::query()->count())],
            'mismas filas, mismas huellas y mismos eventos');
    }

    public function test_generic_buyers_abstentions_and_low_probability_rows_do_not_become_contacts(): void
    {
        $w = $this->decisionWorld();
        $generic = $w['cat']['customers']['c3'];
        $base = (array) DB::table('customer_predictions')->where('core_run_id', $w['run'])->where('horizon_days', 30)->first();
        unset($base['id']);
        DB::table('customer_predictions')->insert([['customer_id' => $generic, 'purchase_probability' => 0.9] + $base]);          // consumidor final: no es un comprador recurrente
        DB::table('customer_predictions')->where('core_run_id', $w['run'])->where('horizon_days', 30)->where('customer_id', $w['cat']['customers']['c2'])
            ->where('product_id', $w['cat']['products']['p1'])->update(['purchase_probability' => null]);                            // abstención explícita
        DB::table('customer_predictions')->where('core_run_id', $w['run'])->where('horizon_days', 30)->where('customer_id', $w['cat']['customers']['c1'])
            ->where('product_id', $w['cat']['products']['p2'])->update(['purchase_probability' => 0.05]);                            // bajo el mínimo de la empresa

        $this->generate($w['tenant']);

        $contacts = $this->recs($w['tenant'], 'contact');
        $this->assertCount(2, $contacts);   // c1×p1 y c2×p2
        $this->assertNotContains($generic, $contacts->pluck('customer_id')->all());
    }

    public function test_the_per_horizon_cap_keeps_the_most_valuable_contacts(): void
    {
        $w = $this->decisionWorld();
        $w['tenant']->forceFill(['settings' => array_merge($w['tenant']->effectiveSettings(), ['decision' => ['max_contact_per_horizon' => 2]])])->save();

        $this->generate($w['tenant']);

        $contacts = $this->recs($w['tenant'], 'contact');
        $this->assertCount(2, $contacts);
        $this->assertSame(3, DB::table('customer_predictions')->where('core_run_id', $w['run'])->where('horizon_days', 30)->where('purchase_probability', '>=', 0.2)->count());
        $this->assertSame($contacts->max('score'), $contacts->sortByDesc('score')->first()->score);
        $this->assertSame(1, $contacts->sortByDesc('score')->first()->priority);
    }

    public function test_new_prediction_inputs_replace_the_untouched_ones_and_refresh_the_in_flight_one(): void
    {
        $w = $this->decisionWorld();
        $this->generate($w['tenant']);
        $old = $this->recs($w['tenant'], 'contact');
        // Una de las viejas ya está asignada (trabajo humano en curso): no se expira ni se le crea sucesora, se refresca.
        DB::table('decision_recommendations')->where('id', $old[0]->id)->update(['status' => 'assigned']);
        DB::table('core_runs')->where('id', $w['run'])->update(['is_current' => false]);
        $newRun = $this->seedPredictionRun($w['tenant'], $w['cat'], [7, 30, 90], $w['asOf'], true);
        $this->seedCustomerS05($w['tenant']->id, $newRun, $w['cat']);

        $report = $this->generate($w['tenant']);

        $this->assertSame(2, $report['created']['contact'], 'una por alcance sin trabajo; la del alcance en curso no tiene sucesora');
        $this->assertSame(1, $report['existing']['contact'], 'la que está en curso se cuenta como existente (se refrescó)');
        $statuses = $this->recs($w['tenant'], 'contact')->groupBy('source_core_run_id')->map(fn ($g) => $g->pluck('status')->sort()->values()->all());
        $this->assertSame(['assigned', 'expired', 'expired'], $statuses[$w['run']], 'la asignada por una persona no se borra');
        $this->assertSame(['suggested', 'suggested'], $statuses[$newRun]);
        $this->assertSame(3, $this->withinTenant($w['tenant'], fn () => DecisionEvent::query()->where('to_status', 'expired')->where('actor_kind', 'system')->count()), '2 contactos + la recuperación');
        // Nadie tiene dos accionables para el mismo alcance: la de la nueva corrida para el alcance en curso no existe.
        $this->assertCount(0, $this->recs($w['tenant'], 'contact')->filter(fn ($r) => $r->source_core_run_id === $newRun && $r->customer_id === $old[0]->customer_id && $r->product_id === $old[0]->product_id));
        $refresh = $this->withinTenant($w['tenant'], fn () => DecisionEvent::query()->where('recommendation_id', $old[0]->id)->where('event', 'comment')->firstOrFail());
        $this->assertSame([$newRun, 'refresh'], [$refresh->payload['source']['core_run_id'], $refresh->payload['kind']]);
    }

    public function test_by_default_the_radar_is_the_only_producer_of_customer_product_contacts(): void
    {
        $w = $this->decisionWorld();

        $report = $this->generate($w['tenant'], false, false);   // sin tocar la configuración: contact_source = radar

        $this->assertSame('radar', DecisionSettings::forTenant($w['tenant'])->contactSource());
        $this->assertSame('produced_by_radar', $report['skipped']['contact:30']);
        $this->assertSame(['recovery' => 1], $report['created'], 'la recuperación y el riesgo a nivel de cliente siguen siendo del Decision Engine');
        $this->assertCount(0, $this->recs($w['tenant'], 'contact'));
        $this->assertSame(['customer'], $this->recs($w['tenant'])->pluck('entity_type')->unique()->values()->all(), 'nada cliente × producto sale de decisions-generate');
    }

    public function test_dry_run_writes_nothing(): void
    {
        $w = $this->decisionWorld();

        $report = $this->generate($w['tenant'], true);

        $this->assertTrue($report['dry_run']);
        $this->assertSame(['contact' => 3, 'recovery' => 1], $report['created'], 'cuenta lo que generaría');
        $this->assertCount(0, $this->recs($w['tenant']));
        $this->assertSame(0, DB::table('decision_events')->count());
        $this->assertSame(0, DB::table('experiment_assignments')->count());
    }

    public function test_a_stale_prediction_does_not_generate_already_expired_contacts(): void
    {
        $w = $this->decisionWorld(now('America/Guayaquil')->subDays(45)->toDateString());

        $report = $this->generate($w['tenant']);

        $this->assertSame([], $report['created']);
        $this->assertSame('source_expired', $report['skipped']['contact:30']);
        $this->assertSame('source_expired', $report['skipped']['activity']);
    }

    public function test_a_company_without_customer_predictions_generates_nothing_and_says_why(): void
    {
        [$tenant] = $this->createTenantWithUser();

        $report = $this->generate($tenant);

        $this->assertSame([], $report['created']);
        $this->assertSame('no_customer_prediction_run', $report['skipped']['customer']);
        $this->assertSame([], $report['errors']);
    }

    public function test_the_same_inputs_produce_the_same_recommendations_in_two_companies(): void
    {
        $a = $this->decisionWorld();
        $b = $this->decisionWorld();
        $this->forecastWithRecommendations($a['tenant'], $a['cat'], $a['asOf']);
        $this->forecastWithRecommendations($b['tenant'], $b['cat'], $b['asOf']);

        $this->generate($a['tenant']);
        $this->generate($b['tenant']);

        $shape = fn (array $w) => $this->recs($w['tenant'])->map(fn (DecisionRecommendation $r) => [
            $r->kind, $r->entity_type, array_search($r->customer_id, $w['cat']['customers'], true) ?: null, array_search($r->product_id, $w['cat']['products'], true) ?: null,
            $r->horizon_days, $r->probability, $r->expected_gross_value, $r->expected_contribution, $r->score, $r->score_formula_version, $r->priority, $r->attribution_label,
            $r->expires_at->toDateString(), preg_replace('/(core_run|forecast_run|supply_plan|id)=\d+/', '$1=N', (string) json_encode($r->explanation)), $r->uncertainty['value'] ?? null,
        ])->sortBy(fn ($row) => json_encode([$row[0], $row[2], $row[3], $row[4]]))->values()->all();
        $this->assertSame($shape($a), $shape($b), 'mismo input, misma recomendación, mismo ranking y misma explicación');
        $this->assertNotEmpty($shape($a));
    }

    public function test_generation_for_one_company_never_touches_another(): void
    {
        $a = $this->decisionWorld();
        $b = $this->decisionWorld();

        $this->generate($a['tenant']);

        $this->assertGreaterThan(0, $this->recs($a['tenant'])->count());
        $this->assertCount(0, $this->recs($b['tenant']));
        foreach (DB::table('decision_recommendations')->get() as $row) {
            $this->assertSame($a['tenant']->id, $row->tenant_id);
            if ($row->customer_id !== null) {
                $this->assertContains($row->customer_id, array_values($a['cat']['customers']));
            }
        }
        // El comando recorre cada empresa por separado y es idempotente.
        Artisan::call('predictily:decisions-generate', ['--tenant' => $b['tenant']->id]);
        $this->assertGreaterThan(0, $this->recs($b['tenant'])->count());
        $countA = $this->recs($a['tenant'])->count();
        Artisan::call('predictily:decisions-generate');
        $this->assertSame($countA, $this->recs($a['tenant'])->count());
    }

    // ───────────────────────── reposición ─────────────────────────

    public function test_replenishment_comes_from_the_existing_recommendations_without_recalculating_anything(): void
    {
        $w = $this->decisionWorld();
        $forecast = $this->forecastWithRecommendations($w['tenant'], $w['cat'], $w['asOf']);

        $report = $this->generate($w['tenant']);

        $this->assertSame(2, $report['created']['replenishment']);
        $p1 = $this->recs($w['tenant'], 'replenishment')->first(fn ($r) => $r->product_id === $w['cat']['products']['p1']);
        $this->assertSame('product_branch', $p1->entity_type);
        $this->assertSame($forecast, $p1->source_forecast_run_id);
        $this->assertNull($p1->source_core_run_id, 'sin plan sobre esta corrida de pronóstico no se mezcla');
        $this->assertEqualsWithDelta(0.99, $p1->probability, 1e-9);
        $this->assertEquals(20.0, $p1->availability['available']);
        // Unidades en riesgo = max(100 − 20, 0) = 80, precio neto reciente 8, costo confiable 6.
        $this->assertEqualsWithDelta(80 * 8.0, $p1->expected_gross_value, 1e-6);
        $this->assertEqualsWithDelta(80 * 2.0, $p1->expected_contribution, 1e-6);
        $this->assertEqualsWithDelta(116.45 * 6.0, $p1->action_cost, 1e-3, 'el desembolso se informa como capital comprometido');
        $this->assertEqualsWithDelta($p1->expected_contribution, $p1->score, 1e-6, 'el capital comprometido no se resta del score');
        $this->assertSame(14, $p1->horizon_days);
        $this->assertSame('critical', collect($p1->explanation)->firstWhere('code', 'stockout_risk')['value']);
        // p2: sin costo confiable → contribución NULL.
        $p2 = $this->recs($w['tenant'], 'replenishment')->first(fn ($r) => $r->product_id === $w['cat']['products']['p2']);
        $this->assertNull($p2->expected_contribution);
        $this->assertNull($p2->action_cost, 'sin costo unitario no hay inversión estimada');
        $this->assertSame($report['created']['replenishment'], $this->recs($w['tenant'], 'replenishment')->count());
        // Las recomendaciones de etapa 1 siguen intactas (no se consumen ni se recalculan).
        $this->assertSame(2, DB::table('recommendations')->where('status', 'open')->count());
        // Reposición es sensible: exige aprobación.
        $this->assertTrue(app(Workflow::class)->requiresApproval($p1, DecisionSettings::forTenant($w['tenant'])));
    }

    public function test_the_supply_plan_built_on_the_same_forecast_sets_quantity_date_supplier_and_over_budget_constraints(): void
    {
        $w = $this->decisionWorld();
        $forecast = $this->forecastWithRecommendations($w['tenant'], $w['cat'], $w['asOf']);
        $plan = $this->withinTenant($w['tenant'], fn () => $this->supplyPlan($w['tenant'], $w['cat'], $w['asOf'], $forecast));

        $this->generate($w['tenant']);

        $p1 = $this->recs($w['tenant'], 'replenishment')->first(fn ($r) => $r->product_id === $w['cat']['products']['p1']);
        $this->assertSame($plan['run'], $p1->source_core_run_id);
        $this->assertEqualsWithDelta(600.0, $p1->action_cost, 1e-6, 'costo de las líneas del plan');
        $qty = collect($p1->explanation)->firstWhere('code', 'suggested_quantity');
        $this->assertSame(100.0, (float) $qty['value']);
        $this->assertSame('supply_plan_lines', $qty['detail']['source']);
        $codes = collect($p1->constraints)->pluck('code')->all();
        $this->assertContains('moq', $codes);
        $this->assertContains('over_budget', $codes, 'faltante por presupuesto en el plan: compra sobre presupuesto');
        $this->assertStringContainsString('100', $p1->next_step);
    }

    public function test_a_supply_plan_of_another_forecast_run_is_not_mixed_in(): void
    {
        $w = $this->decisionWorld();
        $forecast = $this->forecastWithRecommendations($w['tenant'], $w['cat'], $w['asOf']);
        $other = $this->forecastWithRecommendations($w['tenant'], $w['cat'], $w['asOf'], false);
        $this->withinTenant($w['tenant'], fn () => $this->supplyPlan($w['tenant'], $w['cat'], $w['asOf'], $other));

        $report = $this->generate($w['tenant']);

        $this->assertSame('plan_not_built_on_current_forecast_run', $report['skipped']['supply_plan']);
        $p1 = $this->recs($w['tenant'], 'replenishment')->first(fn ($r) => $r->product_id === $w['cat']['products']['p1']);
        $this->assertSame($forecast, $p1->source_forecast_run_id);
        $this->assertNull($p1->source_core_run_id);
        $this->assertNotContains('over_budget', collect($p1->constraints)->pluck('code')->all());
        $this->assertSame('recommendations', collect($p1->explanation)->firstWhere('code', 'suggested_quantity')['detail']['source']);
    }

    // ───────────────────────── presupuesto e inventario-demanda ─────────────────────────

    public function test_unmet_demand_because_of_the_budget_becomes_a_budget_constraint_recommendation(): void
    {
        $w = $this->decisionWorld();
        $forecast = $this->forecastWithRecommendations($w['tenant'], $w['cat'], $w['asOf']);
        $this->withinTenant($w['tenant'], fn () => $this->supplyPlan($w['tenant'], $w['cat'], $w['asOf'], $forecast, 40.0));
        DB::table('purchase_budgets')->insert(['tenant_id' => $w['tenant']->id, 'period_start' => $w['asOf'], 'period_end' => now()->addDays(60)->toDateString(), 'amount' => 500, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now()]);

        $report = $this->generate($w['tenant']);

        $this->assertSame(1, $report['created']['budget_constraint']);
        $rec = $this->recs($w['tenant'], 'budget_constraint')->sole();
        $this->assertSame('product_branch', $rec->entity_type);
        $this->assertSame('budget', $rec->constraints[0]['code']);
        $this->assertSame(40.0, (float) $rec->constraints[0]['unmet_units']);
        $this->assertEqualsWithDelta(40 * 8.0, $rec->expected_gross_value, 1e-6);
        $this->assertEqualsWithDelta(40 * 2.0, $rec->expected_contribution, 1e-6);
        $this->assertEqualsWithDelta(40 * 6.0, $rec->action_cost, 1e-6, 'cuánto costaría cubrir el faltante con el costo del plan');
        $this->assertCount(1, $rec->constraints[0]['budgets']);
        // Sin faltante por presupuesto no hay recomendación.
        DB::table('supply_plan_shortfalls')->where('reason', 'budget')->delete();
        DB::table('decision_recommendations')->where('kind', 'budget_constraint')->delete();
        $this->assertArrayNotHasKey('budget_constraint', $this->generate($w['tenant'])['created']);
    }

    public function test_a_shortage_against_customer_demand_is_a_mismatch_valued_at_the_missing_units(): void
    {
        $w = $this->decisionWorld();
        $this->inventory($w['tenant'], $w['cat'], 'p1', $w['asOf'], 2.0, 0.0, 100.0);   // demanda esperada de p1 = 4,32 + 0,8 = 5,12; el tránsito no cuenta

        $report = $this->generate($w['tenant']);

        $this->assertSame(1, $report['created']['inventory_mismatch']);
        $rec = $this->recs($w['tenant'], 'inventory_mismatch')->sole();
        $this->assertSame('inventory_mismatch:shortage_vs_customer_demand', $rec->policy);
        $this->assertSame('product', $rec->entity_type);
        $this->assertEqualsWithDelta(5.12 - 2.0, $rec->constraints[0]['shortfall_units'], 1e-6);
        $this->assertEqualsWithDelta((5.12 - 2.0) * 8.0, $rec->expected_gross_value, 1e-6);
        $this->assertEqualsWithDelta((5.12 - 2.0) * 2.0, $rec->expected_contribution, 1e-6);
        $this->assertFalse($rec->availability['in_transit_counted_as_available']);
        // p2 no tiene foto de inventario: su disponibilidad es desconocida y no genera nada (no se supone cero).
        $this->assertSame('availability_unknown', $report['skipped']['availability_unknown']);
    }

    public function test_overstock_without_customer_demand_is_a_mismatch_without_an_invented_value(): void
    {
        $w = $this->decisionWorld();
        $forecast = $this->forecastWithRecommendations($w['tenant'], $w['cat'], $w['asOf']);
        DB::table('recommendations')->where('forecast_run_id', $forecast)->where('product_id', $w['cat']['products']['p2'])
            ->update(['type' => 'overstock', 'available' => 500, 'on_hand' => 500, 'suggested_qty' => 0]);

        $this->generate($w['tenant']);

        $excess = $this->recs($w['tenant'], 'inventory_mismatch')->first(fn ($r) => str_contains((string) $r->policy, 'excess'));
        $this->assertNotNull($excess);
        $this->assertSame($w['cat']['products']['p2'], $excess->product_id);
        $this->assertNull($excess->expected_gross_value);
        $this->assertNull($excess->expected_contribution);
        $this->assertNull($excess->score, 'sin tasa de mantener inventario no hay valor estimado: el score queda en blanco, no en cero');
        $this->assertSame(5, $excess->priority, 'sin score va al final del grupo');
        $this->assertStringContainsString('Sin valor económico estimado', implode(' ', $excess->assumptions));
        $this->assertSame('overstock_without_demand', $excess->constraints[0]['code']);
    }

    public function test_the_score_formula_is_documented_by_its_stored_parameters(): void
    {
        $w = $this->decisionWorld();
        $this->generate($w['tenant']);

        $versions = $this->recs($w['tenant'])->pluck('score_formula_version')->unique();
        $this->assertCount(1, $versions);
        $this->assertSame(ScoreFormula::fromSettings(DecisionSettings::forTenant($w['tenant']))->version(), $versions->first());
    }
}

/** Ayuda de fechas para las aserciones de vencimiento. */
final class CarbonImmutableHelper
{
    public static function endOfDay(string $asOf, int $days): string
    {
        return CarbonImmutable::parse($asOf, 'America/Guayaquil')->addDays($days)->toDateString();
    }
}
