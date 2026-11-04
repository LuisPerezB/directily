<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\TenantRole;
use App\Http\Middleware\RequireFullLogin;
use App\Models\DataSource;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Tokens\TokenScopeException;
use App\Services\Auth\Tokens\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Security\Support\SecurityFixtures;
use Tests\TestCase;

/**
 * V2.5 · W6-A (revisión HIGH-1): las habilidades y la empresa de un token NO eran una frontera en las rutas de CUENTA (tokens propios, segundo factor, crear y listar
 * empresas, cambio de contraseña). Un token de solo lectura o de conector podía emitir un token con `settings.manage`, para OTRA empresa, activar su propio autenticador o revocar
 * los demás tokens de la persona. Decisión: esas rutas exigen una sesión o un token de INICIO DE SESIÓN completo (`account.full`); y un token nunca emite otro con más alcance que el
 * suyo ni para otra empresa. T1–T9: token restringido, de conector y entre empresas, por familia de rutas.
 */
final class AccountScopeTokensTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase, SecurityFixtures;

    private function bearer(string $token, string $method, string $uri, array $body = [], ?Tenant $tenant = null): TestResponse
    {
        Auth::forgetGuards();
        $this->flushHeaders();
        $call = $this->withToken($token);
        if ($tenant !== null) {
            $call = $call->withHeader('X-Tenant-ID', $tenant->uuid);
        }

        return $call->json($method, $uri, $body);
    }

    /** Un token de inicio de sesión completo, como lo emite POST /auth/login a un cliente API. */
    private function loginToken(User $user): string
    {
        Auth::forgetGuards();
        $this->flushHeaders();

        return (string) $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->json('data.token');
    }

    /** Un token personal restringido (solo lectura) y uno de conector de una persona. @return array{0: string, 1: string} */
    private function restrictedAndConnector(User $user, Tenant $tenant): array
    {
        $login = $this->loginToken($user);
        $readOnly = (string) $this->bearer($login, 'POST', '/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'bi-solo-lectura', 'abilities' => ['data.view']])->assertCreated()->json('data.token');
        $source = $this->withinTenant($tenant, fn () => DataSource::query()->create(['type' => 'rest_api', 'connector_key' => 'generic_rest', 'name' => 'ERP', 'config' => []]));
        $connector = (string) $this->bearer($login, 'POST', "/api/v1/data-sources/{$source->id}/tokens", [], $tenant)->assertCreated()->json('data.token');

        return [$readOnly, $connector];
    }

    private function forbiddenAsAccount(TestResponse $response): void
    {
        $response->assertForbidden()->assertJsonPath('code', 'token_scope_insufficient');
    }

    // ── T1 y T2: emisión de tokens ───────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_t1_a_read_only_token_cannot_mint_a_token_with_more_abilities(): void
    {
        [$tenant, $admin] = $this->createTenantWithUser(TenantRole::Admin);
        [$readOnly] = $this->restrictedAndConnector($admin, $tenant);
        $before = PersonalAccessToken::query()->count();

        // Es de solo lectura de verdad...
        $this->bearer($readOnly, 'PATCH', '/api/v1/tenant', ['name' => 'Hackeada'], $tenant)->assertForbidden();
        // ...y no puede emitir otro con las habilidades del administrador.
        foreach ([['settings.manage', 'users.manage', 'datasources.manage', 'data.export'], ['data.view'], ['data.view', 'forecast.view']] as $abilities) {
            $this->forbiddenAsAccount($this->bearer($readOnly, 'POST', '/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'escalado', 'abilities' => $abilities]));
        }
        $this->assertSame($before, PersonalAccessToken::query()->count(), 'no se emitió ningún token');
        $this->forbiddenAsAccount($this->bearer($readOnly, 'GET', '/api/v1/auth/tokens'));
    }

    public function test_t2_a_connector_token_cannot_mint_tokens_or_list_them(): void
    {
        [$tenant, $admin] = $this->createTenantWithUser(TenantRole::Admin);
        [, $connector] = $this->restrictedAndConnector($admin, $tenant);
        $before = PersonalAccessToken::query()->count();

        $this->forbiddenAsAccount($this->bearer($connector, 'POST', '/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'desde-conector', 'abilities' => ['users.manage', 'settings.manage']]));
        $this->forbiddenAsAccount($this->bearer($connector, 'POST', '/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'igual', 'abilities' => ['imports.manage']]));
        $this->forbiddenAsAccount($this->bearer($connector, 'GET', '/api/v1/auth/tokens'));
        $this->forbiddenAsAccount($this->bearer($connector, 'DELETE', '/api/v1/auth/tokens/'.PersonalAccessToken::query()->value('id')));
        $this->assertSame($before, PersonalAccessToken::query()->count());
        // Lo suyo, la ingestión, sigue funcionando.
        $this->bearer($connector, 'GET', '/api/v1/products', [], $tenant)->assertOk();
    }

    // ── T3: segundo factor y revocación de los tokens de la persona ─────────────────────────────────────────────────────────────────────────

    public function test_t3_a_limited_token_cannot_enrol_mfa_nor_revoke_the_owners_tokens(): void
    {
        [$tenant, $admin] = $this->createTenantWithUser(TenantRole::Admin);
        [$readOnly, $connector] = $this->restrictedAndConnector($admin, $tenant);
        $tokensBefore = PersonalAccessToken::query()->count();

        foreach ([$readOnly, $connector] as $token) {
            $this->forbiddenAsAccount($this->bearer($token, 'POST', '/api/v1/auth/mfa/setup', ['password' => 'password']));
            $this->forbiddenAsAccount($this->bearer($token, 'POST', '/api/v1/auth/mfa/confirm', ['code' => '123456', 'password' => 'password']));
            $this->forbiddenAsAccount($this->bearer($token, 'POST', '/api/v1/auth/mfa/step-up', ['code' => '123456']));
            $this->forbiddenAsAccount($this->bearer($token, 'POST', '/api/v1/auth/mfa/recovery-codes', ['code' => '123456', 'password' => 'password']));
            $this->forbiddenAsAccount($this->bearer($token, 'DELETE', '/api/v1/auth/mfa', ['password' => 'password', 'code' => '123456']));
            $this->forbiddenAsAccount($this->bearer($token, 'GET', '/api/v1/auth/mfa'));
            $this->forbiddenAsAccount($this->bearer($token, 'POST', '/api/v1/auth/tokens/revoke-others'));
            $this->forbiddenAsAccount($this->bearer($token, 'PUT', '/api/v1/auth/password', ['current_password' => 'password', 'password' => 'NuevaClave12345', 'password_confirmation' => 'NuevaClave12345']));
        }
        $this->assertNull(DB::table('users')->where('id', $admin->id)->value('mfa_secret_encrypted'), 'nadie enroló un autenticador');
        $this->assertSame($tokensBefore, PersonalAccessToken::query()->count(), 'no se revocó ningún token');
        $this->assertTrue(password_verify('password', (string) DB::table('users')->where('id', $admin->id)->value('password')), 'la contraseña no cambió');
    }

    // ── T4: lo que sí puede un inicio de sesión completo ──────────────────────────────────────────────────────────────────────────────────────

    public function test_t4_a_full_login_token_keeps_working_within_the_role(): void
    {
        [$tenant, $analyst] = $this->createTenantWithUser(TenantRole::Analyst);
        $login = $this->loginToken($analyst);

        $ok = $this->bearer($login, 'POST', '/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'ok', 'abilities' => ['data.view', 'forecast.view']])->assertCreated();
        $this->assertSame(['data.view', 'forecast.view'], $ok->json('data.abilities'));
        $this->bearer($login, 'POST', '/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'mas', 'abilities' => ['settings.manage']])->assertUnprocessable();
        $this->bearer($login, 'POST', '/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'todo', 'abilities' => ['*']])->assertUnprocessable();
        $this->bearer($login, 'GET', '/api/v1/auth/tokens')->assertOk();
        $this->bearer($login, 'GET', '/api/v1/tenants')->assertOk()->assertJsonCount(1, 'data');
        $this->bearer($login, 'GET', '/api/v1/auth/mfa')->assertOk();
        // Una sesión de la SPA (o la autenticación transitoria) tampoco se ve afectada.
        Sanctum::actingAs($analyst->refresh(), ['*']);
        $this->getJson('/api/v1/tenants')->assertOk();
        $this->getJson('/api/v1/auth/tokens')->assertOk();
    }

    // ── T5: empresas ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_t5_a_restricted_token_cannot_list_or_create_companies(): void
    {
        [$a, $admin] = $this->createTenantWithUser(TenantRole::Admin);
        [$b] = $this->createTenantWithUser(TenantRole::Owner);
        $b->users()->attach($admin->id, ['role' => TenantRole::Owner->value]);
        [$readOnly, $connector] = $this->restrictedAndConnector($admin, $a);
        $companies = Tenant::query()->count();

        foreach ([$readOnly, $connector] as $token) {
            $this->forbiddenAsAccount($this->bearer($token, 'GET', '/api/v1/tenants'));
            $this->forbiddenAsAccount($this->bearer($token, 'POST', '/api/v1/tenants', ['name' => 'Empresa creada con token restringido', 'sector' => 'ferreteria']));
        }
        $this->assertSame($companies, Tenant::query()->count(), 'no se creó ninguna empresa');
        // Un inicio de sesión completo ve ambas.
        $this->bearer($this->loginToken($admin), 'GET', '/api/v1/tenants')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_t6_auth_me_shows_a_restricted_token_only_its_own_company(): void
    {
        [$a, $admin] = $this->createTenantWithUser(TenantRole::Admin);
        [$b] = $this->createTenantWithUser(TenantRole::Owner);
        $b->users()->attach($admin->id, ['role' => TenantRole::Owner->value]);
        [$readOnly, $connector] = $this->restrictedAndConnector($admin, $a);

        $full = $this->bearer($this->loginToken($admin), 'GET', '/api/v1/auth/me')->assertOk();
        $this->assertEqualsCanonicalizing([$a->uuid, $b->uuid], array_column($full->json('data.tenants'), 'uuid'));
        foreach ([$readOnly, $connector] as $token) {
            $me = $this->bearer($token, 'GET', '/api/v1/auth/me')->assertOk();
            $this->assertSame([$a->uuid], array_column($me->json('data.tenants'), 'uuid'), 'un token ligado a la empresa A no revela la B');
        }
        // Un token restringido SIN empresa (no emitido por la API) no ve ninguna.
        $loose = $admin->createToken('suelto', ['data.view'])->plainTextToken;
        $this->assertSame([], $this->bearer($loose, 'GET', '/api/v1/auth/me')->assertOk()->json('data.tenants'));
    }

    // ── T7 y T9: entre empresas ──────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_t7_a_token_bound_to_company_a_cannot_mint_a_token_for_company_b(): void
    {
        [$a, $user] = $this->createTenantWithUser(TenantRole::Admin);
        [$b] = $this->createTenantWithUser(TenantRole::Owner);
        $b->users()->attach($user->id, ['role' => TenantRole::Owner->value]);
        [$readOnlyA] = $this->restrictedAndConnector($user, $a);
        $before = PersonalAccessToken::query()->count();

        $this->forbiddenAsAccount($this->bearer($readOnlyA, 'POST', '/api/v1/auth/tokens', ['tenant' => $b->uuid, 'name' => 'cruzado-b', 'abilities' => ['tenant.delete', 'users.manage']]));
        $this->assertSame($before, PersonalAccessToken::query()->count());
        // Y desde el inicio de sesión completo sí se emite para B, con las habilidades del rol de la persona EN B.
        $minted = $this->bearer($this->loginToken($user), 'POST', '/api/v1/auth/tokens', ['tenant' => $b->uuid, 'name' => 'para-b', 'abilities' => ['users.manage']])->assertCreated();
        $this->assertSame($b->uuid, $minted->json('data.tenant'));
        $this->bearer((string) $minted->json('data.token'), 'GET', '/api/v1/tenant/users', [], $b)->assertOk();
    }

    public function test_t8_with_mfa_a_restricted_token_still_cannot_mint_or_enrol_and_the_owner_needs_a_fresh_factor(): void
    {
        $this->freezeClock();
        [$tenant, $admin] = $this->createTenantWithUser(TenantRole::Admin);
        $mfa = $this->enableMfa($admin->refresh());
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])->json('data.challenge');
        $login = (string) $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $challenge, 'code' => $this->freshCode($mfa['secret'])])->assertOk()->json('data.token');
        $readOnly = (string) $this->bearer($login, 'POST', '/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'bi-ro-mfa', 'abilities' => ['data.view']])->assertCreated()->json('data.token');

        $this->forbiddenAsAccount($this->bearer($readOnly, 'POST', '/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'escalado-mfa', 'abilities' => ['settings.manage']]));
        $this->forbiddenAsAccount($this->bearer($readOnly, 'POST', '/api/v1/auth/mfa/setup', ['password' => 'password']));
        $this->forbiddenAsAccount($this->bearer($readOnly, 'POST', '/api/v1/auth/mfa/step-up', ['code' => $this->freshCode($mfa['secret'])]));
        // Un token restringido tampoco puede verificar el segundo factor: sin él no hace acciones sensibles aunque tenga la habilidad.
        $withSettings = (string) $this->bearer($login, 'POST', '/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'ajustes', 'abilities' => ['settings.manage']])->assertCreated()->json('data.token');
        $this->bearer($withSettings, 'PATCH', '/api/v1/tenant', ['name' => 'Nombre nuevo'], $tenant)->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
        // El inicio de sesión completo nació con el segundo factor fresco.
        $this->bearer($login, 'PATCH', '/api/v1/tenant', ['name' => 'Nombre nuevo'], $tenant)->assertOk();
    }

    public function test_t9_the_token_service_itself_refuses_wider_or_foreign_tokens(): void
    {
        [$a, $user] = $this->createTenantWithUser(TenantRole::Admin);
        [$b] = $this->createTenantWithUser(TenantRole::Owner);
        $b->users()->attach($user->id, ['role' => TenantRole::Owner->value]);
        $service = app(TokenService::class);
        $caller = $user->createToken('user:acotado', ['data.view'])->accessToken;
        $caller->forceFill(['tenant_id' => $a->id])->save();

        // Defensa en profundidad (la ruta ya lo impide con `account.full`): más habilidades que el que llama, o otra empresa.
        foreach ([[$a, ['data.view', 'settings.manage']], [$b, ['data.view']]] as [$tenant, $abilities]) {
            try {
                $service->create($user, $tenant, 'prueba', $abilities, null, $caller);
                $this->fail('Debió negarse.');
            } catch (TokenScopeException $e) {
                $this->assertSame(403, $e->render()->getStatusCode());
                $this->assertSame('token_scope_insufficient', $e->render()->getData(true)['code']);
            }
        }
        $this->assertSame(0, PersonalAccessToken::query()->where('name', 'like', 'user:prueba%')->count());
        // Dentro de su alcance y de su empresa, sí.
        $this->assertNotNull($service->create($user, $a, 'prueba', ['data.view'], null, $caller)->plainTextToken);
        // Un llamador con comodín (inicio de sesión) emite lo que su rol permita.
        $login = $user->createToken('api-login', ['*'])->accessToken;
        $this->assertNotNull($service->create($user, $b, 'prueba-b', ['tenant.delete'], null, $login)->plainTextToken);
    }

    public function test_a_wildcard_token_bound_to_a_company_is_not_a_full_login(): void
    {
        [$tenant, $admin] = $this->createTenantWithUser(TenantRole::Admin);
        // Un token con comodín pero LIGADO a una empresa no es un inicio de sesión completo (el de inicio de sesión no tiene empresa): no llega a las rutas de cuenta.
        $token = $admin->createToken('api-login', ['*']);
        $token->accessToken->forceFill(['tenant_id' => $tenant->id])->save();

        foreach ([['POST', '/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'x', 'abilities' => ['data.view']]], ['GET', '/api/v1/auth/tokens', []], ['POST', '/api/v1/auth/mfa/setup', ['password' => 'password']], ['GET', '/api/v1/tenants', []]] as [$method, $uri, $body]) {
            $this->forbiddenAsAccount($this->bearer($token->plainTextToken, $method, $uri, $body));
        }
        $this->assertFalse(RequireFullLogin::isFullLogin($token->accessToken->refresh()));
        $this->assertTrue(RequireFullLogin::isFullLogin($admin->createToken('api-login', ['*'])->accessToken));
        $this->assertFalse(RequireFullLogin::isFullLogin($admin->createToken('api-login', ['data.view'])->accessToken));
    }

    public function test_the_wildcard_is_always_denied_when_issuing_even_if_a_role_list_contained_it(): void
    {
        $this->assertSame(['*'], TokenService::deniedAbilities(['*', 'data.view'], ['*', 'data.view']));
        $this->assertSame(['forecast.run'], TokenService::deniedAbilities(['data.view', 'forecast.run'], ['data.view']));
        $this->assertSame([], TokenService::deniedAbilities(['data.view'], ['data.view', 'forecast.view']));
        $this->assertSame([], TokenService::deniedAbilities([], ['*']));
    }

    public function test_a_device_or_garbage_token_does_not_reach_account_routes(): void
    {
        foreach (['brt_'.str_repeat('A', 43), 'no-es-un-token'] as $token) {
            foreach ([['POST', '/api/v1/auth/tokens'], ['POST', '/api/v1/auth/mfa/setup'], ['GET', '/api/v1/tenants'], ['POST', '/api/v1/tenants']] as [$method, $uri]) {
                $this->assertSame(401, $this->bearer($token, $method, $uri)->getStatusCode(), "{$method} {$uri}");
            }
        }
    }
}
