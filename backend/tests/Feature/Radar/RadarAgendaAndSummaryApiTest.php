<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Radar\Support\RadarFixtures;
use Tests\TestCase;

/**
 * V2.5 · W5-A: GET /radar/agenda y /radar/summary (V25-AGD-002, -004, -008, -009): agenda por cliente (sin duplicarlo por SKU), por fecha, con pospuestas y
 * estados finales fuera; resumen por horizonte con valor potencial rotulado, sin sumar horizontes y con contribución solo con costo confiable.
 */
class RadarAgendaAndSummaryApiTest extends TestCase
{
    use RadarFixtures;
    use RefreshDatabase;

    /** @return array{tenant: Tenant, user: User, cat: array, run: int} */
    private function company(?callable $beforeMaterializing = null, TenantRole $role = TenantRole::Analyst): array
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

    // ── Agenda ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_agenda_groups_by_customer_and_never_duplicates_a_customer_per_sku(): void
    {
        $c = $this->company();

        $r = $this->api($c)->getJson('/api/v1/radar/agenda?date=2026-10-15&horizon=30')->assertOk();

        $groups = collect($r->json('data'));
        $this->assertCount(2, $groups, 'dos clientes aunque tengan varias relaciones');
        $this->assertSame($groups->pluck('customer.id')->unique()->count(), $groups->count());
        $g1 = $groups->firstWhere('customer.id', $c['cat']['customers']['c1']);
        $g2 = $groups->firstWhere('customer.id', $c['cat']['customers']['c2']);
        $this->assertSame(3, $g1['opportunities_count'], 'c1: dos productos y su riesgo de cliente, en un solo grupo');
        $this->assertCount(3, $g1['opportunities']);
        $this->assertSame(3, $g2['opportunities_count'], 'c2: contacto, recuperación por relación y recuperación de nivel cliente');
        $this->assertSame('CLI-0001', $g1['customer']['code']);
        $this->assertSame(['pendiente' => 3], (array) $g1['states']);
        $this->assertNotEmpty($g1['next_action']);
        $this->assertSame('2026-10-15', $r->json('date'));
        $this->assertSame('radar-agenda-map-v1', $r->json('status_map.version'));
        $this->assertSame(2, $r->json('meta.total'), 'se pagina por cliente');
    }

    public function test_the_agenda_is_paginated_by_customer_with_a_stable_order(): void
    {
        $c = $this->company();
        $api = $this->api($c);

        $p1 = $api->getJson('/api/v1/radar/agenda?date=2026-10-15&per_page=1&page=1')->assertOk();
        $p2 = $api->getJson('/api/v1/radar/agenda?date=2026-10-15&per_page=1&page=2')->assertOk();

        $this->assertSame(2, $p1->json('meta.last_page'));
        $this->assertCount(1, $p1->json('data'));
        $this->assertNotSame($p1->json('data.0.customer.id'), $p2->json('data.0.customer.id'));
        $this->assertSame($p1->json('data.0.customer.id'), $api->getJson('/api/v1/radar/agenda?date=2026-10-15&per_page=1&page=1')->json('data.0.customer.id'));
    }

    public function test_the_agenda_respects_the_date_the_expiry_and_the_closed_states(): void
    {
        $c = $this->company();
        $api = $this->api($c);
        $count = fn (string $date, string $extra = '') => collect($api->getJson("/api/v1/radar/agenda?date={$date}&per_page=100{$extra}")->assertOk()->json('data'))->sum('opportunities_count');

        $this->assertSame(0, $count('2026-09-30'), 'antes del corte de la predicción no hay agenda');
        $this->assertSame(14, $count('2026-10-01'));
        // Vence una oportunidad: ya no está en la agenda después de su expiración.
        DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('kind', 'customer_risk')->update(['expires_at' => '2026-10-03 12:00:00-05']);
        $this->assertSame(14, $count('2026-10-03'), 'el día de su vencimiento aún se gestiona');
        $this->assertSame(13, $count('2026-10-04'));
        // Estados finales fuera; con filtro explícito se ven.
        $first = (int) DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('kind', 'contact')->orderBy('id')->value('id');
        DB::table('decision_recommendations')->where('id', $first)->update(['agenda_status' => 'pedido_recibido', 'status' => 'executed']);
        $this->assertSame(12, $count('2026-10-04'));
        $this->assertSame(1, $count('2026-10-04', '&state=pedido_recibido'));
    }

