<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\CommercialRole;
use App\Enums\TenantRole;
use App\Http\Middleware\EnforceCommercialScope;
use App\Http\Middleware\ResolveTenant;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Commercial\CommercialScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Radar\Support\RadarFixtures;
use Tests\Feature\Security\Support\SecurityFixtures;
use Tests\TestCase;

/**
 * V2.5 · W6-A (ADR-V25-15; §15): roles comerciales gerente / vendedor / servicio. Se SUMAN al rol de acceso. El vendedor ve y toca solo su cartera y lo que le asignaron
 * (Radar, agenda, resumen, decisiones y ficha del comprador), con pruebas NEGATIVAS entre vendedores; el gerente ve el equipo; servicio es de solo lectura.
 * Todo se comprueba por la API, sobre el escenario ficticio del Revenue Radar (14 oportunidades: c1 y c2 con 7 cada una).
 */
final class CommercialRolesTest extends TestCase
{
    use RadarFixtures, RefreshDatabase, SecurityFixtures;

    /** @return array{tenant: Tenant, user: User, cat: array, run: int, a: User, b: User, gerente: User, servicio: User, c1: int, c2: int} */
    private function world(): array
    {
        $c = $this->radarCompany(TenantRole::Analyst);
        $this->materializeAll($c);
        $c1 = $c['cat']['customers']['c1'];
        $c2 = $c['cat']['customers']['c2'];
        $a = $this->addUserToTenant($c['tenant'], TenantRole::Analyst);
        $b = $this->addUserToTenant($c['tenant'], TenantRole::Analyst);
        $gerente = $this->addUserToTenant($c['tenant'], TenantRole::Analyst);
        $servicio = $this->addUserToTenant($c['tenant'], TenantRole::Analyst);
        $this->setCommercial($c['tenant'], $a, CommercialRole::Vendedor, ['customer_ids' => [$c1]]);
        $this->setCommercial($c['tenant'], $b, CommercialRole::Vendedor, ['customer_ids' => [$c2]]);
        $this->setCommercial($c['tenant'], $gerente, CommercialRole::Gerente);
        $this->setCommercial($c['tenant'], $servicio, CommercialRole::Servicio);

        return $c + compact('a', 'b', 'gerente', 'servicio', 'c1', 'c2');
    }

    private function as(User $user, array $w): static
    {
        return $this->actingAsTenantUser($user, $w['tenant']);
    }

    /** @return list<int> clientes distintos de las oportunidades que devuelve la lista */
    private function customersOf(User $user, array $w, string $uri = '/api/v1/radar/opportunities?per_page=100'): array
    {
        $rows = $this->as($user, $w)->getJson($uri)->assertOk()->json('data');

        return array_values(array_unique(array_map(fn (array $r): int => (int) $r['customer']['id'], $rows)));
    }

    /** @return list<int> */
    private function idsOf(User $user, array $w, string $uri = '/api/v1/radar/opportunities?per_page=100'): array
    {
        return array_map(fn (array $r): int => (int) $r['id'], $this->as($user, $w)->getJson($uri)->assertOk()->json('data'));
    }

    private function recId(array $w, int $customer, string $kind = 'contact', int $horizon = 30): int
    {
        return (int) DB::table('decision_recommendations')->where('tenant_id', $w['tenant']->id)->where('customer_id', $customer)->where('kind', $kind)
            ->where('horizon_days', $horizon)->orderBy('id')->value('id');
    }

    private function assign(int $recommendationId, ?User $user): void
    {
        DB::table('decision_recommendations')->where('id', $recommendationId)->update(['assigned_user_id' => $user?->id]);
    }

    // ── El vendedor ve solo su cartera ───────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_vendedor_sees_only_their_portfolio_in_the_list_the_summary_and_the_agenda(): void
    {
        $w = $this->world();
        $all = $this->idsOf($w['user'], $w);
        $this->assertCount(14, $all, 'el analista sin rol comercial ve toda la empresa');
        $this->assertEqualsCanonicalizing([$w['c1'], $w['c2']], $this->customersOf($w['user'], $w));

        $this->assertSame([$w['c1']], $this->customersOf($w['a'], $w));
        $this->assertSame([$w['c2']], $this->customersOf($w['b'], $w));
        $this->assertCount(7, $this->idsOf($w['a'], $w));
        $this->assertCount(7, $this->idsOf($w['b'], $w));
        $this->assertEmpty(array_intersect($this->idsOf($w['a'], $w), $this->idsOf($w['b'], $w)), 'las carteras no se solapan');

        // El resumen se calcula sobre lo visible: ni conteos ni importes incluyen clientes ajenos.
        $own = $this->as($w['a'], $w)->getJson('/api/v1/radar/summary')->assertOk()->json('data');
        $full = $this->as($w['user'], $w)->getJson('/api/v1/radar/summary')->assertOk()->json('data');
        $this->assertSame(1, $own['totals']['customers']);
        $this->assertSame(2, $full['totals']['customers']);
        $this->assertLessThan($full['totals']['opportunities'], $own['totals']['opportunities']);
        $this->assertArrayNotHasKey('control_withheld', $own, 'quien no puede aprobar no ve el conteo de control');

        // La agenda agrupa por cliente: solo el suyo.
        $agenda = $this->as($w['a'], $w)->getJson('/api/v1/radar/agenda?date='.$this->asOf)->assertOk()->json('data');
        $this->assertSame([$w['c1']], array_values(array_unique(array_map(fn ($g) => $g['customer']['id'], $agenda))));
    }

