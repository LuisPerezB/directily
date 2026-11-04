<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\CommercialRole;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Commercial\CommercialSurfaces;
use App\Services\Conversation\Gateway\Scope\MembershipCommercialScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Radar\Support\RadarFixtures;
use Tests\Feature\Security\Support\SecurityFixtures;
use Tests\TestCase;

/**
 * V2.5 · W6-A (revisión M3, M4 y prueba S6; L4): el alcance comercial es un REGISTRO ÚNICO y falla cerrado. Cada ruta que toca clientes, recomendaciones u oportunidades está
 * clasificada (acotada, de equipo o sin datos por cliente); lo no clasificado queda cerrado para un alcance restringido. Compromisos, alias y cambio de código del cliente (con sus
 * escrituras) ya no escapan al vendedor.
 */
final class CommercialSurfacesTest extends TestCase
{
    use RadarFixtures, RefreshDatabase, SecurityFixtures;

    /** @return array{tenant: Tenant, user: User, cat: array, run: int, a: User, b: User, c1: int, c2: int} */
    private function world(): array
    {
        $c = $this->radarCompany(TenantRole::Analyst);
        $this->materializeAll($c);
        $c1 = $c['cat']['customers']['c1'];
        $c2 = $c['cat']['customers']['c2'];
        $a = $this->addUserToTenant($c['tenant'], TenantRole::Analyst);
        $b = $this->addUserToTenant($c['tenant'], TenantRole::Analyst);
        $this->setCommercial($c['tenant'], $a, CommercialRole::Vendedor, ['customer_ids' => [$c1]]);
        $this->setCommercial($c['tenant'], $b, CommercialRole::Vendedor, ['customer_ids' => [$c2]]);

        return $c + compact('a', 'b', 'c1', 'c2');
    }

    private function as(User $user, array $w): static
    {
        return $this->actingAsTenantUser($user, $w['tenant']);
    }

