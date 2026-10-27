<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_register_creates_user_and_returns_token_for_api_clients(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ana Pérez', 'email' => 'ANA@Example.com', 'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123', 'accept_terms' => true,
        ]);

        $response->assertCreated()->assertJsonPath('data.user.email', 'ana@example.com')->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token']]);
        $this->assertDatabaseHas('users', ['email' => 'ana@example.com']);
        $this->assertTrue(AuditLog::query()->where('action', 'auth.registered')->exists());
    }

    public function test_register_with_spa_origin_uses_session_cookie_instead_of_token(): void
    {
        $response = $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/registro'])
            ->postJson('/api/v1/auth/register', [
                'name' => 'Luis', 'email' => 'luis@example.com', 'password' => 'ClaveSegura123', 'password_confirmation' => 'ClaveSegura123', 'accept_terms' => true,
            ]);

        $response->assertCreated()->assertJsonMissingPath('data.token');
        $this->assertAuthenticated('web');
    }

    public function test_register_validates_password_strength_terms_and_unique_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);
        $this->postJson('/api/v1/auth/register', [
            'name' => 'X', 'email' => 'dup@example.com', 'password' => 'corta', 'password_confirmation' => 'otra',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password', 'accept_terms']);
    }

    public function test_login_success_and_failure_are_audited_without_revealing_existence(): void
    {
        $user = User::factory()->create(['email' => 'maria@example.com', 'password' => 'ClaveSegura123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'maria@example.com', 'password' => 'incorrecta'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Las credenciales no son válidas.');
        $this->postJson('/api/v1/auth/login', ['email' => 'noexiste@example.com', 'password' => 'incorrecta'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Las credenciales no son válidas.');
        $this->postJson('/api/v1/auth/login', ['email' => 'MARIA@example.com', 'password' => 'ClaveSegura123'])
            ->assertOk()->assertJsonPath('data.user.id', $user->id);

        $this->assertSame(2, AuditLog::query()->where('action', 'auth.failed')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.login')->count());
        $this->assertNotNull($user->fresh()->last_login_at);
        // La auditoría no guarda el correo ingresado.
        $this->assertFalse(AuditLog::query()->where('metadata', 'like', '%example.com%')->exists());
    }

    public function test_login_is_rate_limited_per_email_and_ip(): void
    {
        RateLimiter::clear('login');
        User::factory()->create(['email' => 'rl@example.com', 'password' => 'ClaveSegura123']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'rl@example.com', 'password' => 'mala'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'rl@example.com', 'password' => 'ClaveSegura123'])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    }

    public function test_me_lists_only_own_tenants_with_role(): void
    {
        [$tenantA, $user] = $this->createTenantWithUser();
        $this->createTenantWithUser(); // otra empresa de otro usuario

        $this->actingAsTenantUser($user, $tenantA)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonCount(1, 'data.tenants')
            ->assertJsonPath('data.tenants.0.uuid', $tenantA->uuid)
            ->assertJsonPath('data.tenants.0.role', 'owner');
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/products')->assertUnauthorized();
    }

    public function test_token_logout_revokes_the_token(): void
    {
        $user = User::factory()->create(['password' => 'ClaveSegura123']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'ClaveSegura123'])->json('data.token');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_change_password_requires_current_password(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $user->forceFill(['password' => 'ClaveSegura123'])->save();
        $this->actingAsTenantUser($user, $tenant)->putJson('/api/v1/auth/password', ['current_password' => 'mala', 'password' => 'NuevaClave456', 'password_confirmation' => 'NuevaClave456'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/auth/password', ['current_password' => 'ClaveSegura123', 'password' => 'NuevaClave456', 'password_confirmation' => 'NuevaClave456'])
            ->assertNoContent();
    }
}