    public function test_the_agenda_defaults_to_today_in_the_company_timezone(): void
    {
        $c = $this->company();
        $this->travelTo('2026-10-10 03:00:00');   // en UTC es el 10; en Guayaquil (UTC−5) todavía el 9

        $this->assertSame('2026-10-09', $this->api($c)->getJson('/api/v1/radar/agenda')->assertOk()->json('date'));
    }

    public function test_the_agenda_filters_by_seller_and_state_and_hides_the_control_group(): void
    {
        $c = $this->company();
        $seller = $this->addUserToTenant($c['tenant'], TenantRole::Analyst);
        $mine = (int) DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('kind', 'contact')->where('horizon_days', 30)->orderBy('id')->value('id');
        DB::table('decision_recommendations')->where('id', $mine)->update(['assigned_user_id' => $seller->id]);
        $api = $this->api($c);

        $bySeller = $api->getJson("/api/v1/radar/agenda?date=2026-10-15&seller_id={$seller->id}")->assertOk()->json('data');
        $this->assertCount(1, $bySeller);
        $this->assertSame([$mine], collect($bySeller)->pluck('opportunities')->flatten(1)->pluck('id')->all());
        // Un vendedor sin oportunidades asignadas obtiene lista vacía, no la de otros.
        $nobody = $this->addUserToTenant($c['tenant'], TenantRole::Analyst);
        $this->assertSame([], $api->getJson("/api/v1/radar/agenda?date=2026-10-15&seller_id={$nobody->id}")->assertOk()->json('data'));
        // Del grupo de control no se asigna ni se muestra.
        DB::table('decision_recommendations')->where('id', $mine)->update(['experiment_group' => 'control']);
        $this->assertSame([], $api->getJson("/api/v1/radar/agenda?date=2026-10-15&seller_id={$seller->id}")->json('data'));
        $api->getJson('/api/v1/radar/agenda?seller_id='.$c['user']->id.'0000')->assertStatus(422)->assertJsonValidationErrors('seller_id');
        $api->getJson('/api/v1/radar/agenda?date=15-10-2026')->assertStatus(422)->assertJsonValidationErrors('date');
    }

    public function test_the_agenda_is_isolated_by_company_and_open_to_every_existing_role(): void
    {
        $a = $this->company();
        $b = $this->company(null, TenantRole::Viewer);
        DB::table('customers')->where('id', $a['cat']['customers']['c1'])->update(['external_code' => 'SOLO-DE-A']);

        $codes = collect($this->api($b)->getJson('/api/v1/radar/agenda?date=2026-10-15')->assertOk()->json('data'))->pluck('customer.code')->all();

        $this->assertNotContains('SOLO-DE-A', $codes);
        $this->assertContains('CLI-0001', $codes);
        $this->api($b)->getJson('/api/v1/radar/agenda?customer_id='.$a['cat']['customers']['c1'])->assertStatus(422);
    }