    // ── M4: registro completo y fail-closed ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_m4_every_route_that_touches_customers_or_recommendations_is_classified_and_the_registry_has_no_stale_entries(): void
    {
        $required = [];
        $realKeys = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }
            $key = CommercialSurfaces::keyOf($route);
            $realKeys[$key] = true;
            if (! CommercialSurfaces::requiresClassification($route)) {
                continue;
            }
            $required[] = $key;
            $surface = CommercialSurfaces::classify($route);
            $this->assertNotNull($surface, "{$key}: ruta que toca clientes, recomendaciones u oportunidades SIN clasificar en CommercialSurfaces (queda cerrada para un alcance restringido)");
            $this->assertContains($surface[0], [CommercialSurfaces::SCOPED_LIST, CommercialSurfaces::SCOPED_RECOMMENDATION, CommercialSurfaces::SCOPED_CUSTOMER, CommercialSurfaces::CUSTOMER_FILTER,
                CommercialSurfaces::TEAM_ONLY, CommercialSurfaces::NON_CUSTOMER], $key);
            if (in_array($surface[0], [CommercialSurfaces::SCOPED_RECOMMENDATION, CommercialSurfaces::SCOPED_CUSTOMER], true)) {
                $this->assertContains($surface[1] ?? '', $route->parameterNames(), "{$key}: el parámetro que acota la ruta debe existir");
            }
        }
        $this->assertGreaterThan(30, count($required));
        // Y a la inversa: ninguna entrada del registro apunta a una ruta que ya no existe.
        $this->assertSame([], array_values(array_diff(array_keys(CommercialSurfaces::SURFACES), array_keys($realKeys))), 'entradas del registro sin ruta');
    }

    public function test_m4_a_new_unclassified_customer_route_is_closed_for_restricted_scopes_and_open_for_everyone_else(): void
    {
        $w = $this->world();
        $gerente = $this->addUserToTenant($w['tenant'], TenantRole::Analyst);
        $this->setCommercial($w['tenant'], $gerente, CommercialRole::Gerente);   // sin alcance guardado: toda la empresa
        $servicio = $this->addUserToTenant($w['tenant'], TenantRole::Analyst);
        $this->setCommercial($w['tenant'], $servicio, CommercialRole::Servicio, ['zones' => ['Norte']]);   // con alcance: restringido
        // Una ruta NUEVA que nadie clasificó: toca clientes por su URI y por su parámetro.
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'tenant.ability:data.view'])->prefix('api/v1')->group(function (): void {
            Route::get('customers/{id}/zz-nueva-vista', fn () => response()->json(['ok' => true]));
            Route::get('zz/vista-sin-clientes', fn () => response()->json(['ok' => true]));
        });
        app('router')->getRoutes()->refreshNameLookups();

        foreach ([$w['a'], $w['b'], $servicio] as $restricted) {
            $this->as($restricted, $w)->getJson("/api/v1/customers/{$w['c1']}/zz-nueva-vista")->assertForbidden()->assertJsonPath('code', 'commercial_scope_forbidden');
        }
        $this->as($w['user'], $w)->getJson("/api/v1/customers/{$w['c1']}/zz-nueva-vista")->assertOk();
        $this->as($gerente, $w)->getJson("/api/v1/customers/{$w['c1']}/zz-nueva-vista")->assertOk();
        // Una ruta que no toca clientes ni oportunidades no necesita clasificación.
        $this->as($w['a'], $w)->getJson('/api/v1/zz/vista-sin-clientes')->assertOk();
    }

    // ── M3 / S6: compromisos, alias y cambio de código ───────────────────────────────────────────────────────────────────────────────────────

    public function test_s6_commitments_are_team_only_for_restricted_scopes(): void
    {
        $w = $this->world();
        foreach ([['GET', '/api/v1/commitments'], ['POST', '/api/v1/commitments'], ['GET', '/api/v1/commitments/1'], ['POST', '/api/v1/commitments/1/cancel']] as [$method, $uri]) {
            $this->as($w['a'], $w)->json($method, $uri, [])->assertForbidden()->assertJsonPath('code', 'commercial_scope_forbidden');
        }
        $this->as($w['user'], $w)->getJson('/api/v1/commitments')->assertOk();   // sin rol comercial: igual que antes
    }

    public function test_s6_customer_aliases_and_code_change_follow_the_portfolio_including_writes(): void
    {
        $w = $this->world();
        $api = fn (User $u) => $this->as($u, $w);
        // Un alias del cliente de la cartera de B, creado por quien no tiene rol comercial.
        $foreignAlias = (int) $api($w['user'])->postJson("/api/v1/catalog/customers/{$w['c2']}/aliases", ['source_code' => 'AJENO-0001'])->assertCreated()->json('data.id');
        $codeBefore = DB::table('customers')->where('id', $w['c2'])->value('external_code');
        $aliasesBefore = DB::table('customer_aliases')->count();

        // Lecturas y ESCRITURAS sobre un cliente que NO es de su cartera: 404 y sin cambios.
        $api($w['a'])->getJson("/api/v1/catalog/customers/{$w['c2']}/aliases")->assertNotFound();
        $api($w['a'])->postJson("/api/v1/catalog/customers/{$w['c2']}/aliases", ['source_code' => 'INTRUSO-1'])->assertNotFound();
        $api($w['a'])->deleteJson("/api/v1/catalog/customers/{$w['c2']}/aliases/{$foreignAlias}")->assertNotFound();
        $api($w['a'])->postJson("/api/v1/catalog/customers/{$w['c2']}/code-change", ['old_code' => $codeBefore, 'new_code' => 'CLI-ROBADO', 'effective_from' => now()->toDateString()])->assertNotFound();
        $this->assertSame($aliasesBefore, DB::table('customer_aliases')->count());
        $this->assertSame($codeBefore, DB::table('customers')->where('id', $w['c2'])->value('external_code'), 'el código del cliente ajeno no cambió');

        // Sobre su propio cliente funciona (lectura, alta, baja y cambio de código).
        $api($w['a'])->getJson("/api/v1/catalog/customers/{$w['c1']}/aliases")->assertOk();
        $own = (int) $api($w['a'])->postJson("/api/v1/catalog/customers/{$w['c1']}/aliases", ['source_code' => 'PROPIO-0001'])->assertCreated()->json('data.id');
        $api($w['a'])->deleteJson("/api/v1/catalog/customers/{$w['c1']}/aliases/{$own}")->assertNoContent();
        $oldCode = (string) DB::table('customers')->where('id', $w['c1'])->value('external_code');
        $api($w['a'])->postJson("/api/v1/catalog/customers/{$w['c1']}/code-change", ['old_code' => $oldCode, 'new_code' => 'CLI-0001-NUEVO', 'effective_from' => now()->toDateString()])->assertSuccessful();
        $this->assertSame('CLI-0001-NUEVO', DB::table('customers')->where('id', $w['c1'])->value('external_code'));
        // Quien no tiene rol comercial conserva el acceso a todos.
        $api($w['user'])->getJson("/api/v1/catalog/customers/{$w['c2']}/aliases")->assertOk();
    }

    public function test_s6_customer_core_views_are_scoped_or_team_only_or_free_of_customer_data(): void
    {
        $w = $this->world();
        // De equipo: entrenar/predecir para toda la empresa y la conciliación por cliente.
        $this->as($w['a'], $w)->postJson('/api/v1/customer/runs', [])->assertForbidden()->assertJsonPath('code', 'commercial_scope_forbidden');
        $this->as($w['a'], $w)->getJson('/api/v1/customer/reconciliation')->assertForbidden()->assertJsonPath('code', 'commercial_scope_forbidden');
        // Sin datos por cliente (metadatos de corridas y métricas del modelo): pasan.
        $this->as($w['a'], $w)->getJson('/api/v1/customer/runs')->assertOk();
        $this->as($w['a'], $w)->getJson('/api/v1/customer/evaluation')->assertOk();
        $this->as($w['a'], $w)->getJson('/api/v1/demand/forecasts')->assertOk();
        $this->as($w['a'], $w)->getJson('/api/v1/decisions/settings')->assertOk();
        // Sin rol comercial, todo igual que antes.
        $this->as($w['user'], $w)->getJson('/api/v1/customer/reconciliation')->assertOk();
    }

    // ── L4: el gateway lee el rol comercial igual que la API ─────────────────────────────────────────────────────────────────────────────────

    public function test_l4_the_gateway_scope_resolver_closes_on_blank_or_unexpected_roles_like_the_api(): void
    {
        $w = $this->world();
        $resolver = app(MembershipCommercialScopeResolver::class);
        $user = $this->addUserToTenant($w['tenant'], TenantRole::Admin);
        $resolve = fn () => $resolver->resolve($w['tenant'], $user->refresh(), TenantRole::Admin);

        // Solo NULL es «sin rol comercial»: resuelto y sin restricción.
        $none = $resolve();
        $this->assertTrue($none->resolved);
        $this->assertFalse($none->isRestricted());
        // La cadena vacía, los espacios y las variantes de mayúsculas NO abren el alcance (antes la cadena vacía se leía como «sin rol»): sin resolver = el más restrictivo.
        foreach (['', ' ', "\t", 'Vendedor', ' vendedor', 'director'] as $raw) {
            DB::table('tenant_users')->where('user_id', $user->id)->update(['commercial_role' => $raw]);
            $scope = $resolve();
            $this->assertFalse($scope->resolved, var_export($raw, true));
            $this->assertTrue($scope->isRestricted(), var_export($raw, true));
        }
        // Un rol reconocido con su cartera se resuelve y restringe.
        $this->setCommercial($w['tenant'], $user, CommercialRole::Vendedor, ['customer_ids' => [$w['c1']]]);
        $vendedor = $resolve();
        $this->assertTrue($vendedor->resolved);
        $this->assertTrue($vendedor->isRestricted());
        $this->assertSame('vendedor', $vendedor->role());
    }
}