    public function test_filtering_by_another_customer_or_seller_never_widens_the_scope(): void
    {
        $w = $this->world();

        $this->assertSame([], $this->idsOf($w['a'], $w, '/api/v1/radar/opportunities?customer_id='.$w['c2'].'&per_page=100'), 'filtrar por un cliente ajeno no lo expone');
        $this->assertSame([], $this->idsOf($w['a'], $w, '/api/v1/radar/opportunities?seller_id='.$w['b']->id.'&per_page=100'));
        $this->assertSame([], $this->idsOf($w['a'], $w, '/api/v1/radar/opportunities?zone=Norte&per_page=100'));
    }

    public function test_the_gerente_sees_the_whole_team_and_a_regional_scope_narrows_it(): void
    {
        $w = $this->world();
        $this->assertCount(14, $this->idsOf($w['gerente'], $w), 'gerente sin alcance guardado: toda la empresa');
        $this->assertCount(14, $this->idsOf($w['servicio'], $w));

        // Con zona asignada: solo los clientes de su zona, sean de quien sean las asignaciones.
        DB::table('customers')->where('id', $w['c1'])->update(['commercial_zone' => 'Norte']);
        DB::table('customers')->where('id', $w['c2'])->update(['commercial_zone' => 'Sur']);
        $this->setCommercial($w['tenant'], $w['gerente'], CommercialRole::Gerente, ['zones' => ['Norte']]);
        $this->assign($this->recId($w, $w['c1']), $w['b']);   // asignada a otro vendedor: el gerente la ve (es de su zona)
        $this->assertSame([$w['c1']], $this->customersOf($w['gerente'], $w));
        $this->assertContains($this->recId($w, $w['c1']), $this->idsOf($w['gerente'], $w));

        // Un equipo (team_user_ids) suma lo asignado a esas personas aunque el cliente sea de otra zona.
        $this->assign($this->recId($w, $w['c2']), $w['b']);
        $this->setCommercial($w['tenant'], $w['gerente'], CommercialRole::Gerente, ['zones' => ['Norte'], 'team_user_ids' => [$w['b']->id]]);
        $this->assertContains($this->recId($w, $w['c2']), $this->idsOf($w['gerente'], $w));
    }