    // ── Resumen ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_summary_counts_by_horizon_and_kind_and_labels_the_value_as_potential(): void
    {
        $c = $this->company();

        $r = $this->api($c)->getJson('/api/v1/radar/summary')->assertOk();

        $data = $r->json('data');
        // Relaciones DISTINTAS (tipo, cliente, producto), no filas por horizonte: 3 contactos + recuperación por relación + recuperación y riesgo a nivel de cliente.
        $this->assertSame(6, $data['totals']['opportunities']);
        $this->assertSame(2, $data['totals']['customers']);
        $this->assertSame([7, 30, 90], array_keys($data['by_horizon']), 'claves de horizonte (JSON las serializa como texto)');
        $this->assertSame(4, $data['by_horizon']['7']['opportunities']);
        $h30 = $data['by_horizon']['30'];
        $this->assertSame(6, $h30['opportunities']);
        $this->assertSame(2, $h30['customers']);
        $this->assertEquals(['contact' => 3, 'recovery' => 2, 'customer_risk' => 1], $h30['by_kind']);
        $this->assertSame('potential_unproven', $h30['value']['attribution']['label']);
        $this->assertSame('Oportunidad potencial, atribución no demostrada', $h30['value']['attribution']['text']);
        $this->assertSame('Oportunidad potencial, atribución no demostrada', $data['label']);
        // Sin contribución registrada: no hay cifra y se dice cuántas oportunidades la carecen.
        $this->assertNull($h30['value']['contribution']);
        $this->assertSame(0, $h30['value']['contribution_opportunities']);
        $this->assertSame(4, $h30['value']['contribution_unavailable'], 'las 4 relaciones cliente × producto; las de nivel de cliente no tienen contribución por diseño');
        // «value» suma SOLO el valor pronosticado de las relaciones cliente × producto de ese horizonte; lo de nivel de cliente (caída observada del gasto) va aparte y no se suma.
        $pairs = DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('horizon_days', 30)->where('entity_type', 'customer_product');
        $this->assertEqualsWithDelta((float) (clone $pairs)->sum('expected_gross_value'), $h30['value']['gross_value'], 0.01);
        $this->assertSame((clone $pairs)->count(), $h30['value']['gross_value_opportunities']);
        $this->assertSame('customer_product', $h30['value']['scope']);
        $this->assertSame('forecast_units_x_last_net_price', $h30['value']['basis_code']);
        $this->assertSame(['opportunities' => 2, 'customers' => 2], array_intersect_key($h30['customer_level'], array_flip(['opportunities', 'customers'])));
        $this->assertSame('observed_spend_decline', $h30['customer_level']['basis_code']);
        $observed = (float) DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('horizon_days', 30)->where('entity_type', 'customer')->sum('expected_gross_value');
        $this->assertEqualsWithDelta($observed, $h30['customer_level']['observed_spend_decline'], 0.01);
        $this->assertGreaterThan(0, $observed, 'el escenario trae una caída observada que NO debe sumarse al valor pronosticado');
        $all = (float) DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('horizon_days', 30)->sum('expected_gross_value');
        $this->assertEqualsWithDelta($all - $observed, $h30['value']['gross_value'], 0.01, 'pronóstico = todo − lo observado');
        $this->assertSame($c['run'], $r->json('envelope.run_id'));
        $this->assertSame(2026, (int) substr((string) $data['as_of'], 0, 4));
        $this->assertArrayHasKey('expected_gross_value', $r->json('definitions'));
        $this->assertNotEmpty($data['notes']);
    }

    public function test_the_value_is_never_summed_across_horizons(): void
    {
        $c = $this->company();

        $data = $this->api($c)->getJson('/api/v1/radar/summary')->json('data');

        $perHorizon = collect($data['by_horizon'])->pluck('value.gross_value');
        $this->assertCount(3, $perHorizon);
        $this->assertArrayNotHasKey('gross_value', $data, 'no existe un valor total entre horizontes (se solapan)');
        $this->assertArrayNotHasKey('value', $data);
        $filtered = $this->api($c)->getJson('/api/v1/radar/summary?horizon=30')->json('data');
        $this->assertSame([30], array_keys($filtered['by_horizon']));
        $this->assertSame(6, $filtered['totals']['opportunities']);
    }

    public function test_the_contribution_is_summed_only_over_opportunities_the_decision_engine_valued_with_a_reliable_cost(): void
    {
        $c = $this->company();
        $ids = DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('horizon_days', 30)->where('kind', 'contact')->orderBy('id')->pluck('id')->all();
        DB::table('decision_recommendations')->where('id', $ids[0])->update(['expected_contribution' => 5.5]);
        DB::table('decision_recommendations')->where('id', $ids[1])->update(['expected_contribution' => 7.25]);

        $h30 = $this->api($c)->getJson('/api/v1/radar/summary?horizon=30')->json('data.by_horizon.30');

        $this->assertSame(2, $h30['value']['contribution_opportunities']);
        $this->assertSame(2, $h30['value']['contribution_unavailable']);
        $this->assertEqualsWithDelta(12.75, $h30['value']['contribution'], 1e-9);
    }

