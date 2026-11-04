<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Enums\TenantRole;
use App\Models\DecisionRecommendation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Radar\Support\RadarFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-A: GET /radar/opportunities y /radar/opportunities/{id} (V25-AGD-003, -008, -009, -021): lista de trabajo con filtros, paginación y orden
 * deterministas, aislamiento entre empresas, grupo de control fuera de la vista, y honestidad de cifras (ventana, costo, inventario, atribución).
 */
class RadarOpportunitiesApiTest extends TestCase
{
    use RadarFixtures;
    use RefreshDatabase;

    /** @return array{tenant: Tenant, user: User, cat: array, run: int} */
    private function company(TenantRole $role = TenantRole::Analyst, ?callable $beforeMaterializing = null): array
    {
        $c = $this->radarCompany($role);
        if ($beforeMaterializing !== null) {
            $beforeMaterializing($c);
        }
        $this->materializeAll($c);   // las 12 del Radar + las 2 de nivel cliente del Decision Engine

        return $c;
    }

    private function api(array $c): static
    {
        return $this->actingAsTenantUser($c['user'], $c['tenant']);
    }

    private function recId(array $c, string $kind, int $horizon, string $customer, ?string $product): int
    {
        return (int) DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('kind', $kind)->where('horizon_days', $horizon)
            ->where('customer_id', $c['cat']['customers'][$customer])->when($product !== null, fn ($q) => $q->where('product_id', $c['cat']['products'][$product]), fn ($q) => $q->whereNull('product_id'))->value('id');
    }

    public function test_lists_the_materialized_opportunities_with_the_contract_fields(): void
    {
        $c = $this->company();

        $response = $this->api($c)->getJson('/api/v1/radar/opportunities?horizon=30&kind=contact')->assertOk();

        $rows = collect($response->json('data'));
        $this->assertCount(3, $rows, 'c1 × p1, c1 × p2 y c2 × p2');
        $top = $rows->first(fn ($r) => $r['product']['id'] === $c['cat']['products']['p1']);
        $this->assertSame(['id', 'kind', 'entity_type', 'as_of', 'horizon_days', 'source_run_id', 'customer', 'product', 'state', 'status', 'priority', 'score', 'score_formula_version', 'policy',
            'probability', 'expected_orders', 'quantity_per_order', 'expected_units', 'window', 'model_segment', 'activity', 'value', 'inventory', 'owner_user_id', 'assigned_user',
            'expires_at', 'next_step', 'follow_up'], array_keys($top), 'sin experiment_group: la pertenencia a un experimento solo la ve quien puede aprobar');
        $this->assertSame('contact', $top['kind']);
        $this->assertSame('pendiente', $top['state'], 'sin gestión: estado heredado «pendiente»');
        $this->assertSame('suggested', $top['status']);
        $this->assertEqualsWithDelta(0.65, $top['probability'], 1e-9);
        $this->assertSame(1.35, $top['expected_orders'], 'E(N) servido');
        $this->assertSame(3.2, $top['quantity_per_order']);
        $this->assertSame(4.32, $top['expected_units']);
        $this->assertEquals(['status' => 'estimated', 'p10_days' => 6, 'p50_days' => 11.5, 'p90_days' => 19], $top['window']);
        $this->assertSame('frequency:high', $top['model_segment']);
        $this->assertSame(['id' => $c['cat']['customers']['c1'], 'code' => 'CLI-0001', 'type' => 'company', 'zone' => null, 'segment' => null], $top['customer']);
        $this->assertEqualsWithDelta(34.56, $top['value']['expected_gross_value'], 1e-6);
        $this->assertNull($top['value']['expected_contribution']);
        $this->assertSame('unknown', $top['value']['cost_status'], 'esta recomendación no trae la declaración de costo del Decision Engine');
        $this->assertSame(['label' => 'potential_unproven', 'text' => 'Oportunidad potencial, atribución no demostrada'], $top['value']['attribution']);
        $this->assertSame('unknown', $top['inventory']['status']);
        $this->assertSame('Oportunidad potencial, atribución no demostrada', $response->json('label'));
        $this->assertSame($c['run'], $response->json('envelope.run_id'));
        $this->assertSame('customer', $response->json('envelope.core'));
        $this->assertArrayHasKey('inventory', $response->json('definitions'));
        // Sin PII: el nombre del comprador no existe en la respuesta y los datos de contacto tampoco.
        $this->assertStringNotContainsString('name_encrypted', $response->getContent());
        // La recuperación atrasada trae cuantiles anchos (overdue); el riesgo de cliente no tiene producto ni inventario que evaluar.
        $recovery = collect($this->api($c)->getJson('/api/v1/radar/opportunities?horizon=30&kind=recovery&per_page=100')->json('data'));
        $skuLevel = $recovery->first(fn ($r) => $r['entity_type'] === 'customer_product');
        $this->assertEquals(['status' => 'overdue', 'p10_days' => 5, 'p50_days' => 9, 'p90_days' => 14], $skuLevel['window']);
        $risk = collect($this->api($c)->getJson('/api/v1/radar/opportunities?kind=customer_risk')->json('data'))->first();
        $this->assertNull($risk['product']);
        $this->assertSame('not_applicable', $risk['inventory']['status']);
        $this->assertNull($risk['probability']);
    }

