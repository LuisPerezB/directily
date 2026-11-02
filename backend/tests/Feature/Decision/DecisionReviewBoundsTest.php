<?php

declare(strict_types=1);

namespace Tests\Feature\Decision;

use App\Contracts\Decision\RecommendationSink;
use App\Models\DecisionRecommendation;
use App\Models\Tenant;
use App\Services\Decision\DecisionGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Decision\Support\DecisionFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-B (revisión conjunta): demanda del desajuste inventario-demanda con la demanda agregada del pronóstico y sin inventar «cero» (L6), producción
 * acotada y de huella estable (L7) e índices de las consultas reales (M6).
 */
final class DecisionReviewBoundsTest extends TestCase
{
    use DecisionFixtures;
    use RefreshDatabase;

    /** @param array<string, mixed> $decision */
    private function configure(Tenant $tenant, array $decision): void
    {
        $tenant->refresh();
        $tenant->forceFill(['settings' => array_merge($tenant->effectiveSettings(), ['decision' => $decision + (array) ($tenant->settings['decision'] ?? [])])])->save();
    }

    /** @return array<string, mixed> */
    private function generate(Tenant $tenant): array
    {
        $this->configure($tenant, ['contact_source' => 'engine']);

        return app(DecisionGenerator::class)->generate($tenant->refresh(), now('America/Guayaquil')->toImmutable());
    }

