<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\DataSource;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Tokens\TokenService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Security\Support\SecurityFixtures;
use Tests\TestCase;

/**
 * V2.5 · W6-A (ADR-V25-15): tokens de API con alcance mínimo y caducidad, y revocación con efecto INMEDIATO (Sanctum relee el token de la base en cada petición).
 * Se usan tokens Bearer reales (no `Sanctum::actingAs`) para demostrar el 401 inmediato tras revocar o vencer.
 */
final class TokenLifecycleTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase, SecurityFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
    }

    /** Llama con un token Bearer real, sin rastro de la autenticación de la llamada anterior. */
    private function bearer(string $token, string $method, string $uri, array $body = [], ?Tenant $tenant = null): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $call = $this->withToken($token);
        if ($tenant !== null) {
            $call = $call->withHeader('X-Tenant-ID', $tenant->uuid);
        }

        return $call->json($method, $uri, $body);
    }

    /** Emite un token personal por la API como la persona (con sesión de prueba) y devuelve [texto, id]. @return array{0: string, 1: int} */
    private function mint(User $user, Tenant $tenant, array $abilities, array $extra = []): array
    {
        $r = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'Integración', 'abilities' => $abilities] + $extra)->assertCreated();

        return [(string) $r->json('data.token'), (int) $r->json('data.id')];
    }

    public function test_a_token_is_issued_with_explicit_minimal_abilities_a_default_expiry_and_is_shown_once(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);

        $r = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'Lector de ventas', 'abilities' => ['data.view', 'forecast.view']])->assertCreated();
        $this->assertNotEmpty($r->json('data.token'));
        $this->assertSame(['data.view', 'forecast.view'], $r->json('data.abilities'));
        $this->assertSame($tenant->uuid, $r->json('data.tenant'));
        $this->assertSame(now()->addDays(30)->toIso8601String(), Carbon::parse($r->json('data.expires_at'))->toIso8601String(), 'caducidad por defecto: security.tokens.default_ttl_days');

        $row = PersonalAccessToken::query()->findOrFail($r->json('data.id'));
        $this->assertSame($tenant->id, (int) $row->tenant_id, 'atado a la empresa');
        $this->assertNotSame($r->json('data.token'), $row->token, 'solo se guarda el hash');
        // El listado no vuelve a mostrar el token.
        $list = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/auth/tokens')->assertOk();
        $this->assertStringNotContainsString((string) $r->json('data.token'), $list->getContent());
        $this->assertStringNotContainsString($row->token, $list->getContent());
        $list->assertJsonPath('data.0.kind', 'personal')->assertJsonPath('data.0.name', 'Lector de ventas')->assertJsonPath('data.0.expired', false);
        $this->assertSame(1, AuditLog::query()->where('action', 'token.created')->count());
    }

    public function test_the_expiry_is_configurable_and_capped(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);
        $api = fn () => $this->actingAsTenantUser($user, $tenant);

        $api()->postJson('/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'Corto', 'abilities' => ['data.view'], 'expires_in_days' => 7])->assertCreated()
            ->assertJsonPath('data.expires_at', now()->addDays(7)->toIso8601String());
        $api()->postJson('/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'Eterno', 'abilities' => ['data.view'], 'expires_in_days' => 366])->assertUnprocessable()->assertJsonValidationErrors('expires_in_days');
        $api()->postJson('/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'Cero', 'abilities' => ['data.view'], 'expires_in_days' => 0])->assertUnprocessable();
        config(['security.tokens.default_ttl_days' => 3]);
        $api()->postJson('/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'Por defecto', 'abilities' => ['data.view']])->assertCreated()
            ->assertJsonPath('data.expires_at', now()->addDays(3)->toIso8601String());
    }

    public function test_abilities_must_be_explicit_and_within_the_role(): void
    {
        [$tenant, $viewer] = $this->createTenantWithUser(TenantRole::Viewer);
        $api = fn () => $this->actingAsTenantUser($viewer, $tenant);

        $api()->postJson('/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'Todo', 'abilities' => ['*']])->assertUnprocessable()->assertJsonValidationErrors(['abilities.0']);
        $api()->postJson('/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'Sin alcance'])->assertUnprocessable()->assertJsonValidationErrors('abilities');
        $api()->postJson('/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'Vacío', 'abilities' => []])->assertUnprocessable();
        // Un visor no puede emitir un token con más habilidades que las suyas.
        $api()->postJson('/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'Más', 'abilities' => ['data.view', 'imports.manage']])->assertUnprocessable()->assertJsonValidationErrors('abilities');
        $api()->postJson('/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'Inventada', 'abilities' => ['no.existe']])->assertUnprocessable();
        $api()->postJson('/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'Ok', 'abilities' => ['data.view']])->assertCreated();
        $this->assertSame(1, PersonalAccessToken::query()->count());
    }

    public function test_a_token_cannot_be_issued_for_a_company_the_person_does_not_belong_to(): void
    {
        [$tenantA, $user] = $this->createTenantWithUser(TenantRole::Owner);
        [$tenantB] = $this->createTenantWithUser(TenantRole::Owner);

        $this->actingAsTenantUser($user, $tenantA)->postJson('/api/v1/auth/tokens', ['tenant' => $tenantB->uuid, 'name' => 'Ajena', 'abilities' => ['data.view']])->assertNotFound();
        $this->actingAsTenantUser($user, $tenantA)->postJson('/api/v1/auth/tokens', ['tenant' => '11111111-1111-4111-8111-111111111111', 'name' => 'Inexistente', 'abilities' => ['data.view']])->assertNotFound();
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_the_token_only_does_what_it_was_granted_and_only_in_its_company(): void
    {
        [$tenantA, $user] = $this->createTenantWithUser(TenantRole::Owner);
        $tenantB = Tenant::query()->create(['name' => 'Segunda', 'sector' => 'ferreteria']);
        $tenantB->users()->attach($user->id, ['role' => TenantRole::Owner->value]);
        [$token] = $this->mint($user, $tenantA, ['data.view']);

        $this->bearer($token, 'GET', '/api/v1/products', [], $tenantA)->assertOk();
        // Sin la habilidad, el rol de propietario no basta: el token manda.
        $this->bearer($token, 'POST', '/api/v1/products', ['sku' => 'X1', 'name' => 'Producto'], $tenantA)->assertForbidden()->assertJsonPath('required_ability', 'catalog.manage');
        // Atado a la empresa A: el encabezado de B se ignora (la empresa es la del token).
        $this->bearer($token, 'GET', '/api/v1/tenant', [], $tenantB)->assertOk()->assertJsonPath('data.uuid', $tenantA->uuid);
    }

    public function test_revoking_a_token_takes_effect_on_the_very_next_request(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);
        [$token, $id] = $this->mint($user, $tenant, ['data.view']);
        $this->bearer($token, 'GET', '/api/v1/products', [], $tenant)->assertOk();

        $this->actingAsTenantUser($user, $tenant)->deleteJson("/api/v1/auth/tokens/{$id}")->assertNoContent();

        $this->bearer($token, 'GET', '/api/v1/products', [], $tenant)->assertUnauthorized();
        $this->assertNull(PersonalAccessToken::query()->find($id));
        $this->assertSame(1, AuditLog::query()->where('action', 'token.revoked')->count());
        // Revocar otra vez: ya no existe.
        $this->actingAsTenantUser($user, $tenant)->deleteJson("/api/v1/auth/tokens/{$id}")->assertNotFound();
    }

    public function test_an_expired_token_stops_working_without_being_revoked(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);
        [$token, $id] = $this->mint($user, $tenant, ['data.view'], ['expires_in_days' => 2]);

        $this->travel(47)->hours();
        $this->bearer($token, 'GET', '/api/v1/products', [], $tenant)->assertOk();
        $this->travel(2)->hours();
        $this->bearer($token, 'GET', '/api/v1/products', [], $tenant)->assertUnauthorized();
        $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/auth/tokens')->assertJsonPath('data.0.expired', true);
        $this->assertNotNull(PersonalAccessToken::query()->find($id), 'vencido pero listado (para que se pueda revocar)');
    }

    public function test_a_person_only_manages_their_own_tokens(): void
    {
        [$tenant, $alice] = $this->createTenantWithUser(TenantRole::Analyst);
        $bob = $this->addUserToTenant($tenant, TenantRole::Analyst);
        [$bobToken, $bobId] = $this->mint($bob, $tenant, ['data.view']);

        $this->actingAsTenantUser($alice, $tenant)->deleteJson("/api/v1/auth/tokens/{$bobId}")->assertNotFound();
        $this->bearer($bobToken, 'GET', '/api/v1/products', [], $tenant)->assertOk();
        $this->assertSame([], $this->actingAsTenantUser($alice, $tenant)->getJson('/api/v1/auth/tokens')->json('data'));
    }

    public function test_revoke_others_keeps_the_current_login_token_and_the_connectors(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner);
        $keep = $user->createToken('api-login', ['*'])->plainTextToken;
        $otherLogin = $user->createToken('api-login', ['*'])->plainTextToken;
        [$personal] = $this->mint($user, $tenant, ['data.view']);
        [$personal2] = $this->mint($user, $tenant, ['data.view']);
        $source = $this->withinTenant($tenant, fn () => DataSource::query()->create(['type' => 'rest_api', 'connector_key' => 'generic_rest', 'name' => 'F', 'config' => []]));
        $connector = $this->actingAsTenantUser($user, $tenant)->postJson("/api/v1/data-sources/{$source->id}/tokens")->assertCreated();

        $r = $this->bearer($keep, 'POST', '/api/v1/auth/tokens/revoke-others')->assertOk();
        $this->assertSame(3, $r->json('data.revoked'), 'el otro inicio de sesión y los dos personales');
        $this->bearer($keep, 'GET', '/api/v1/auth/me')->assertOk();
        foreach ([$otherLogin, $personal, $personal2] as $dead) {
            $this->bearer($dead, 'GET', '/api/v1/products', [], $tenant)->assertUnauthorized();
        }
        $this->bearer((string) $connector->json('data.token'), 'GET', '/api/v1/products', [], $tenant)->assertOk();

        $this->bearer($keep, 'POST', '/api/v1/auth/tokens/revoke-others', ['include_connectors' => true])->assertOk()->assertJsonPath('data.revoked', 1);
        $this->bearer((string) $connector->json('data.token'), 'GET', '/api/v1/products', [], $tenant)->assertUnauthorized();
        $this->bearer($keep, 'GET', '/api/v1/auth/me')->assertOk();
    }

    public function test_the_login_token_follows_the_configured_default_expiry(): void
    {
        $user = User::factory()->create(['password' => 'ClaveSegura123']);
        config(['security.tokens.default_ttl_days' => 5]);

        $r = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'ClaveSegura123'])->assertOk();
        $this->assertSame(now()->addDays(5)->toIso8601String(), $r->json('data.expires_at'));
        $this->assertSame(now()->addDays(5)->toDateTimeString(), PersonalAccessToken::query()->firstOrFail()->expires_at->toDateTimeString());
    }

    public function test_the_connector_token_follows_the_configured_default_expiry(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner);
        $source = $this->withinTenant($tenant, fn () => DataSource::query()->create(['type' => 'rest_api', 'connector_key' => 'generic_rest', 'name' => 'F', 'config' => []]));

        $this->actingAsTenantUser($owner, $tenant)->postJson("/api/v1/data-sources/{$source->id}/tokens")->assertCreated()->assertJsonPath('data.expires_at', now()->addDays(365)->toIso8601String());
        config(['security.tokens.connector_default_ttl_days' => 30]);
        $this->actingAsTenantUser($owner, $tenant)->postJson("/api/v1/data-sources/{$source->id}/tokens")->assertCreated()->assertJsonPath('data.expires_at', now()->addDays(30)->toIso8601String());
        $this->actingAsTenantUser($owner, $tenant)->postJson("/api/v1/data-sources/{$source->id}/tokens", ['expires_in_days' => 10])->assertCreated()->assertJsonPath('data.expires_at', now()->addDays(10)->toIso8601String());
    }

    // ── Revocación por la administración de la empresa ───────────────────────────────────────────────────────────────────────────────────────

    public function test_the_company_admin_lists_and_revokes_tenant_tokens_immediately(): void
    {
        [$tenant, $admin] = $this->createTenantWithUser(TenantRole::Admin);
        $analyst = $this->addUserToTenant($tenant, TenantRole::Analyst);
        [$token, $id] = $this->mint($analyst, $tenant, ['data.view']);
        $this->bearer($token, 'GET', '/api/v1/products', [], $tenant)->assertOk();

        $list = $this->actingAsTenantUser($admin, $tenant)->getJson('/api/v1/tenant/tokens')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $id);
        $this->assertSame($analyst->email, $row['user']['email']);
        $this->assertSame(['data.view'], $row['abilities']);
        $this->assertSame('personal', $row['kind']);
        $this->assertStringNotContainsString($token, $list->getContent());

        $this->actingAsTenantUser($admin, $tenant)->deleteJson("/api/v1/tenant/tokens/{$id}")->assertNoContent();
        $this->bearer($token, 'GET', '/api/v1/products', [], $tenant)->assertUnauthorized();
        $log = AuditLog::query()->where('action', 'token.revoked')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('admin', $log->metadata['by']);
    }

    public function test_tenant_token_administration_never_crosses_companies_and_needs_users_manage(): void
    {
        [$tenantA, $adminA] = $this->createTenantWithUser(TenantRole::Admin);
        [$tenantB, $adminB] = $this->createTenantWithUser(TenantRole::Admin);
        $analystA = $this->addUserToTenant($tenantA, TenantRole::Analyst);
        [$tokenA, $idA] = $this->mint($analystA, $tenantA, ['data.view']);

        // El administrador de B no ve ni revoca los tokens de A.
        $this->assertSame([], $this->actingAsTenantUser($adminB, $tenantB)->getJson('/api/v1/tenant/tokens')->json('data'));
        $this->actingAsTenantUser($adminB, $tenantB)->deleteJson("/api/v1/tenant/tokens/{$idA}")->assertNotFound();
        $this->bearer($tokenA, 'GET', '/api/v1/products', [], $tenantA)->assertOk();
        // Con el encabezado de A, el administrador de B no es miembro: 404.
        $this->actingAsTenantUser($adminB, $tenantA)->getJson('/api/v1/tenant/tokens')->assertNotFound()->assertJsonPath('code', 'tenant_not_found');
        // Sin users.manage (analista): 403.
        $this->actingAsTenantUser($analystA, $tenantA)->getJson('/api/v1/tenant/tokens')->assertForbidden()->assertJsonPath('required_ability', 'users.manage');
        $this->actingAsTenantUser($analystA, $tenantA)->deleteJson("/api/v1/tenant/tokens/{$idA}")->assertForbidden();
        // Un token de inicio de sesión (sin empresa) no es administrable por la empresa.
        $login = $analystA->createToken('api-login', ['*']);
        $this->actingAsTenantUser($adminA, $tenantA)->deleteJson('/api/v1/tenant/tokens/'.$login->accessToken->id)->assertNotFound();
    }

    public function test_removing_a_member_revokes_their_company_tokens(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner);
        $analyst = $this->addUserToTenant($tenant, TenantRole::Analyst);
        [$token] = $this->mint($analyst, $tenant, ['data.view']);

        $this->actingAsTenantUser($owner, $tenant)->deleteJson("/api/v1/tenant/users/{$analyst->id}")->assertNoContent();
        $this->bearer($token, 'GET', '/api/v1/products', [], $tenant)->assertUnauthorized();
    }

    public function test_a_demoted_member_cannot_use_abilities_the_token_still_lists(): void
    {
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner);
        $analyst = $this->addUserToTenant($tenant, TenantRole::Analyst);
        [$token] = $this->mint($analyst, $tenant, ['data.view', 'catalog.manage']);
        $this->bearer($token, 'POST', '/api/v1/products', ['sku' => 'D-1', 'name' => 'Producto'], $tenant)->assertCreated();

        DB::table('tenant_users')->where('user_id', $analyst->id)->update(['role' => TenantRole::Viewer->value]);
        $this->bearer($token, 'POST', '/api/v1/products', ['sku' => 'D-2', 'name' => 'Producto'], $tenant)->assertForbidden()->assertJsonPath('required_ability', 'catalog.manage');
        $this->assertNotNull($owner);
    }

    public function test_the_number_of_active_personal_tokens_is_bounded(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);
        for ($i = 0; $i < TokenService::MAX_PERSONAL_TOKENS; $i++) {
            $user->createToken('user:t'.$i, ['data.view'])->accessToken->forceFill(['tenant_id' => $tenant->id, 'expires_at' => now()->addDays(5)])->save();
        }

        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'Uno más', 'abilities' => ['data.view']])->assertUnprocessable()->assertJsonValidationErrors('name');
    }
}