    public function test_cost_and_inventory_are_shown_as_the_decision_engine_declared_them(): void
    {
        $c = $this->company();
        $id = $this->recId($c, 'contact', 30, 'c1', 'p1');
        DB::table('decision_recommendations')->where('id', $id)->update([
            'expected_contribution' => 12.96,
            'uncertainty' => json_encode(['cost' => ['reliable' => true, 'source' => 'sales_lines', 'reasons' => []]]),
            'availability' => json_encode(['status' => 'known', 'available' => 8.0, 'in_transit' => 5.0, 'in_transit_counted_as_available' => false]),
        ]);
        $other = $this->recId($c, 'contact', 30, 'c1', 'p2');
        DB::table('decision_recommendations')->where('id', $other)->update(['uncertainty' => json_encode(['cost' => ['reliable' => false, 'reasons' => ['insufficient_cost_observations']]])]);

        $rows = collect($this->api($c)->getJson('/api/v1/radar/opportunities?horizon=30&kind=contact&per_page=100')->json('data'))->keyBy('id');

        $this->assertSame('reliable', $rows[$id]['value']['cost_status']);
        $this->assertEquals(12.96, $rows[$id]['value']['expected_contribution']);
        $this->assertSame([], $rows[$id]['value']['cost_reasons']);
        $this->assertEquals(8.0, $rows[$id]['inventory']['available']);
        $this->assertEquals(5.0, $rows[$id]['inventory']['in_transit'], 'el tránsito va aparte');
        $this->assertSame('unreliable', $rows[$other]['value']['cost_status']);
        $this->assertSame(['insufficient_cost_observations'], $rows[$other]['value']['cost_reasons']);
        $this->assertNull($rows[$other]['value']['expected_contribution'], 'sin costo confiable no hay contribución');
    }

    public function test_the_default_order_is_by_score_and_is_deterministic(): void
    {
        $c = $this->company();
        $api = $this->api($c);

        $first = collect($api->getJson('/api/v1/radar/opportunities?per_page=100')->json('data'));
        $second = collect($api->getJson('/api/v1/radar/opportunities?per_page=100')->json('data'));

        $this->assertSame($first->pluck('id')->all(), $second->pluck('id')->all());
        // Orden: score descendente (nulos al final), luego valor bruto esperado descendente (nulos al final) y por último id ascendente.
        $key = fn (array $r) => [$r['score'] === null ? -INF : (float) $r['score'], $r['value']['expected_gross_value'] === null ? -INF : (float) $r['value']['expected_gross_value'], -$r['id']];
        $expected = $first->all();
        usort($expected, fn (array $a, array $b) => $key($b) <=> $key($a));
        $this->assertSame(array_column($expected, 'id'), $first->pluck('id')->all());
        $this->assertGreaterThan(1, $first->pluck('score')->unique()->count());
        $this->assertSame(14, $first->count());
    }

    public function test_pagination_walks_every_opportunity_exactly_once(): void
    {
        $c = $this->company();
        $api = $this->api($c);

        $page1 = $api->getJson('/api/v1/radar/opportunities?per_page=4&page=1')->assertOk();
        $this->assertSame(14, $page1->json('meta.total'));
        $this->assertSame(4, $page1->json('meta.last_page'));
        $this->assertSame(4, $page1->json('meta.per_page'));
        $ids = [];
        for ($p = 1; $p <= 4; $p++) {
            foreach ($api->getJson("/api/v1/radar/opportunities?per_page=4&page={$p}")->json('data') as $row) {
                $ids[] = $row['id'];
            }
        }
        $this->assertCount(14, $ids);
        $this->assertCount(14, array_unique($ids), 'sin repetidos entre páginas');
        $api->getJson('/api/v1/radar/opportunities?per_page=101')->assertStatus(422)->assertJsonValidationErrors('per_page');
        $api->getJson('/api/v1/radar/opportunities?page=0')->assertStatus(422)->assertJsonValidationErrors('page');
    }