    public function test_a_portfolio_can_be_defined_by_zone_or_segment_too(): void
    {
        $w = $this->world();
        DB::table('customers')->where('id', $w['c1'])->update(['commercial_zone' => 'Costa']);
        $segment = (int) DB::table('customer_segments')->insertGetId(['tenant_id' => $w['tenant']->id, 'code' => 'MAY', 'name' => 'Mayoristas', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customers')->where('id', $w['c2'])->update(['segment_id' => $segment]);

        $this->setCommercial($w['tenant'], $w['a'], CommercialRole::Vendedor, ['zones' => ['Costa']]);
        $this->setCommercial($w['tenant'], $w['b'], CommercialRole::Vendedor, ['segment_ids' => [$segment]]);

        $this->assertSame([$w['c1']], $this->customersOf($w['a'], $w));
        $this->assertSame([$w['c2']], $this->customersOf($w['b'], $w));
    }

    // ── Asignaciones ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_assignments_decide_visibility_and_never_leak_to_the_other_vendedor(): void
    {
        $w = $this->world();
        $c2Rec = $this->recId($w, $w['c2']);   // cliente de la cartera de B
        $c1Rec = $this->recId($w, $w['c1']);   // cliente de la cartera de A

        // Asignada a A, aunque el cliente no es de su cartera: A la ve (se la dieron); B, que tiene al cliente en su cartera, ya NO.
        $this->assign($c2Rec, $w['a']);
        $this->assertContains($c2Rec, $this->idsOf($w['a'], $w));
        $this->assertNotContains($c2Rec, $this->idsOf($w['b'], $w));
        $this->as($w['b'], $w)->getJson("/api/v1/radar/opportunities/{$c2Rec}")->assertNotFound();
        $this->as($w['a'], $w)->getJson("/api/v1/radar/opportunities/{$c2Rec}")->assertOk();

        // Una sin asignar de la cartera de A pasa a B: A deja de verla aunque su cliente siga en su cartera.
        $this->assign($c1Rec, $w['b']);
        $this->assertNotContains($c1Rec, $this->idsOf($w['a'], $w));
        $this->assertContains($c1Rec, $this->idsOf($w['b'], $w));
        // El analista sin rol comercial y el gerente las ven todas.
        $this->assertContains($c1Rec, $this->idsOf($w['user'], $w));
        $this->assertContains($c2Rec, $this->idsOf($w['gerente'], $w));
    }

    public function test_the_unassigned_in_portfolio_rule_can_be_switched_off_by_configuration(): void
    {
        $w = $this->world();
        config(['radar.seller_scope.unassigned_in_portfolio' => false]);

        $this->assertSame([], $this->idsOf($w['a'], $w), 'sin asignaciones y con la regla apagada nada llega al vendedor');
        $rec = $this->recId($w, $w['c1']);
        $this->assign($rec, $w['a']);
        $this->assertSame([$rec], $this->idsOf($w['a'], $w));
    }

    public function test_a_vendedor_without_portfolio_or_assignments_sees_nothing_and_fails_closed(): void
    {
        $w = $this->world();
        $this->setCommercial($w['tenant'], $w['a'], CommercialRole::Vendedor, []);

        $this->assertSame([], $this->idsOf($w['a'], $w));
        $this->assertSame(0, $this->as($w['a'], $w)->getJson('/api/v1/radar/summary')->assertOk()->json('data.totals.opportunities'));
        $this->as($w['a'], $w)->getJson('/api/v1/decisions?per_page=100')->assertOk()->assertJsonCount(0, 'data');
        $this->as($w['a'], $w)->getJson('/api/v1/decisions/summary')->assertOk()->assertJsonPath('data.awaiting_assignment', 0);
    }

    // ── Rutas por id ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_ids_outside_the_scope_are_not_found_and_nothing_changes(): void
    {
        $w = $this->world();
        $foreign = $this->recId($w, $w['c2']);   // de la cartera de B
        $own = $this->recId($w, $w['c1']);
        $before = DB::table('decision_events')->count();
        $statusBefore = DB::table('decision_recommendations')->where('id', $foreign)->first(['status', 'agenda_status', 'assigned_user_id']);

        $this->as($w['a'], $w)->getJson("/api/v1/radar/opportunities/{$foreign}")->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->as($w['a'], $w)->getJson("/api/v1/decisions/{$foreign}")->assertNotFound();
        $this->as($w['a'], $w)->postJson("/api/v1/radar/opportunities/{$foreign}/feedback", ['state' => 'contactado', 'idempotency_key' => 'k-ajena-1'])->assertNotFound();
        $this->as($w['a'], $w)->postJson("/api/v1/decisions/{$foreign}/transition", ['to_status' => 'assigned', 'idempotency_key' => 'k-ajena-2', 'expected_version' => 1, 'assignee_user_id' => $w['a']->id])->assertNotFound();
        // Un id inexistente responde igual: no hay oráculo para saber si existe en otra cartera.
        $this->as($w['a'], $w)->getJson('/api/v1/radar/opportunities/99999999')->assertNotFound();

        $this->assertSame($before, DB::table('decision_events')->count(), 'ni un evento nuevo');
        $this->assertEquals($statusBefore, DB::table('decision_recommendations')->where('id', $foreign)->first(['status', 'agenda_status', 'assigned_user_id']));

        // Lo propio sí funciona: detalle y seguimiento.
        $this->as($w['a'], $w)->getJson("/api/v1/radar/opportunities/{$own}")->assertOk();
        $this->as($w['a'], $w)->getJson("/api/v1/decisions/{$own}")->assertOk();
        $this->as($w['a'], $w)->postJson("/api/v1/radar/opportunities/{$own}/feedback", ['state' => 'contactado', 'idempotency_key' => 'k-propia-1'])->assertSuccessful();
        $this->assertGreaterThan($before, DB::table('decision_events')->count());
    }

    public function test_the_buyer_file_and_customer_predictions_follow_the_portfolio(): void
    {
        $w = $this->world();
        foreach (['predictions', 'demand', 'products', 'overview'] as $section) {
            $this->as($w['a'], $w)->getJson("/api/v1/customers/{$w['c1']}/{$section}")->assertOk();
            $this->as($w['a'], $w)->getJson("/api/v1/customers/{$w['c2']}/{$section}")->assertNotFound();
        }
        $this->as($w['a'], $w)->getJson("/api/v1/customer/customers/{$w['c2']}/profile")->assertNotFound();
        $this->as($w['a'], $w)->getJson("/api/v1/customer/customers/{$w['c1']}/profile")->assertOk();

        // La lista completa de predicciones de la empresa no se acota por cartera: se exige un cliente visible.
        $this->as($w['a'], $w)->getJson('/api/v1/customer/predictions')->assertForbidden()->assertJsonPath('code', 'commercial_scope_forbidden');
        $this->as($w['a'], $w)->getJson("/api/v1/customer/predictions?customer_id={$w['c2']}")->assertNotFound();
        $this->as($w['a'], $w)->getJson("/api/v1/customer/predictions?customer_id={$w['c1']}")->assertOk();
        // Sin rol comercial: la lista completa sigue disponible.
        $this->as($w['user'], $w)->getJson('/api/v1/customer/predictions')->assertOk();

        // Una oportunidad asignada abre la ficha de un cliente fuera de la cartera (necesita el contexto de lo que le asignaron).
        $this->assign($this->recId($w, $w['c2']), $w['a']);
        $this->as($w['a'], $w)->getJson("/api/v1/customers/{$w['c2']}/overview")->assertOk();
    }

    public function test_decision_lists_and_counts_only_include_what_the_vendedor_can_see(): void
    {
        $w = $this->world();
        $visible = $this->recId($w, $w['c1']);
        $this->assign($visible, $w['a']);

        $list = $this->as($w['a'], $w)->getJson('/api/v1/decisions?per_page=100')->assertOk()->json('data');
        $this->assertNotEmpty($list);
        $this->assertSame([$w['c1']], array_values(array_unique(array_map(fn ($r) => (int) $r['customer']['id'], $list))));
        $this->assertContains($visible, array_map(fn ($r) => (int) $r['id'], $list));
        $this->as($w['a'], $w)->getJson('/api/v1/decisions?mine=1')->assertOk()->assertJsonCount(1, 'data');
        $this->as($w['a'], $w)->getJson('/api/v1/decisions?customer_id='.$w['c2'])->assertOk()->assertJsonCount(0, 'data');

        $total = array_sum((array) $this->as($w['user'], $w)->getJson('/api/v1/decisions/summary')->assertOk()->json('data.by_status'));
        $mine = array_sum((array) $this->as($w['a'], $w)->getJson('/api/v1/decisions/summary')->assertOk()->json('data.by_status'));
        $this->assertSame(14, $total);
        $this->assertSame(7, $mine);
        $this->as($w['gerente'], $w)->getJson('/api/v1/decisions?per_page=100')->assertOk()->assertJsonCount(14, 'data');
    }

    public function test_team_wide_views_are_closed_to_vendedores_until_they_are_scoped(): void
    {
        $w = $this->world();

        foreach (['/api/v1/decisions/outcomes', '/api/v1/decisions/roi'] as $uri) {
            $this->as($w['a'], $w)->getJson($uri)->assertForbidden()->assertJsonPath('code', 'commercial_scope_forbidden');
            $this->assertNotSame(403, $this->as($w['user'], $w)->getJson($uri)->getStatusCode(), $uri.' sigue disponible para el analista');
            $this->assertNotSame(403, $this->as($w['gerente'], $w)->getJson($uri)->getStatusCode(), $uri.' sigue disponible para el gerente');
        }
        $this->as($w['a'], $w)->postJson('/api/v1/radar/opportunities/materialize')->assertForbidden()->assertJsonPath('code', 'commercial_scope_forbidden');
    }

    public function test_the_commercial_scope_is_resolved_per_company(): void
    {
        $w = $this->world();
        // La misma persona es vendedora en la empresa A y analista sin rol comercial en la B.
        [$other, $owner] = $this->createTenantWithUser(TenantRole::Owner);
        $other->users()->attach($w['a']->id, ['role' => TenantRole::Analyst->value]);
        $this->actingAsTenantUser($w['a'], $other)->getJson('/api/v1/tenant')->assertOk()->assertJsonPath('data.commercial_role', null);
        $this->as($w['a'], $w)->getJson('/api/v1/tenant')->assertOk()->assertJsonPath('data.commercial_role', 'vendedor');

        // Y un vendedor de A no opera en una empresa de la que no es miembro: 404, no 403.
        $this->actingAsTenantUser($w['b'], $other)->getJson('/api/v1/radar/opportunities')->assertNotFound()->assertJsonPath('code', 'tenant_not_found');
        $this->assertNotNull($owner);
    }

    // ── Servicio: solo lectura ───────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_servicio_is_read_only_whatever_the_access_role(): void
    {
        $w = $this->world();
        $rec = $this->recId($w, $w['c1']);

        // Lee (vistas de atención al cliente)...
        $this->as($w['servicio'], $w)->getJson('/api/v1/radar/summary')->assertOk();
        $this->as($w['servicio'], $w)->getJson("/api/v1/customers/{$w['c1']}/overview")->assertOk();
        $this->as($w['servicio'], $w)->getJson('/api/v1/products')->assertOk();
        // ...y no escribe nada, aunque su rol de acceso sea analista.
        $this->as($w['servicio'], $w)->postJson("/api/v1/radar/opportunities/{$rec}/feedback", ['state' => 'contactado', 'idempotency_key' => 'k-servicio'])
            ->assertForbidden()->assertJsonPath('code', 'forbidden')->assertJsonPath('required_ability', 'decisions.manage');
        $this->as($w['servicio'], $w)->postJson("/api/v1/decisions/{$rec}/transition", ['to_status' => 'assigned', 'idempotency_key' => 'k-servicio-2', 'expected_version' => 1])->assertForbidden();
        $this->as($w['servicio'], $w)->postJson('/api/v1/products', ['sku' => 'NUEVO-1', 'name' => 'Nuevo'])->assertForbidden()->assertJsonPath('required_ability', 'catalog.manage');
        $this->as($w['servicio'], $w)->postJson('/api/v1/radar/opportunities/materialize')->assertForbidden()->assertJsonPath('required_ability', 'forecast.run');
        $this->assertSame(0, DB::table('decision_events')->where('recommendation_id', $rec)->count());
        $this->assertSame(0, DB::table('products')->where('sku', 'NUEVO-1')->count());

        // Tampoco puede emitirse un token con habilidades de escritura.
        $this->as($w['servicio'], $w)->postJson('/api/v1/auth/tokens', ['tenant' => $w['tenant']->uuid, 'name' => 'Escritura', 'abilities' => ['decisions.manage']])
            ->assertUnprocessable()->assertJsonValidationErrors('abilities');
        $this->as($w['servicio'], $w)->postJson('/api/v1/auth/tokens', ['tenant' => $w['tenant']->uuid, 'name' => 'Lectura', 'abilities' => ['data.view']])->assertCreated();
    }

    public function test_the_ability_mapping_of_access_role_by_commercial_role(): void
    {
        $viewer = TenantRole::Viewer->abilities();
        $expectedFor = fn (TenantRole $access, ?CommercialRole $commercial): array => $commercial === CommercialRole::Servicio ? $viewer : $access->abilities();

        foreach ([TenantRole::Viewer, TenantRole::Analyst, TenantRole::Admin, TenantRole::Owner] as $access) {
            foreach ([null, CommercialRole::Gerente, CommercialRole::Vendedor, CommercialRole::Servicio] as $commercial) {
                if ($commercial === CommercialRole::Servicio && $access === TenantRole::Owner) {
                    continue;   // «servicio» no se asigna a un propietario (se rechaza al asignarlo: ver la prueba de asignación)
                }
                [$tenant, $user] = $this->createTenantWithUser($access);
                $this->setCommercial($tenant, $user, $commercial);
                $data = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/tenant')->assertOk()->json('data');

                $label = $access->value.' + '.($commercial?->value ?? 'sin rol comercial');
                $this->assertEqualsCanonicalizing($expectedFor($access, $commercial), $data['abilities'], $label);
                $this->assertSame($access->value, $data['access_role'], $label.': el rol de acceso guardado no cambia');
                $this->assertSame($commercial?->value, $data['commercial_role'], $label);
                $this->assertSame($commercial === CommercialRole::Servicio ? 'viewer' : $access->value, $data['role'], $label.': rol efectivo');
            }
        }
        // El rol comercial NO concede habilidades que el rol de acceso no tiene (gerente y vendedor no suben a un visor).
        $this->assertSame([], array_diff(CommercialRole::Gerente->capAccessRole(TenantRole::Viewer)->abilities(), $viewer));
        $this->assertSame(TenantRole::Viewer, CommercialRole::Vendedor->capAccessRole(TenantRole::Viewer));
        $this->assertSame(TenantRole::Admin, CommercialRole::Vendedor->capAccessRole(TenantRole::Admin));
    }

    // ── Asignación del rol comercial ─────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_commercial_role_is_assigned_by_users_manage_audited_and_listed(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner);
        $analyst = $this->addUserToTenant($tenant, TenantRole::Analyst);
        $other = $this->addUserToTenant($tenant, TenantRole::Analyst);
        $customer = (int) DB::table('customers')->insertGetId(['tenant_id' => $tenant->id, 'external_code' => 'CLI-1', 'customer_type' => 'company', 'is_generic' => false, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAsTenantUser($owner, $tenant)->putJson("/api/v1/tenant/users/{$analyst->id}/commercial-role", [
            'commercial_role' => 'vendedor', 'commercial_scope' => ['customer_ids' => [$customer], 'zones' => ['Norte'], 'team_user_ids' => [$other->id]],
        ])->assertOk()->assertJsonPath('data.commercial_role', 'vendedor')->assertJsonPath('data.access_role', 'analyst')->assertJsonPath('data.commercial_scope.zones.0', 'Norte');

        $members = collect($this->actingAsTenantUser($owner, $tenant)->getJson('/api/v1/tenant/users')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('vendedor', $members[$analyst->id]['commercial_role']);
        $this->assertSame('analyst', $members[$analyst->id]['role'], 'el rol de acceso no cambió');
        $this->assertSame([$customer], $members[$analyst->id]['commercial_scope']['customer_ids']);
        $this->assertNull($members[$owner->id]['commercial_role']);
        $log = AuditLog::query()->where('action', 'user.commercial_role_changed')->firstOrFail();
        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame('vendedor', $log->metadata['to']);

        // Quitar el rol limpia también el alcance.
        $this->actingAsTenantUser($owner, $tenant)->putJson("/api/v1/tenant/users/{$analyst->id}/commercial-role", ['commercial_role' => null])->assertOk()->assertJsonPath('data.commercial_role', null);
        $this->assertSame('{}', str_replace(' ', '', (string) DB::table('tenant_users')->where('user_id', $analyst->id)->value('commercial_scope')));
    }

    public function test_assignment_rules_validate_tenant_ids_and_protect_owners(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner);
        $admin = $this->addUserToTenant($tenant, TenantRole::Admin);
        $analyst = $this->addUserToTenant($tenant, TenantRole::Analyst);
        [$otherTenant] = $this->createTenantWithUser(TenantRole::Owner);
        $foreignCustomer = (int) DB::table('customers')->insertGetId(['tenant_id' => $otherTenant->id, 'external_code' => 'AJENO', 'customer_type' => 'company', 'is_generic' => false, 'created_at' => now(), 'updated_at' => now()]);
        $foreignUser = $this->addUserToTenant($otherTenant, TenantRole::Analyst);

        $api = fn (User $u) => $this->actingAsTenantUser($u, $tenant);
        // Identificadores de OTRA empresa: 422, nunca un alcance sobre datos ajenos.
        $api($owner)->putJson("/api/v1/tenant/users/{$analyst->id}/commercial-role", ['commercial_role' => 'vendedor', 'commercial_scope' => ['customer_ids' => [$foreignCustomer]]])
            ->assertUnprocessable()->assertJsonValidationErrors('commercial_scope.customer_ids.0');
        $api($owner)->putJson("/api/v1/tenant/users/{$analyst->id}/commercial-role", ['commercial_role' => 'gerente', 'commercial_scope' => ['team_user_ids' => [$foreignUser->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('commercial_scope.team_user_ids.0');
        $api($owner)->putJson("/api/v1/tenant/users/{$analyst->id}/commercial-role", ['commercial_role' => 'director'])->assertUnprocessable()->assertJsonValidationErrors('commercial_role');
        $api($owner)->putJson("/api/v1/tenant/users/{$analyst->id}/commercial-role", ['commercial_role' => 'vendedor', 'commercial_scope' => ['otra' => [1]]])->assertUnprocessable();
        // Una persona de otra empresa no es miembro: 404.
        $api($owner)->putJson("/api/v1/tenant/users/{$foreignUser->id}/commercial-role", ['commercial_role' => 'vendedor'])->assertNotFound();

        // Solo users.manage asigna roles comerciales; un administrador no modifica a un propietario; «servicio» no se asigna a un propietario.
        $api($analyst)->putJson("/api/v1/tenant/users/{$analyst->id}/commercial-role", ['commercial_role' => 'gerente'])->assertForbidden();
        $api($admin)->putJson("/api/v1/tenant/users/{$owner->id}/commercial-role", ['commercial_role' => 'vendedor'])->assertForbidden();
        $api($owner)->putJson("/api/v1/tenant/users/{$owner->id}/commercial-role", ['commercial_role' => 'servicio'])->assertUnprocessable()->assertJsonPath('code', 'commercial_role_not_allowed');
        $api($owner)->putJson("/api/v1/tenant/users/{$owner->id}/commercial-role", ['commercial_role' => 'gerente'])->assertOk();
        $this->assertNull(DB::table('tenant_users')->where('user_id', $analyst->id)->value('commercial_role'));
    }

    // ── Valores inesperados: nunca abren el alcance ──────────────────────────────────────────────────────────────────────────────────────────

    public function test_only_the_three_commercial_roles_or_null_can_be_written(): void
    {
        $w = $this->world();
        $owner = $this->addUserToTenant($w['tenant'], TenantRole::Owner);
        $target = $this->addUserToTenant($w['tenant'], TenantRole::Analyst);
        $put = fn (mixed $role) => $this->as($owner, $w)->putJson("/api/v1/tenant/users/{$target->id}/commercial-role", ['commercial_role' => $role]);

        foreach (['Vendedor', 'GERENTE', 'director', 'servicio al cliente', 'admin', 123, ['vendedor'], true] as $bad) {
            $put($bad)->assertUnprocessable()->assertJsonValidationErrors('commercial_role');
        }
        $this->assertNull(DB::table('tenant_users')->where('user_id', $target->id)->value('commercial_role'), 'ninguna escritura inválida llegó a la base');
        $put('vendedor')->assertOk();
        $put(null)->assertOk()->assertJsonPath('data.commercial_role', null);
        // Sin el campo, tampoco: «quitar» se pide con null explícito, no por omisión.
        $this->as($owner, $w)->putJson("/api/v1/tenant/users/{$target->id}/commercial-role", [])->assertUnprocessable()->assertJsonValidationErrors('commercial_role');

    }

    public function test_an_unexpected_stored_role_fails_closed_never_open(): void
    {
        $w = $this->world();
        // Se simula un dato dañado: nada en la aplicación lo escribe (la API valida), pero una edición a mano o una migración futura podrían dejarlo.
        $logged = [];
        Log::listen(function ($event) use (&$logged): void {
            $logged[] = [$event->level, $event->message, $event->context];
        });
        $broken = $this->addUserToTenant($w['tenant'], TenantRole::Admin);   // administrador: con un rol comercial reconocido o ninguno vería toda la empresa
        $blank = $this->addUserToTenant($w['tenant'], TenantRole::Analyst);
        DB::table('tenant_users')->where('user_id', $broken->id)->update(['commercial_role' => 'Vendedor', 'commercial_scope' => json_encode(['customer_ids' => [$w['c1']], 'zones' => ['Norte-SECRETA']])]);
        DB::table('tenant_users')->where('user_id', $blank->id)->update(['commercial_role' => '']);
        $own = $this->recId($w, $w['c1']);
        $this->assign($own, $broken);   // ni siquiera una asignación explícita abre el alcance

        foreach ([$broken, $blank] as $user) {
            // Nada visible: ni la lista, ni el resumen, ni las decisiones, ni por id.
            $this->assertSame([], $this->idsOf($user, $w), 'la lista del Radar queda vacía');
            $this->assertSame(0, $this->as($user, $w)->getJson('/api/v1/radar/summary')->assertOk()->json('data.totals.opportunities'));
            $this->as($user, $w)->getJson('/api/v1/decisions?per_page=100')->assertOk()->assertJsonCount(0, 'data');
            $this->as($user, $w)->getJson("/api/v1/radar/opportunities/{$own}")->assertNotFound();
            $this->as($user, $w)->getJson("/api/v1/decisions/{$own}")->assertNotFound();
            $this->as($user, $w)->getJson("/api/v1/customers/{$w['c1']}/overview")->assertNotFound();
            $this->as($user, $w)->getJson('/api/v1/decisions/roi')->assertForbidden()->assertJsonPath('code', 'commercial_scope_forbidden');
            // Y en solo lectura: el rol de acceso (admin/analista) no se respeta como si no hubiera rol comercial.
            $tenant = $this->as($user, $w)->getJson('/api/v1/tenant')->assertOk();
            $this->assertEqualsCanonicalizing(TenantRole::Viewer->abilities(), $tenant->json('data.abilities'));
            $this->assertNull($tenant->json('data.commercial_role'));
            $this->as($user, $w)->postJson("/api/v1/radar/opportunities/{$own}/feedback", ['state' => 'contactado', 'idempotency_key' => 'k-dañado'])->assertNotFound();
            $this->as($user, $w)->postJson('/api/v1/products', ['sku' => 'DANADO-1', 'name' => 'No debe crearse'])->assertForbidden()->assertJsonPath('required_ability', 'catalog.manage');
            $this->as($user, $w)->postJson('/api/v1/auth/tokens', ['tenant' => $w['tenant']->uuid, 'name' => 'Escritura', 'abilities' => ['decisions.manage']])->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('decision_events')->where('recommendation_id', $own)->count());

        // La administración ve el dato dañado para poder corregirlo; el resto de la empresa no se afecta.
        $owner = $this->addUserToTenant($w['tenant'], TenantRole::Owner);
        $members = collect($this->as($owner, $w)->getJson('/api/v1/tenant/users')->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($members[$broken->id]['commercial_role_unexpected']);
        $this->assertNull($members[$broken->id]['commercial_role']);
        $this->assertFalse($members[$w['a']->id]['commercial_role_unexpected']);
        $this->assertCount(14, $this->idsOf($w['user'], $w));
        $this->assertCount(6, $this->idsOf($w['a'], $w), 'el vendedor sano sigue viendo su cartera, menos lo que se asignó a otra persona');

        // Se registra una advertencia por cada petición, sin volcar la cartera ni la zona.
        $warnings = array_values(array_filter($logged, fn (array $l): bool => $l[0] === 'warning' && str_contains($l[1], 'Rol comercial inesperado')));
        $this->assertNotEmpty($warnings);
        $this->assertContains($broken->id, array_column(array_column($warnings, 2), 'user_id'));
        $this->assertStringNotContainsString('Norte-SECRETA', json_encode($logged, JSON_THROW_ON_ERROR));
    }

    public function test_the_scope_object_closes_on_anything_that_is_not_a_known_role(): void
    {
        foreach (['Vendedor', 'vendedor ', '', 'director', 'servicio-'] as $raw) {
            $scope = CommercialScope::fromMembership(9, $raw, '{"customer_ids":[1,2],"zones":["Norte"]}');
            $this->assertTrue($scope->isRestricted(), var_export($raw, true));
            $this->assertTrue($scope->unexpectedRole);
            $this->assertNull($scope->role);
            $this->assertSame([], $scope->actorIds());
            $this->assertSame([], [...$scope->customerIds, ...$scope->zones], 'ni la cartera declarada se conserva');
        }
        $this->assertFalse(CommercialScope::fromMembership(9, null, '{}')->isRestricted(), 'solo null es «sin rol comercial»');
        $this->assertTrue(CommercialScope::fromMembership(9, 'vendedor', '{}')->isRestricted());
        $this->assertFalse(CommercialScope::fromMembership(9, 'vendedor', '{}')->unexpectedRole);

        [$none, $noneUnexpected] = CommercialRole::parseStored(null);
        $this->assertSame([null, false], [$none, $noneUnexpected]);
        $this->assertSame([CommercialRole::Gerente, false], CommercialRole::parseStored('gerente'));
        foreach (['Gerente', '', ' gerente', 0, false, ['gerente']] as $raw) {
            $this->assertSame([null, true], CommercialRole::parseStored($raw), var_export($raw, true));
        }
        $this->assertSame(TenantRole::Viewer, CommercialRole::effectiveAccessRole(TenantRole::Owner, null, true));
        $this->assertSame(TenantRole::Owner, CommercialRole::effectiveAccessRole(TenantRole::Owner, null, false));
        $this->assertSame(TenantRole::Viewer, CommercialRole::effectiveAccessRole(TenantRole::Admin, CommercialRole::Servicio, false));
        $this->assertSame(TenantRole::Admin, CommercialRole::effectiveAccessRole(TenantRole::Admin, CommercialRole::Vendedor, false));
    }

    // ── Orden del middleware ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_scope_middleware_runs_after_the_company_is_resolved_on_every_scoped_route(): void
    {
        $routes = app('router')->getRoutes();
        foreach (['radar.opportunities.show', 'radar.opportunities.feedback', 'decisions.show', 'decisions.transition', 'radar.customers.overview', 'customer.customers.profile',
            'customer.predictions.index', 'decisions.outcomes.index', 'decisions.outcomes.roi', 'radar.opportunities.materialize'] as $name) {
            $route = $routes->getByName($name);
            $this->assertNotNull($route, $name);
            $stack = array_map(fn ($m) => is_string($m) ? $m : 'closure', app('router')->gatherRouteMiddleware($route));
            $tenant = array_search(ResolveTenant::class, $stack, true);
            $scope = array_search(EnforceCommercialScope::class, $stack, true);
            $this->assertIsInt($tenant, "{$name}: ResolveTenant en la pila");
            $this->assertIsInt($scope, "{$name}: EnforceCommercialScope en la pila");
            $this->assertGreaterThan($tenant, $scope, "{$name}: el alcance se aplica DESPUÉS de resolver la empresa");
            $bindings = array_search(SubstituteBindings::class, $stack, true);
            $this->assertIsInt($bindings, "{$name}: SubstituteBindings en la pila");
            $this->assertLessThan($bindings, $scope, "{$name}: y ANTES del enlace de modelos (sin oráculo de existencia por id)");
        }
    }
}