    public function test_the_summary_breaks_down_states_inventory_and_control(): void
    {
        $c = $this->company();
        $at = fn (string $kind, ?string $customer, ?string $product) => (int) DB::table('decision_recommendations')->where('tenant_id', $c['tenant']->id)->where('horizon_days', 30)->where('kind', $kind)
            ->when($customer, fn ($q) => $q->where('customer_id', $c['cat']['customers'][$customer]))
            ->when($product, fn ($q) => $q->where('product_id', $c['cat']['products'][$product]), fn ($q) => $q->whereNull('product_id'))->value('id');
        $belowExpected = $at('contact', 'c1', 'p1');
        DB::table('decision_recommendations')->where('id', $belowExpected)->update([
            'availability' => json_encode(['status' => 'known', 'available' => 1.0]),
            'constraints' => json_encode([['code' => 'stock_below_expected_units', 'available_units' => 1.0, 'expected_units' => 4.32]]),
        ]);
        $contacted = $at('recovery', 'c2', 'p1');
        DB::table('decision_recommendations')->where('id', $contacted)->update(['agenda_status' => 'contactado', 'availability' => json_encode(['status' => 'known', 'available' => 50.0])]);
        DB::table('decision_recommendations')->where('id', $at('contact', 'c1', 'p2'))->update(['agenda_status' => 'descartado']);
        DB::table('decision_recommendations')->where('id', $at('contact', 'c2', 'p2'))->update(['experiment_group' => 'control']);

        $data = $this->api($c)->getJson('/api/v1/radar/summary?horizon=30')->assertOk()->json('data');

        // El desglose por estado es POR HORIZONTE, incluye los cerrados (no así la lista de trabajo) y no cuenta el grupo de control.
        $h30 = $data['by_horizon']['30'];
        $this->assertSame(['pendiente' => 3, 'contactado' => 1, 'interesado' => 0, 'pedido_recibido' => 0, 'pospuesto' => 0, 'descartado' => 1], $h30['by_state']);
        $this->assertSame(4, $data['totals']['opportunities'], '6 − descartada − control');
        $this->assertSame(['known' => 1, 'unknown' => 0, 'below_expected' => 1, 'not_applicable' => 2], $h30['inventory']);
        $this->assertSame(array_sum($h30['inventory']), $h30['opportunities']);
        $this->assertArrayNotHasKey('by_state', $data, 'no hay un desglose total entre horizontes (se solapan)');
        $this->assertArrayNotHasKey('inventory', $data);
        // Cuántas se retienen en control NO se informa a un analista (delataría quién está en control)…
        $this->assertArrayNotHasKey('control_withheld', $data);
        // …solo a quien puede aprobar y sin filtros de entidad.
        $admin = $this->actingAsTenantUser($this->addUserToTenant($c['tenant'], TenantRole::Admin), $c['tenant']);
        $this->assertSame(1, $admin->getJson('/api/v1/radar/summary?horizon=30')->json('data.control_withheld'));
        $this->assertArrayNotHasKey('control_withheld', $admin->getJson('/api/v1/radar/summary?horizon=30&customer_id='.$c['cat']['customers']['c2'])->json('data'));
        $this->assertArrayNotHasKey('control_withheld', $admin->getJson('/api/v1/radar/summary?horizon=30&product_id='.$c['cat']['products']['p2'])->json('data'));
    }

    public function test_a_prediction_without_proposed_opportunities_says_how_to_generate_them(): void
    {
        $c = $this->radarCompany(TenantRole::Viewer);

        $r = $this->api($c)->getJson('/api/v1/radar/summary')->assertOk();

        $this->assertSame(0, $r->json('data.totals.opportunities'));
        $this->assertSame([], (array) $r->json('data.by_horizon'));
        $this->assertStringContainsString('POST /radar/opportunities/materialize', $r->json('message'));
        $this->assertSame($c['run'], $r->json('envelope.run_id'));
    }

    public function test_a_company_without_any_prediction_gets_an_explicit_abstention_not_zeros(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Viewer);

        $r = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/radar/summary')->assertOk();

        $this->assertSame('insufficient_data', $r->json('envelope.quality_status'));
        $this->assertNull($r->json('envelope.run_id'));
        $this->assertNull($r->json('message'));
        $this->assertSame(0, $r->json('data.totals.opportunities'));
    }

    public function test_the_summary_is_isolated_by_company(): void
    {
        $a = $this->company();
        $b = $this->radarCompany();   // sin oportunidades propuestas

        $this->assertSame(6, $this->api($a)->getJson('/api/v1/radar/summary')->json('data.totals.opportunities'));
        $this->assertSame(0, $this->api($b)->getJson('/api/v1/radar/summary')->json('data.totals.opportunities'));
    }
}