    public function test_order_by_is_a_whitelist_with_direction(): void
    {
        $c = $this->company();
        $api = $this->api($c);

        $asc = collect($api->getJson('/api/v1/radar/opportunities?order_by=probability&direction=asc&per_page=100')->json('data'))->pluck('probability')->filter(fn ($p) => $p !== null)->values()->all();
        $sorted = $asc;
        sort($sorted);
        $this->assertSame($sorted, $asc);
        $api->getJson('/api/v1/radar/opportunities?order_by=tenant_id')->assertStatus(422)->assertJsonValidationErrors('order_by');
        $api->getJson('/api/v1/radar/opportunities?direction=sideways')->assertStatus(422)->assertJsonValidationErrors('direction');
    }

    public function test_filters_by_customer_product_horizon_kind_zone_segment_category_seller_and_model_segment(): void
    {
        $c = $this->company();
        $tid = $c['tenant']->id;
        $segment = (int) DB::table('customer_segments')->insertGetId(['tenant_id' => $tid, 'code' => 'mayorista', 'name' => 'Mayoristas', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customers')->where('id', $c['cat']['customers']['c1'])->update(['segment_id' => $segment, 'commercial_zone' => 'Norte']);
        DB::table('customers')->where('id', $c['cat']['customers']['c2'])->update(['commercial_zone' => 'Sur']);
        $category = (int) DB::table('product_categories')->insertGetId(['tenant_id' => $tid, 'name' => 'Cementos', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('products')->where('id', $c['cat']['products']['p1'])->update(['category_id' => $category]);
        $seller = $this->addUserToTenant($c['tenant'], TenantRole::Analyst);
        DB::table('decision_recommendations')->where('id', $this->recId($c, 'contact', 30, 'c1', 'p1'))->update(['assigned_user_id' => $seller->id]);
        $api = $this->api($c);
        $ids = fn (string $query) => collect($api->getJson("/api/v1/radar/opportunities?per_page=100&{$query}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertCount(14, $ids(''));
        $this->assertCount(7, $ids('customer_id='.$c['cat']['customers']['c2']), 'c2: contacto + recuperación por relación en 3 horizontes + la recuperación de nivel cliente');
        $this->assertCount(7, $ids('customer_id='.$c['cat']['customers']['c1']), 'c1: 6 contactos + el riesgo de cliente');
        $this->assertCount(6, $ids('product_id='.$c['cat']['products']['p2']));
        $this->assertCount(6, $ids('horizon=30'));
        $this->assertCount(1, $ids('horizon=30&kind=customer_risk'));
        $this->assertCount(4, $ids('kind=recovery'), '3 por relación + 1 de nivel cliente');
        $this->assertCount(7, $ids("segment_id={$segment}"), 'solo c1');
        $this->assertCount(7, $ids('zone=Norte'));
        $this->assertCount(7, $ids('zone=Sur'));
        $this->assertCount(6, $ids("category_id={$category}"), 'solo oportunidades de p1 (sin las de nivel cliente)');
        $this->assertSame([$this->recId($c, 'contact', 30, 'c1', 'p1')], $ids("seller_id={$seller->id}"));
        $this->assertCount(6, $ids('segment=frequency:high'), 'segmento de calibración del modelo (c1)');
        $this->assertCount(14, $ids('as_of=2026-10-01'));
        $this->assertCount(0, $ids('as_of=2026-09-01'));
        $this->assertCount(0, $ids('customer_id='.$c['cat']['customers']['c3']), 'el consumidor final (genérico) nunca tiene oportunidades');
    }

    public function test_filter_values_are_validated_and_ids_of_another_company_are_rejected(): void
    {
        $c = $this->company();
        $other = $this->radarCompany();
        $api = $this->api($c);

        foreach ([
            'customer_id' => $other['cat']['customers']['c1'], 'product_id' => $other['cat']['products']['p1'], 'seller_id' => $other['user']->id,
            'segment' => 'DROP', 'state' => 'inventado', 'status' => 'inventado', 'kind' => 'replenishment', 'horizon' => 60, 'as_of' => 'ayer',
        ] as $field => $value) {
            $api->getJson("/api/v1/radar/opportunities?{$field}={$value}")->assertStatus(422)->assertJsonValidationErrors($field);
        }
    }

    public function test_state_and_status_filters_and_the_default_work_list(): void
    {
        $c = $this->company();
        $closed = $this->recId($c, 'contact', 30, 'c1', 'p1');
        $discarded = $this->recId($c, 'contact', 30, 'c1', 'p2');
        $contacted = $this->recId($c, 'recovery', 30, 'c2', 'p1');
        $rejected = $this->recId($c, 'contact', 30, 'c2', 'p2');
        $expired = $this->recId($c, 'contact', 7, 'c1', 'p1');
        DB::table('decision_recommendations')->where('id', $closed)->update(['agenda_status' => 'pedido_recibido', 'status' => 'executed']);
        DB::table('decision_recommendations')->where('id', $discarded)->update(['agenda_status' => 'descartado']);
        DB::table('decision_recommendations')->where('id', $contacted)->update(['agenda_status' => 'contactado', 'status' => 'in_progress']);
        DB::table('decision_recommendations')->where('id', $rejected)->update(['status' => 'rejected']);
        DB::table('decision_recommendations')->where('id', $expired)->update(['status' => 'expired']);
        $api = $this->api($c);
        $ids = fn (string $query) => collect($api->getJson("/api/v1/radar/opportunities?per_page=100&horizon=30&{$query}")->assertOk()->json('data'))->pluck('id')->all();

        // Lista de trabajo: ni pedido recibido, ni descartado, ni rechazada (de workflow) ni vencida.
        $work = $ids('');
        $this->assertNotContains($closed, $work);
        $this->assertNotContains($discarded, $work);
        $this->assertNotContains($rejected, $work);
        $this->assertContains($contacted, $work);
        $this->assertCount(3, $work, 'quedan la contactada y las dos de nivel cliente');
        // Con filtro de estado heredado se ve exactamente ese estado.
        $this->assertSame([$closed], $ids('state=pedido_recibido'));
        $this->assertSame([$discarded], $ids('state=descartado'));
        $this->assertSame([$contacted], $ids('state=contactado'));
        $pending = $ids('state=pendiente');
        $this->assertCount(3, $pending, 'NULL se lee como pendiente: las dos de nivel cliente y la rechazada por el workflow (el filtro de estado no mira el workflow)');
        $this->assertContains($rejected, $pending);
        // Con filtro de workflow se ve exactamente ese estado.
        $this->assertSame([$rejected], $ids('status=rejected'));
        $this->assertSame([$expired], collect($api->getJson('/api/v1/radar/opportunities?status=expired')->json('data'))->pluck('id')->all());
        $this->assertNotContains($expired, collect($api->getJson('/api/v1/radar/opportunities?per_page=100')->json('data'))->pluck('id')->all(), 'las vencidas no están en la lista por defecto');
    }

    public function test_the_control_group_is_never_shown_or_reachable(): void
    {
        $c = $this->company();
        $control = $this->recId($c, 'contact', 30, 'c1', 'p1');
        DB::table('decision_recommendations')->where('id', $control)->update(['experiment_group' => 'control']);
        $api = $this->api($c);

        $ids = collect($api->getJson('/api/v1/radar/opportunities?per_page=100')->json('data'))->pluck('id')->all();

        $this->assertNotContains($control, $ids);
        $this->assertCount(13, $ids);
        $api->getJson("/api/v1/radar/opportunities/{$control}")->assertNotFound();
        $api->getJson('/api/v1/radar/opportunities?status=suggested&per_page=100')->assertOk()->assertJsonMissing(['id' => $control]);
        $this->assertArrayNotHasKey('control_withheld', $api->getJson('/api/v1/radar/summary')->json('data'), 'un analista no ve cuántas hay en control');
        $this->assertArrayNotHasKey('control_withheld', $api->getJson('/api/v1/radar/summary?customer_id='.$c['cat']['customers']['c1'])->json('data'), 'ni con un filtro de cliente');
    }

    public function test_the_detail_includes_the_explanation_assumptions_and_full_history(): void
    {
        $c = $this->company();
        $id = $this->recId($c, 'contact', 30, 'c1', 'p1');

        $data = $this->api($c)->getJson("/api/v1/radar/opportunities/{$id}")->assertOk()->json();

        $this->assertSame($id, $data['data']['id']);
        $codes = array_column($data['data']['explanation'], 'code');
        $this->assertContains('purchase_probability', $codes);
        $this->assertContains('units_basis', $codes);
        $this->assertNotEmpty($data['data']['assumptions']);
        $this->assertNotEmpty($data['data']['model_versions']);
        $this->assertSame([], $data['data']['history']);
        $this->assertSame(['contactado', 'pedido_recibido', 'pospuesto', 'descartado'], $data['data']['allowed_feedback']);
        $this->assertSame('unknown', $data['data']['value']['cost_status']);
        $this->assertSame('radar-agenda-map-v1', $data['status_map']['version']);
        $this->assertCount(6, $data['status_map']['table']);
        $this->assertSame($c['run'], $data['data']['source']['core_run_id']);
        $this->api($c)->getJson('/api/v1/radar/opportunities/999999')->assertNotFound();
    }

    public function test_the_window_is_never_published_without_a_state_that_backs_it_even_if_the_prediction_row_changes(): void
    {
        $c = $this->company();
        // La predicción de origen pasa a «censored» con cuantiles guardados: la API no los publica.
        DB::table('customer_predictions')->where('core_run_id', $c['run'])->where('customer_id', $c['cat']['customers']['c1'])->where('product_id', $c['cat']['products']['p1'])
            ->where('horizon_days', 30)->update(['window_status' => 'censored']);

        $row = collect($this->api($c)->getJson('/api/v1/radar/opportunities?horizon=30&kind=contact')->json('data'))->first(fn ($r) => $r['product']['id'] === $c['cat']['products']['p1']);

        $this->assertSame('censored', $row['window']['status']);
        $this->assertNull($row['window']['p10_days']);
        $this->assertNull($row['window']['p50_days']);
        $this->assertNull($row['window']['p90_days']);
    }

    public function test_opportunities_of_another_company_are_invisible(): void
    {
        $a = $this->company();
        $b = $this->company();
        $aId = $this->recId($a, 'contact', 30, 'c1', 'p1');

        $this->api($b)->getJson("/api/v1/radar/opportunities/{$aId}")->assertNotFound();
        $bIds = collect($this->api($b)->getJson('/api/v1/radar/opportunities?per_page=100')->json('data'))->pluck('id')->all();
        $this->assertNotContains($aId, $bIds);
        $this->assertCount(14, $bIds);
        $this->assertSame(0, $this->api($b)->getJson('/api/v1/radar/opportunities?customer_id='.$b['cat']['customers']['c3'])->json('meta.total'));
        // La empresa A tampoco ve nada de la B aunque comparta el mismo usuario con otro rol (cabecera de otra empresa donde no es miembro → 404).
        $this->actingAsTenantUser($a['user'], $b['tenant'])->getJson('/api/v1/radar/opportunities')->assertNotFound();
    }

    public function test_every_existing_role_can_read_and_a_missing_header_or_session_is_rejected(): void
    {
        $this->getJson('/api/v1/radar/opportunities')->assertUnauthorized();
        foreach ([TenantRole::Viewer, TenantRole::Analyst, TenantRole::Admin, TenantRole::Owner] as $role) {
            $c = $this->company($role);
            $this->api($c)->getJson('/api/v1/radar/opportunities')->assertOk();
            $this->api($c)->getJson('/api/v1/radar/summary')->assertOk();
            $this->api($c)->getJson('/api/v1/radar/agenda')->assertOk();
        }
        $c = $this->company();
        $this->flushHeaders();   // las cabeceras de las iteraciones anteriores persisten en la prueba
        Sanctum::actingAs($c['user']->refresh(), ['*']);
        $this->getJson('/api/v1/radar/opportunities')->assertStatus(400);
    }

    public function test_a_plan_without_customer_gets_402_and_a_horizon_outside_the_plan_gets_402(): void
    {
        $c = $this->company();
        $c['tenant']->forceFill(['plan_overrides' => ['horizons' => [7, 30]]])->save();
        $this->api($c)->getJson('/api/v1/radar/opportunities?horizon=90')->assertStatus(402);
        $this->api($c)->getJson('/api/v1/radar/summary?horizon=90')->assertStatus(402);

        $c['tenant']->forceFill(['plan_overrides' => ['features' => ['customer' => false]]])->save();
        foreach (['/api/v1/radar/opportunities', '/api/v1/radar/summary', '/api/v1/radar/agenda'] as $path) {
            $this->api($c)->getJson($path)->assertStatus(402)->assertJsonPath('limit', 'features.customer');
        }
    }

    public function test_an_empty_company_returns_an_empty_page_with_an_explicit_missing_envelope(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Viewer);

        $r = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/radar/opportunities')->assertOk();

        $this->assertSame([], $r->json('data'));
        $this->assertSame(0, $r->json('meta.total'));
        $this->assertSame('insufficient_data', $r->json('envelope.quality_status'));
        $this->assertSame(0, DecisionRecommendation::withoutGlobalScopes()->count());
    }
}