    private function product(Tenant $tenant, string $sku): int
    {
        return (int) DB::table('products')->insertGetId([
            'tenant_id' => $tenant->id, 'sku' => $sku, 'name' => "Producto {$sku}", 'unit' => 'unidad', 'is_active' => true, 'current_price' => 8, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Recomendación de exceso (overstock) de la corrida de pronóstico para el producto. */
    private function overstock(array $w, int $forecastRun, int $productId, float $available): void
    {
        DB::table('recommendations')->insert([
            'tenant_id' => $w['tenant']->id, 'forecast_run_id' => $forecastRun, 'branch_id' => $w['cat']['branch'], 'product_id' => $productId, 'type' => 'overstock',
            'inventory_snapshot_date' => $w['asOf'], 'on_hand' => $available, 'reserved' => 0, 'in_transit' => 0, 'available' => $available, 'lead_time_days' => 7,
            'lead_time_source' => 'tenant_default', 'review_period_days' => 7, 'service_level' => 0.95, 'demand_lead_time' => 1, 'demand_lead_time_std' => 1,
            'safety_stock' => 1, 'reorder_point' => 1, 'suggested_qty' => 0, 'unit_cost' => null, 'estimated_investment' => null, 'days_of_cover' => 400.0,
            'stockout_probability' => 0.0, 'stockout_date_estimate' => null, 'risk_level' => 'low', 'status' => 'open', 'assumptions' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Pronóstico agregado diario del producto (nivel producto, todas las sucursales). */
    private function dailyForecast(array $w, int $forecastRun, int $productId, float $perDay, int $days = 30): void
    {
        $rows = [];
        for ($step = 1; $step <= $days; $step++) {
            $rows[] = [
                'tenant_id' => $w['tenant']->id, 'forecast_run_id' => $forecastRun, 'node_key' => 'product:'.$productId, 'level' => 'product', 'branch_id' => null, 'product_id' => $productId,
                'category_id' => null, 'granularity' => 'day', 'target_date' => date('Y-m-d', strtotime($w['asOf']." +{$step} days")), 'period_days' => 1, 'horizon_step' => $step,
                'yhat' => $perDay, 'model_family' => 'lightgbm_global',
            ];
        }
        DB::table('forecast_values')->insert($rows);
    }

    /** @return Collection<int, DecisionRecommendation> */
    private function excess(Tenant $tenant)
    {
        return $this->withinTenant($tenant, fn () => DecisionRecommendation::query()->where('kind', 'inventory_mismatch')->where('policy', 'inventory_mismatch:excess_without_demand')->orderBy('product_id')->get());
    }

    // ───────────────────────── L6: la demanda del exceso es la mayor de las dos vistas y «no se sabe» no es «cero» ─────────────────────────

    public function test_an_overstocked_product_with_aggregate_forecast_demand_is_not_an_excess_without_demand(): void
    {
        $w = $this->decisionWorld();
        $forecast = $this->forecastWithRecommendations($w['tenant'], $w['cat'], $w['asOf']);
        $busy = $this->product($w['tenant'], 'SIN-COMPRADORES-ALTA');
        $this->overstock($w, $forecast, $busy, 500.0);
        $this->dailyForecast($w, $forecast, $busy, 20.0);   // 600 unidades en 30 días frente a 500 disponibles: hay demanda aunque ningún comprador esté identificado

        $this->generate($w['tenant']);

        $this->assertSame([], $this->excess($w['tenant'])->where('product_id', $busy)->all(), 'con demanda agregada alta no hay «exceso sin demanda»');
    }

    public function test_an_overstocked_product_with_little_aggregate_demand_is_an_excess_that_says_where_the_demand_came_from(): void
    {
        $w = $this->decisionWorld();
        $forecast = $this->forecastWithRecommendations($w['tenant'], $w['cat'], $w['asOf']);
        $quiet = $this->product($w['tenant'], 'SIN-COMPRADORES-BAJA');
        $this->overstock($w, $forecast, $quiet, 500.0);
        $this->dailyForecast($w, $forecast, $quiet, 0.5);   // 15 unidades en 30 días < 10 % de 500

        $this->generate($w['tenant']);

        $rec = $this->excess($w['tenant'])->firstWhere('product_id', $quiet);
        $this->assertNotNull($rec);
        $value = $rec->explanation[0]['value'];
        $this->assertEqualsWithDelta(15.0, $value['expected_demand_units'], 1e-6);
        $this->assertEqualsWithDelta(15.0, $value['aggregate_demand_units'], 1e-6);
        $this->assertNull($value['buyers_demand_units'], 'no hay compradores identificados: se dice, no se muestra un cero');
        $this->assertSame($forecast, $rec->source_forecast_run_id);
    }

    public function test_when_neither_buyers_nor_a_forecast_are_known_the_demand_is_unknown_and_nothing_is_recommended(): void
    {
        $w = $this->decisionWorld();
        $forecast = $this->forecastWithRecommendations($w['tenant'], $w['cat'], $w['asOf']);
        $unknown = $this->product($w['tenant'], 'SIN-NINGUNA');
        $this->overstock($w, $forecast, $unknown, 500.0);

        $report = $this->generate($w['tenant']);

        $this->assertSame([], $this->excess($w['tenant'])->where('product_id', $unknown)->all(), 'ausencia de datos de demanda no es demanda cero');
        $this->assertSame('demand_unknown', $report['skipped']['demand_unknown']);
    }

    // ───────────────────────── L7: producción acotada y de huella estable ─────────────────────────

    public function test_customer_level_opportunities_keep_their_identity_when_the_contact_horizons_change(): void
    {
        $w = $this->decisionWorld();
        $this->generate($w['tenant']);
        $customerKinds = ['recovery', 'customer_risk'];
        $snapshot = fn () => DB::table('decision_recommendations')->where('tenant_id', $w['tenant']->id)->whereIn('kind', $customerKinds)->orderBy('id')->get(['id', 'kind', 'customer_id', 'horizon_days', 'idempotency_key'])->all();
        $before = $snapshot();
        $this->assertNotSame([], $before, 'la empresa de prueba tiene clientes inactivos y en riesgo');

        // Cambiar la lista de horizontes de contacto (otro horizonte primero, más horizontes) no es una oportunidad de cliente nueva.
        $this->configure($w['tenant'], ['horizons' => [7, 30, 90]]);
        $report = $this->generate($w['tenant']);

        $this->assertEquals($before, $snapshot(), 'misma huella, mismas filas: sin duplicados');
        $this->assertSame(0, ($report['created']['recovery'] ?? 0) + ($report['created']['customer_risk'] ?? 0));
    }

    public function test_the_customer_level_candidates_are_capped_by_the_per_horizon_limit(): void
    {
        $w = $this->decisionWorld();
        $this->configure($w['tenant'], ['max_contact_per_horizon' => 1]);

        $this->generate($w['tenant']);

        foreach (['recovery', 'customer_risk'] as $kind) {
            $this->assertLessThanOrEqual(1, DB::table('decision_recommendations')->where('tenant_id', $w['tenant']->id)->where('kind', $kind)->count(), "{$kind} acotada por el tope configurado");
        }
    }

    public function test_the_supply_candidates_are_capped_and_keep_the_most_urgent_ones(): void
    {
        $w = $this->decisionWorld();
        $this->forecastWithRecommendations($w['tenant'], $w['cat'], $w['asOf']);
        $this->configure($w['tenant'], ['max_supply_candidates' => 1]);

        $report = $this->generate($w['tenant']);

        $kept = $this->withinTenant($w['tenant'], fn () => DecisionRecommendation::query()->where('kind', 'replenishment')->get());
        $this->assertCount(1, $kept);
        $this->assertSame($w['cat']['products']['p1'], $kept->first()->product_id, 'p1 (riesgo crítico, 0,99) sobre p2 (0,4)');
        $this->assertSame(1, $report['created']['replenishment']);
    }

    public function test_the_supply_query_is_bounded_in_the_database_not_in_memory(): void
    {
        $w = $this->decisionWorld();
        $this->forecastWithRecommendations($w['tenant'], $w['cat'], $w['asOf']);
        $this->configure($w['tenant'], ['max_supply_candidates' => 7]);
        DB::enableQueryLog();

        $this->generate($w['tenant']);

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $pool = $queries->first(fn (string $q) => str_contains($q, 'from "recommendations"') && str_contains($q, 'stockout_probability'));
        $this->assertNotNull($pool);
        $this->assertStringContainsString('limit 14', $pool, 'el conjunto de candidatas se acota en la consulta (2 × max_supply_candidates), no se trae todo para recortarlo después');
    }

    public function test_an_older_cutoff_arriving_later_never_expires_a_newer_recommendation(): void
    {
        $w = $this->decisionWorld();
        $sink = fn (array $override) => $this->withinTenant($w['tenant'], fn () => app(RecommendationSink::class)->propose($this->contactProposal($w, $override)));

        $newer = $sink(['as_of' => $w['asOf']]);
        $older = $sink(['as_of' => date('Y-m-d', strtotime($w['asOf'].' -3 days'))]);   // una corrida atrasada llega después

        $this->assertNotSame($newer->id, $older->id);
        $this->assertSame('suggested', $this->withinTenant($w['tenant'], fn () => DecisionRecommendation::query()->findOrFail($newer->id)->status), 'solo se reemplaza lo de corte igual o anterior');
    }

    public function test_the_supply_cap_follows_the_stockout_probability_order_not_the_size_of_the_risk(): void
    {
        $w = $this->decisionWorld();
        $forecast = $this->forecastWithRecommendations($w['tenant'], $w['cat'], $w['asOf']);   // p1 (0,99) y p2 (0,4)
        $second = $this->product($w['tenant'], 'POOL-ALTA');
        $outsider = $this->product($w['tenant'], 'POOL-FUERA');
        $row = fn (int $productId, float $probability, float $demand, float $available) => DB::table('recommendations')->insert([
            'tenant_id' => $w['tenant']->id, 'forecast_run_id' => $forecast, 'branch_id' => $w['cat']['branch'], 'product_id' => $productId, 'type' => 'stockout_risk',
            'inventory_snapshot_date' => $w['asOf'], 'on_hand' => $available, 'reserved' => 0, 'in_transit' => 0, 'available' => $available, 'lead_time_days' => 7,
            'lead_time_source' => 'tenant_default', 'review_period_days' => 7, 'service_level' => 0.95, 'demand_lead_time' => $demand, 'demand_lead_time_std' => 1,
            'safety_stock' => 1, 'reorder_point' => 1, 'suggested_qty' => 10, 'unit_cost' => 6.0, 'estimated_investment' => 60.0, 'days_of_cover' => 1.0,
            'stockout_probability' => $probability, 'stockout_date_estimate' => $w['asOf'], 'risk_level' => 'high', 'status' => 'open', 'assumptions' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row($second, 0.90, 3.0, 1.0);          // casi sin unidades en riesgo, pero entre las 2 más probables
        $row($outsider, 0.50, 100000.0, 0.0);   // enormes unidades en riesgo, pero la 3.ª más probable: queda fuera del conjunto acotado
        $this->configure($w['tenant'], ['max_supply_candidates' => 1]);

        $this->generate($w['tenant']);

        $kept = $this->withinTenant($w['tenant'], fn () => DecisionRecommendation::query()->where('kind', 'replenishment')->get());
        $this->assertCount(1, $kept);
        $this->assertSame($w['cat']['products']['p1'], $kept->first()->product_id, 'el tope respeta el orden por probabilidad de quiebre: el 3.º no entra aunque valga más');
    }

    // ───────────────────────── M6: los índices de las consultas reales existen y se usan ─────────────────────────

    public function test_the_query_indexes_exist(): void
    {
        $names = DB::table('pg_indexes')->where('tablename', 'decision_recommendations')->pluck('indexname')->all();

        foreach (['scope', 'group', 'work', 'expiry'] as $suffix) {
            $this->assertContains("decision_recommendations_{$suffix}_idx", $names);
        }
        $expiry = (string) DB::table('pg_indexes')->where('indexname', 'decision_recommendations_expiry_idx')->value('indexdef');
        $this->assertStringContainsString('status', $expiry);
        $this->assertStringContainsString('WHERE', $expiry, 'parcial: solo los estados sin acción terminada');
    }

    /** Filas sintéticas (clientes × productos) para que el planificador decida con estadísticas reales, no con una tabla vacía. */
    private function synthetic(int $tenantId, int $customers, int $products, string $asOf): void
    {
        DB::statement("INSERT INTO customers (tenant_id, external_code, customer_type, is_generic, created_at, updated_at) SELECT ?, 'SYN-'||g, 'company', false, now(), now() FROM generate_series(1, ?) g", [$tenantId, $customers]);
        DB::statement("INSERT INTO products (tenant_id, sku, name, unit, is_active, current_price, created_at, updated_at) SELECT ?, 'SYN-'||g, 'Syn '||g, 'unidad', true, 8, now(), now() FROM generate_series(1, ?) g", [$tenantId, $products]);
        DB::statement("INSERT INTO decision_recommendations (tenant_id, kind, as_of, horizon_days, entity_type, customer_id, product_id, score, score_formula_version, priority, status, expires_at, idempotency_key, created_at, updated_at)
            SELECT ?, (ARRAY['contact','recovery','customer_risk'])[1 + (c.id % 3)], ?::date - (c.id % 5)::int, (ARRAY[7,30,90])[1 + (p.id % 3)], 'customer_product', c.id, p.id, random() * 100, 'syn', 3,
                (ARRAY['suggested','assigned','reviewed','approved','executed','rejected','expired'])[1 + ((c.id + p.id) % 7)], now() + interval '10 days',
                md5(c.id || 'x' || p.id) || md5(p.id || 'y' || c.id), now(), now()
            FROM customers c CROSS JOIN products p WHERE c.tenant_id = ? AND c.external_code LIKE 'SYN-%' AND p.tenant_id = ? AND p.sku LIKE 'SYN-%'", [$tenantId, $asOf, $tenantId, $tenantId]);
        DB::statement('ANALYZE decision_recommendations');
    }

    public function test_the_real_queries_use_the_new_indexes(): void
    {
        $w = $this->decisionWorld();
        $tid = $w['tenant']->id;
        $this->synthetic($tid, 100, 100, $w['asOf']);
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = fn (string $sql, array $bindings) => collect(DB::select('EXPLAIN '.$sql, $bindings))->map(fn ($r) => implode(' ', (array) $r))->implode("\n");
        $customer = (int) DB::table('customers')->where('tenant_id', $tid)->where('external_code', 'SYN-3')->value('id');
        $product = (int) DB::table('products')->where('tenant_id', $tid)->where('sku', 'SYN-5')->value('id');

        $this->assertStringContainsString('decision_recommendations_scope_idx', $plan(
            'SELECT id FROM decision_recommendations WHERE tenant_id = ? AND kind = ? AND customer_id = ? AND product_id = ? AND horizon_days = ?', [$tid, 'contact', $customer, $product, 30],
        ));
        $this->assertStringContainsString('decision_recommendations_group_idx', $plan(
            'SELECT id FROM decision_recommendations WHERE tenant_id = ? AND kind = ? AND as_of = ? AND horizon_days = ?', [$tid, 'contact', $w['asOf'], 30],
        ));
        $this->assertStringContainsString('decision_recommendations_expiry_idx', $plan(
            "SELECT id FROM decision_recommendations WHERE tenant_id = ? AND expires_at <= now() AND status IN ('suggested','assigned','reviewed','approved')", [$tid],
        ));
        $this->assertStringContainsString('decision_recommendations_work_idx', $plan(
            "SELECT id FROM decision_recommendations WHERE tenant_id = ? AND status = 'suggested' AND kind = 'contact' ORDER BY score DESC NULLS LAST LIMIT 50", [$tid],
        ));
    }
}
