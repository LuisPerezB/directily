<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\DataSource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Mfa\MfaException;
use App\Services\Auth\Mfa\MfaPolicy;
use App\Services\Auth\Mfa\MfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Security\Support\SecurityFixtures;
use Tests\TestCase;

/**
 * V2.5 · W6-A (ADR-V25-15): MFA TOTP de punta a punta por la API. Activación con verificación del primer código, inicio de sesión con desafío, códigos de recuperación de un solo
 * uso, segundo factor fresco (step-up) en las acciones sensibles, bloqueo por fuerza bruta y política por empresa. Reloj fijo; códigos reales del algoritmo (RFC 6238).
 */
final class MfaFlowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase, SecurityFixtures;

    private const PASSWORD = 'password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
    }

    /** @return array{0: Tenant, 1: User} */
    private function owner(TenantRole $role = TenantRole::Owner): array
    {
        return $this->createTenantWithUser($role);
    }

    private function api(User $user, Tenant $tenant): static
    {
        return $this->actingAsTenantUser($user, $tenant);
    }

    // ── Activación ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_enrolment_stores_the_secret_encrypted_and_activates_only_after_a_valid_first_code(): void
    {
        [, $user] = $this->owner();
        Sanctum::actingAs($user->refresh(), ['*']);

        $setup = $this->postJson('/api/v1/auth/mfa/setup', ['password' => self::PASSWORD])->assertCreated();
        $secret = $setup->json('data.secret');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertStringStartsWith('otpauth://totp/Predictily:', $setup->json('data.otpauth_uri'));
        $this->assertStringContainsString('secret='.$secret, $setup->json('data.otpauth_uri'));
        $this->assertSame(['Predictily', 6, 30, 'SHA1'], [$setup->json('data.issuer'), $setup->json('data.digits'), $setup->json('data.period'), $setup->json('data.algorithm')]);

        // Secreto cifrado con la clave de la aplicación: lo guardado no es el secreto y lo descifra solo la aplicación. Aún NO está activo.
        $stored = (string) DB::table('users')->where('id', $user->id)->value('mfa_secret_encrypted');
        $this->assertNotSame($secret, $stored);
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertSame($secret, Crypt::decryptString($stored));
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('mfa_enabled_at'));
        $this->getJson('/api/v1/auth/mfa')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.pending', true);

        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '000000', 'password' => self::PASSWORD])->assertUnprocessable()->assertJsonPath('code', 'mfa_invalid_code');
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('mfa_enabled_at'), 'un código incorrecto no activa');

        $confirm = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->totp($secret), 'password' => self::PASSWORD])->assertOk()->assertJsonPath('data.enabled', true);
        $this->assertCount(10, $confirm->json('data.recovery_codes'));
        $this->assertNotNull(DB::table('users')->where('id', $user->id)->value('mfa_enabled_at'));
        $this->getJson('/api/v1/auth/mfa')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.recovery_codes_remaining', 10)->assertJsonPath('data.step_up.fresh', true);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.mfa_enabled', true)
            ->assertJsonMissingPath('data.user.mfa_secret_encrypted')->assertJsonMissingPath('data.user.mfa_recovery_codes');
    }

    public function test_recovery_codes_are_shown_once_and_stored_only_as_hashes(): void
    {
        [, $user] = $this->owner();
        Sanctum::actingAs($user->refresh(), ['*']);
        $secret = $this->postJson('/api/v1/auth/mfa/setup', ['password' => self::PASSWORD])->json('data.secret');
        $codes = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->totp($secret), 'password' => self::PASSWORD])->assertOk()->json('data.recovery_codes');

        $raw = (string) DB::table('users')->where('id', $user->id)->value('mfa_recovery_codes');
        $this->assertCount(10, json_decode($raw, true));
        foreach ($codes as $code) {
            $this->assertStringNotContainsString(str_replace('-', '', $code), $raw);
            $this->assertStringNotContainsString($code, $raw);
        }
        $this->getJson('/api/v1/auth/mfa')->assertJsonMissingPath('data.recovery_codes');
    }

    public function test_setup_is_refused_when_the_second_factor_is_already_active(): void
    {
        [, $user] = $this->owner();
        $this->enableMfa($user);
        Sanctum::actingAs($user->refresh(), ['*']);

        $this->postJson('/api/v1/auth/mfa/setup', ['password' => self::PASSWORD])->assertStatus(409)->assertJsonPath('code', 'mfa_already_enabled');
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '123456', 'password' => self::PASSWORD])->assertStatus(409)->assertJsonPath('code', 'mfa_already_enabled');
    }

    public function test_confirm_without_a_pending_setup_is_a_conflict(): void
    {
        [, $user] = $this->owner();
        Sanctum::actingAs($user->refresh(), ['*']);

        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '123456', 'password' => self::PASSWORD])->assertStatus(409)->assertJsonPath('code', 'mfa_not_pending');
    }

    // ── Inicio de sesión ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_login_with_mfa_returns_a_challenge_and_no_token_until_the_code_is_verified(): void
    {
        [, $user] = $this->owner();
        $mfa = $this->enableMfa($user);

        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(202)
            ->assertJsonPath('code', 'mfa_required')->assertJsonPath('data.mfa_required', true)->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.user');
        $this->assertSame(300, $login->json('data.expires_in'));
        $this->assertNull(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->value('id'), 'sin token hasta el segundo factor');
        $this->assertSame(0, AuditLog::query()->where('action', 'auth.login')->where('user_id', $user->id)->count(), 'la contraseña sola no es un inicio de sesión');
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.mfa_challenge_issued')->count());

        $done = $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $login->json('data.challenge'), 'code' => $this->freshCode($mfa['secret'])])->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.mfa_method', 'totp');
        $this->assertNotEmpty($done->json('data.token'));
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.login')->where('user_id', $user->id)->count());
        $this->assertNotNull($user->fresh()->last_login_at);

        // El token emitido funciona y nace con el segundo factor fresco (el inicio de sesión cuenta como verificación).
        $this->app['auth']->forgetGuards();
        $this->withToken($done->json('data.token'))->getJson('/api/v1/auth/mfa')->assertOk()->assertJsonPath('data.step_up.fresh', true);
    }

    public function test_the_challenge_is_single_use_and_a_wrong_code_does_not_complete_the_login(): void
    {
        [, $user] = $this->owner();
        $mfa = $this->enableMfa($user);
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('data.challenge');

        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $challenge, 'code' => '000000'])->assertUnprocessable()->assertJsonPath('code', 'mfa_invalid_code');
        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => 'desafio-inventado', 'code' => $this->freshCode($mfa['secret'])])->assertUnprocessable()->assertJsonPath('code', 'mfa_challenge_invalid');
        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $challenge, 'code' => $this->freshCode($mfa['secret'])])->assertOk();
        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $challenge, 'code' => $this->freshCode($mfa['secret'])])->assertUnprocessable()->assertJsonPath('code', 'mfa_challenge_invalid');
    }

    public function test_an_expired_challenge_is_refused(): void
    {
        [, $user] = $this->owner();
        $mfa = $this->enableMfa($user);
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('data.challenge');

        $this->travel(301)->seconds();
        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $challenge, 'code' => $this->totp($mfa['secret'], 1)])->assertUnprocessable()->assertJsonPath('code', 'mfa_challenge_invalid');
    }

    public function test_a_totp_code_cannot_be_replayed(): void
    {
        [, $user] = $this->owner();
        $mfa = $this->enableMfa($user);
        $code = $this->freshCode($mfa['secret']);
        $first = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('data.challenge');
        $second = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('data.challenge');

        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $first, 'code' => $code])->assertOk();
        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $second, 'code' => $code])->assertUnprocessable()->assertJsonPath('code', 'mfa_invalid_code');
    }

    public function test_codes_one_step_either_side_are_accepted_and_two_steps_are_not(): void
    {
        [, $user] = $this->owner();
        $mfa = $this->enableMfa($user);
        Sanctum::actingAs($user->refresh(), ['*']);

        // enableMfa usó el paso actual (S). Un paso adelantado (reloj del servidor ligeramente atrasado, ≤ 30 s) → válido.
        $this->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->totp($mfa['secret'], 1)])->assertOk();
        // Tres pasos adelante: fuera de la ventana de ±1.
        $this->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->totp($mfa['secret'], 3)])->assertUnprocessable()->assertJsonPath('code', 'mfa_invalid_code');
        // 90 s después el paso actual es S+3: el código del paso anterior (S+2, reloj del servidor adelantado) es válido y mayor que el último aceptado (S+1)...
        $this->travel(90)->seconds();
        $this->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->totp($mfa['secret'], -1)])->assertOk();
        // ...pero no se acepta dos veces, ni uno anterior al último aceptado.
        $this->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->totp($mfa['secret'], -1)])->assertUnprocessable();
        $this->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->totp($mfa['secret'], -2)])->assertUnprocessable();
    }

    public function test_a_recovery_code_completes_the_login_once(): void
    {
        [, $user] = $this->owner();
        $mfa = $this->enableMfa($user);
        $recovery = $mfa['recovery'][3];
        $loginAgain = fn (): string => $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('data.challenge');

        $done = $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $loginAgain(), 'recovery_code' => strtolower($recovery)])->assertOk()
            ->assertJsonPath('data.mfa_method', 'recovery')->assertJsonPath('data.recovery_codes_remaining', 9);
        $this->assertNotEmpty($done->json('data.token'));
        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $loginAgain(), 'recovery_code' => $recovery])->assertUnprocessable()->assertJsonPath('code', 'mfa_invalid_code');
        $this->assertSame(1, AuditLog::query()->where('action', 'mfa.recovery_used')->count());
        // Otro código de recuperación sí sirve.
        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $loginAgain(), 'recovery_code' => $mfa['recovery'][4]])->assertOk();
    }

    public function test_regenerating_recovery_codes_requires_a_current_code_and_invalidates_the_old_ones(): void
    {
        [, $user] = $this->owner();
        $mfa = $this->enableMfa($user);
        Sanctum::actingAs($user->refresh(), ['*']);

        $this->postJson('/api/v1/auth/mfa/recovery-codes', ['code' => '000000', 'password' => self::PASSWORD])->assertUnprocessable();
        $new = $this->postJson('/api/v1/auth/mfa/recovery-codes', ['code' => $this->freshCode($mfa['secret']), 'password' => self::PASSWORD])->assertOk()->json('data.recovery_codes');
        $this->assertCount(10, $new);
        $this->assertEmpty(array_intersect($new, $mfa['recovery']));

        $this->postJson('/api/v1/auth/mfa/step-up', ['recovery_code' => $mfa['recovery'][0]])->assertUnprocessable();
        $this->postJson('/api/v1/auth/mfa/step-up', ['recovery_code' => $new[0]])->assertOk();
    }

    // ── Segundo factor fresco ────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_sensitive_abilities_need_a_fresh_second_factor_that_expires(): void
    {
        [$tenant, $user] = $this->owner();
        $mfa = $this->enableMfa($user);
        $api = fn () => $this->api($user, $tenant);

        $api()->patchJson('/api/v1/tenant', ['name' => 'Empresa renombrada'])->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
        $this->assertNotSame('Empresa renombrada', $tenant->fresh()->name);

        $api()->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->freshCode($mfa['secret'])])->assertOk()->assertJsonPath('data.verified', true);
        $api()->patchJson('/api/v1/tenant', ['name' => 'Empresa renombrada'])->assertOk();

        // Dentro de la ventana sigue fresco; vencida, se vuelve a pedir.
        $this->travel(599)->seconds();
        $api()->patchJson('/api/v1/tenant', ['name' => 'Otro nombre'])->assertOk();
        $this->travel(2)->seconds();
        $api()->patchJson('/api/v1/tenant', ['name' => 'Nombre tardío'])->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
        $api()->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->freshCode($mfa['secret'])])->assertOk();
        $api()->patchJson('/api/v1/tenant', ['name' => 'Nombre tardío'])->assertOk();
    }

    public function test_every_sensitive_family_is_protected(): void
    {
        [$tenant, $user] = $this->owner();
        $this->enableMfa($user);
        $source = $this->withinTenant($tenant, fn () => DataSource::query()->create(['type' => 'rest_api', 'connector_key' => 'generic_rest', 'name' => 'Fuente', 'config' => []]));
        $api = fn () => $this->api($user, $tenant);

        $needsStepUp = [
            'ajustes de la empresa (settings.manage)' => fn () => $api()->patchJson('/api/v1/tenant', ['name' => 'Nombre']),
            'política de seguridad (settings.manage)' => fn () => $api()->putJson('/api/v1/tenant/security-policy', ['mfa_required_for_privileged' => false]),
            'usuarios (users.manage)' => fn () => $api()->getJson('/api/v1/tenant/users'),
            'tokens de la empresa (users.manage)' => fn () => $api()->getJson('/api/v1/tenant/tokens'),
            'rol comercial (users.manage)' => fn () => $api()->putJson("/api/v1/tenant/users/{$user->id}/commercial-role", ['commercial_role' => null]),
            'fuentes de datos y token de conector (datasources.manage)' => fn () => $api()->postJson("/api/v1/data-sources/{$source->id}/tokens"),
            'aprobación de modelos (models.approve)' => fn () => $api()->postJson('/api/v1/models/999/approve'),
            'promoción de modelos (models.approve)' => fn () => $api()->postJson('/api/v1/models/999/promote'),
            'rechazo de modelos (models.approve)' => fn () => $api()->postJson('/api/v1/models/999/reject', ['reason' => 'x']),
            'exportaciones CSV (data.export)' => fn () => $api()->getJson('/api/v1/exports/forecasts.csv'),
            'exportación completa LOPDP (tenant.export_all)' => fn () => $api()->postJson('/api/v1/tenant/export'),
            'consentimiento (tenant.consent)' => fn () => $api()->putJson('/api/v1/tenant/shared-training-consent', ['allow' => false]),
            'eliminación de la empresa (tenant.delete)' => fn () => $api()->deleteJson('/api/v1/tenant', ['confirm_name' => 'x']),
            'token personal' => fn () => $api()->postJson('/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'Mi token', 'abilities' => ['data.view']]),
            'aprobar una decisión' => fn () => $api()->postJson('/api/v1/decisions/1/transition', ['to_status' => 'approved', 'idempotency_key' => 'k', 'expected_version' => 1]),
            'rechazar una decisión' => fn () => $api()->postJson('/api/v1/decisions/1/transition', ['to_status' => 'rejected', 'idempotency_key' => 'k', 'expected_version' => 1]),
        ];
        foreach ($needsStepUp as $label => $call) {
            $call()->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required', $label);
        }
        // Una transición que NO aprueba ni rechaza no pide segundo factor: llega al flujo de decisiones (que dirá lo suyo: aquí, que no existe).
        $other = $api()->postJson('/api/v1/decisions/1/transition', ['to_status' => 'assigned', 'idempotency_key' => 'k', 'expected_version' => 1]);
        $this->assertNotSame('mfa_step_up_required', $other->json('code'));
        // Lo que no es sensible, tampoco: una lectura corriente.
        $api()->getJson('/api/v1/tenant')->assertOk();
    }

    public function test_people_without_mfa_are_never_asked_for_a_second_factor(): void
    {
        [$tenant, $user] = $this->owner();

        $this->api($user, $tenant)->patchJson('/api/v1/tenant', ['name' => 'Sin MFA'])->assertOk();
        $this->api($user, $tenant)->getJson('/api/v1/tenant/users')->assertOk();
        $this->api($user, $tenant)->postJson('/api/v1/auth/tokens', ['tenant' => $tenant->uuid, 'name' => 'Mi token', 'abilities' => ['data.view']])->assertCreated();
    }

    public function test_a_wrong_ability_is_reported_before_any_hint_about_the_second_factor(): void
    {
        [$tenant, $viewer] = $this->owner(TenantRole::Viewer);
        $this->enableMfa($viewer);

        $this->api($viewer, $tenant)->patchJson('/api/v1/tenant', ['name' => 'Nombre'])->assertForbidden()->assertJsonPath('code', 'forbidden')->assertJsonPath('required_ability', 'settings.manage');
    }

    public function test_step_up_over_a_real_bearer_token_is_tied_to_that_token(): void
    {
        [$tenant, $user] = $this->owner();
        $mfa = $this->enableMfa($user);
        $token = $user->createToken('t', ['*'])->plainTextToken;
        $other = $user->createToken('t2', ['*'])->plainTextToken;
        $call = function (string $bearer, string $method, string $uri, array $body = []) use ($tenant) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($bearer)->withHeader('X-Tenant-ID', $tenant->uuid)->json($method, $uri, $body);
        };

        $call($token, 'PATCH', '/api/v1/tenant', ['name' => 'Nombre uno'])->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
        $call($token, 'POST', '/api/v1/auth/mfa/step-up', ['code' => $this->freshCode($mfa['secret'])])->assertOk();
        $call($token, 'PATCH', '/api/v1/tenant', ['name' => 'Nombre uno'])->assertOk();
        // El segundo token de la misma persona NO hereda la verificación.
        $call($other, 'PATCH', '/api/v1/tenant', ['name' => 'Nombre dos'])->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
    }

    // ── Fuerza bruta ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** Llama como si viniera de otra IP (los contadores y bloqueos por IP se separan por dirección). */
    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    public function test_five_failures_from_one_ip_lock_that_combination_but_not_the_victim_elsewhere(): void
    {
        [, $user] = $this->owner();
        $mfa = $this->enableMfa($user);
        Sanctum::actingAs($user->refresh(), ['*']);

        // Quien conoce la contraseña (el atacante) falla desde SU IP...
        for ($i = 1; $i <= 4; $i++) {
            $this->fromIp('203.0.113.9')->postJson('/api/v1/auth/mfa/step-up', ['code' => '00000'.$i])->assertUnprocessable()->assertJsonPath('code', 'mfa_invalid_code');
        }
        $locked = $this->fromIp('203.0.113.9')->postJson('/api/v1/auth/mfa/step-up', ['code' => '000005'])->assertStatus(423)->assertJsonPath('code', 'mfa_locked');
        $this->assertSame(900, $locked->json('retry_after'));
        $this->assertSame('900', $locked->headers->get('Retry-After'));

        // ...y esa IP queda bloqueada: ni el código correcto ni uno de recuperación se aceptan desde ella.
        $this->fromIp('203.0.113.9')->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->freshCode($mfa['secret'])])->assertStatus(423)->assertJsonPath('code', 'mfa_locked');
        $this->fromIp('203.0.113.9')->postJson('/api/v1/auth/mfa/step-up', ['recovery_code' => $mfa['recovery'][0]])->assertStatus(423);
        // La VÍCTIMA, desde su propia IP, no queda bloqueada: puede verificar y entrar.
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('mfa_locked_until'), 'la persona no quedó bloqueada: solo la combinación persona + IP');
        $this->fromIp('198.51.100.7')->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->freshCode($mfa['secret'])])->assertOk();
        $status = $this->fromIp('198.51.100.7')->getJson('/api/v1/auth/mfa')->assertOk()->assertJsonPath('data.recovery_codes_remaining', 10);
        $this->assertNull($status->json('data.locked_until'));
        $this->assertSame(['pair'], array_values(array_unique(array_column(array_column(AuditLog::query()->where('action', 'mfa.locked')->get()->all(), 'metadata'), 'scope'))));
    }

    public function test_the_person_is_locked_only_after_failures_from_several_ips_and_then_everywhere(): void
    {
        [, $user] = $this->owner();
        $mfa = $this->enableMfa($user);
        Sanctum::actingAs($user->refresh(), ['*']);

        // 3 IP × 5 intentos = 15 fallos de la persona: el nivel de la persona (más lento) se activa en el último.
        foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $n => $ip) {
            for ($i = 1; $i <= 5; $i++) {
                $this->fromIp($ip)->postJson('/api/v1/auth/mfa/step-up', ['code' => '0000'.$n.$i])->assertStatus($i < 5 ? 422 : 423);
            }
            $expectedLock = $n === 2;
            $this->assertSame($expectedLock, DB::table('users')->where('id', $user->id)->value('mfa_locked_until') !== null, "tras la IP {$ip}");
        }
        // Bloqueada la persona: ni siquiera desde una IP nueva y con el código correcto.
        $this->fromIp('198.51.100.7')->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->freshCode($mfa['secret'])])->assertStatus(423)->assertJsonPath('code', 'mfa_locked');
        $status = $this->fromIp('198.51.100.7')->getJson('/api/v1/auth/mfa')->assertOk();
        $this->assertNotNull($status->json('data.locked_until'));
        $this->assertContains('user', array_column(array_column(AuditLog::query()->where('action', 'mfa.locked')->get()->all(), 'metadata'), 'scope'));
    }

    public function test_one_ip_sweeping_many_accounts_is_locked_by_ip(): void
    {
        config(['security.mfa.max_failed_attempts_per_ip' => 6]);
        $users = [];
        for ($i = 0; $i < 4; $i++) {
            [, $u] = $this->owner();
            $this->enableMfa($u);
            $users[] = $u;
        }
        // 2 intentos por cuenta desde la misma IP: ninguna combinación persona + IP llega a 5, pero la IP sí a 6.
        $statuses = [];
        foreach ($users as $u) {
            foreach ([1, 2] as $try) {
                Sanctum::actingAs($u->refresh(), ['*']);
                $statuses[] = $this->fromIp('203.0.113.50')->postJson('/api/v1/auth/mfa/step-up', ['code' => '99999'.$try])->getStatusCode();
            }
        }
        $this->assertSame([422, 422, 422, 422, 422, 423, 423, 423], $statuses, 'la IP queda bloqueada al llegar a su límite');
        $this->assertContains('ip', array_column(array_column(AuditLog::query()->where('action', 'mfa.locked')->get()->all(), 'metadata'), 'scope'));
    }

    public function test_the_lock_expires_and_a_success_resets_the_counter(): void
    {
        [, $user] = $this->owner();
        $mfa = $this->enableMfa($user);
        Sanctum::actingAs($user->refresh(), ['*']);
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/auth/mfa/step-up', ['code' => '00000'.$i]);
        }

        $this->travel(899)->seconds();
        $this->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->totp($mfa['secret'], 1)])->assertStatus(423);
        $this->travel(2)->seconds();
        $this->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->freshCode($mfa['secret'])])->assertOk();
        $row = DB::table('users')->where('id', $user->id)->first(['mfa_failed_attempts', 'mfa_locked_until']);
        $this->assertSame(0, (int) $row->mfa_failed_attempts);
        $this->assertNull($row->mfa_locked_until);
        // Un acierto también reinicia el contador de la combinación persona + IP: 4 fallos más no la bloquean.
        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/v1/auth/mfa/step-up', ['code' => '11111'.$i])->assertUnprocessable();
        }
    }

    public function test_attempts_are_reserved_before_checking_so_a_burst_cannot_try_more_than_the_limit(): void
    {
        [, $user] = $this->owner();
        $this->enableMfa($user);
        $service = app(MfaService::class);
        $verified = 0;
        $locked = 0;
        for ($i = 0; $i < 50; $i++) {
            try {
                $service->verify($user->refresh(), sprintf('%06d', $i), null);
            } catch (MfaException $e) {
                $e->errorCode === 'mfa_locked' ? $locked++ : $verified++;
            }
        }

        // Solo 5 intentos llegaron a comprobarse (4 «inválido» + el que bloquea); los otros 45 se rechazaron sin comprobar.
        $this->assertSame(4, $verified);
        $this->assertSame(46, $locked);
        $this->assertSame(5, AuditLog::query()->where('action', 'mfa.failed')->count());
    }

    // ── Desactivación ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_disabling_requires_the_password_and_a_code(): void
    {
        [, $user] = $this->owner();
        $mfa = $this->enableMfa($user);
        Sanctum::actingAs($user->refresh(), ['*']);

        $this->deleteJson('/api/v1/auth/mfa', ['password' => 'incorrecta', 'code' => $this->freshCode($mfa['secret'])])->assertUnprocessable()->assertJsonPath('code', 'mfa_invalid_code');
        $this->deleteJson('/api/v1/auth/mfa', ['password' => self::PASSWORD, 'code' => '000000'])->assertUnprocessable();
        $this->deleteJson('/api/v1/auth/mfa', ['password' => self::PASSWORD])->assertUnprocessable()->assertJsonValidationErrors(['code', 'recovery_code']);
        $this->assertNotNull(DB::table('users')->where('id', $user->id)->value('mfa_enabled_at'));

        $this->deleteJson('/api/v1/auth/mfa', ['password' => self::PASSWORD, 'code' => $this->freshCode($mfa['secret'])])->assertOk()->assertJsonPath('data.enabled', false);
        $row = DB::table('users')->where('id', $user->id)->first(['mfa_secret_encrypted', 'mfa_enabled_at', 'mfa_recovery_codes', 'mfa_last_used_step']);
        $this->assertNull($row->mfa_secret_encrypted);
        $this->assertNull($row->mfa_enabled_at);
        $this->assertNull($row->mfa_recovery_codes);
        $this->assertSame(1, AuditLog::query()->where('action', 'mfa.disabled')->count());
        // Sin MFA, el inicio de sesión vuelve a ser de un solo paso.
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk()->assertJsonPath('data.token_type', 'Bearer');
    }

    public function test_enabling_mfa_revokes_the_other_login_tokens(): void
    {
        [, $user] = $this->owner();
        $old = $user->createToken('api-login', ['*'])->plainTextToken;
        $connector = $user->createToken('datasource:1', ['imports.manage']);
        Sanctum::actingAs($user->refresh(), ['*']);

        $secret = $this->postJson('/api/v1/auth/mfa/setup', ['password' => self::PASSWORD])->json('data.secret');
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->totp($secret), 'password' => self::PASSWORD])->assertOk()->assertJsonPath('data.revoked_login_tokens', 1);

        $this->assertNull(DB::table('personal_access_tokens')->where('name', 'api-login')->value('id'));
        $this->assertNotNull(DB::table('personal_access_tokens')->where('id', $connector->accessToken->id)->value('id'), 'un token de conector no es un inicio de sesión');
        $this->assertNotEmpty($old);
    }

    // ── Sin secretos en registros ────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_no_secret_or_code_reaches_logs_audit_or_later_responses(): void
    {
        $logged = [];
        Log::listen(function ($event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        [$tenant, $user] = $this->owner();
        Sanctum::actingAs($user->refresh(), ['*']);

        $secret = $this->postJson('/api/v1/auth/mfa/setup', ['password' => self::PASSWORD])->json('data.secret');
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '111111', 'password' => self::PASSWORD]);
        $confirmCode = $this->totp($secret);
        $recovery = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $confirmCode, 'password' => self::PASSWORD])->json('data.recovery_codes');
        $stepCode = $this->freshCode($secret);
        $this->postJson('/api/v1/auth/mfa/step-up', ['code' => $stepCode]);
        $this->postJson('/api/v1/auth/mfa/step-up', ['recovery_code' => $recovery[0]]);
        $this->postJson('/api/v1/auth/mfa/step-up', ['code' => '222222']);

        $haystack = implode("\n", $logged).DB::table('audit_logs')->get()->map(fn ($r) => json_encode($r))->implode("\n");
        $secrets = [$secret, $confirmCode, $stepCode, '111111', '222222', ...$recovery, ...array_map(fn ($c) => str_replace('-', '', $c), $recovery)];
        foreach ($secrets as $needle) {
            $this->assertStringNotContainsString($needle, $haystack, 'un secreto o código apareció en un registro');
        }
        $this->assertGreaterThan(0, AuditLog::query()->where('action', 'like', 'mfa.%')->count(), 'la auditoría registra los hechos');
        // Lecturas posteriores: nunca devuelven el secreto ni los hashes.
        foreach (['/api/v1/auth/me', '/api/v1/auth/mfa', '/api/v1/tenant/users'] as $uri) {
            $body = $this->api($user, $tenant)->getJson($uri)->getContent();
            $this->assertStringNotContainsString($secret, $body);
            $this->assertStringNotContainsString('mfa_secret_encrypted', $body);
            $this->assertStringNotContainsString('mfa_recovery_codes', $body);
        }
    }

    // ── Política por empresa y superadministrador ────────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_company_policy_makes_mfa_mandatory_for_owners_and_admins(): void
    {
        [$tenant, $owner] = $this->owner();
        $admin = $this->addUserToTenant($tenant, TenantRole::Admin);
        $analyst = $this->addUserToTenant($tenant, TenantRole::Analyst);

        // Quien activa la política debe tener MFA: no puede bloquearse a sí mismo.
        $this->api($owner, $tenant)->putJson('/api/v1/tenant/security-policy', ['mfa_required_for_privileged' => true])->assertUnprocessable()->assertJsonPath('code', 'mfa_required_to_enable_policy');
        $mfa = $this->enableMfa($owner);
        $this->api($owner, $tenant)->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->freshCode($mfa['secret'])])->assertOk();
        $this->api($owner, $tenant)->getJson('/api/v1/tenant/security-policy')->assertOk()->assertJsonPath('data.privileged_without_mfa', 1)->assertJsonPath('data.privileged_members', 2);
        $this->api($owner, $tenant)->putJson('/api/v1/tenant/security-policy', ['mfa_required_for_privileged' => true])->assertOk()->assertJsonPath('data.mfa_required_for_privileged', true);
        $this->assertTrue((bool) data_get(Tenant::query()->find($tenant->id)->settings, 'security.mfa_required_for_privileged'));

        // El administrador sin MFA puede iniciar sesión y activarlo, pero no operar la empresa.
        $this->api($admin, $tenant)->getJson('/api/v1/tenant')->assertForbidden()->assertJsonPath('code', 'mfa_enrollment_required');
        $this->api($admin, $tenant)->getJson('/api/v1/products')->assertForbidden()->assertJsonPath('code', 'mfa_enrollment_required');
        Sanctum::actingAs($admin->refresh(), ['*']);
        $this->getJson('/api/v1/auth/me')->assertOk();
        $secret = $this->postJson('/api/v1/auth/mfa/setup', ['password' => self::PASSWORD])->assertCreated()->json('data.secret');
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->totp($secret), 'password' => self::PASSWORD])->assertOk();
        $this->api($admin->refresh(), $tenant)->getJson('/api/v1/products')->assertOk();
        // Con la política vigente no se puede desactivar el segundo factor.
        $this->deleteJson('/api/v1/auth/mfa', ['password' => self::PASSWORD, 'code' => $this->freshCode($secret)])->assertStatus(409)->assertJsonPath('code', 'mfa_required_by_policy');

        // La política no alcanza a quien no es privilegiado.
        $this->api($analyst, $tenant)->getJson('/api/v1/products')->assertOk();
    }

    public function test_the_superadmin_must_have_mfa_where_the_platform_requires_it_and_steps_up_to_change_things(): void
    {
        config(['security.mfa.superadmin_required' => true]);
        $root = User::factory()->create(['is_superadmin' => true]);
        [$tenant] = $this->createTenantWithUser();

        Sanctum::actingAs($root->refresh(), ['*']);
        $this->getJson('/api/v1/admin/tenants')->assertForbidden()->assertJsonPath('code', 'mfa_enrollment_required');

        $mfa = $this->enableMfa($root);
        $this->getJson('/api/v1/admin/tenants')->assertOk();   // las lecturas de plataforma no piden un segundo factor fresco
        $this->patchJson("/api/v1/admin/tenants/{$tenant->uuid}", ['status' => 'suspended'])->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
        $this->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->freshCode($mfa['secret'])])->assertOk();
        $this->patchJson("/api/v1/admin/tenants/{$tenant->uuid}", ['status' => 'suspended'])->assertOk();
        // El superadministrador no puede desactivar el MFA que la plataforma le exige.
        $this->deleteJson('/api/v1/auth/mfa', ['password' => self::PASSWORD, 'code' => $this->freshCode($mfa['secret'])])->assertStatus(409)->assertJsonPath('code', 'mfa_required_by_policy');
    }

    public function test_by_default_the_platform_requirement_follows_the_environment(): void
    {
        config(['security.mfa.superadmin_required' => null]);
        $policy = app(MfaPolicy::class);
        $this->assertFalse($policy->superadminRequires(), 'en pruebas/desarrollo no estorba');
        $this->app['env'] = 'production';
        $this->assertTrue($policy->superadminRequires(), 'en producción es obligatorio');
    }

    public function test_the_platform_resets_a_lost_second_factor_with_a_reason_and_only_the_platform(): void
    {
        [$tenant, $owner] = $this->owner();
        $this->enableMfa($owner);
        $owner->createToken('api-login', ['*']);
        $root = User::factory()->create(['is_superadmin' => true]);

        // Ni un propietario de empresa ni un administrador de otra empresa puede hacerlo.
        $this->api($owner, $tenant)->postJson("/api/v1/admin/users/{$owner->id}/mfa-reset", ['reason' => 'Perdí mi teléfono y los códigos'])->assertForbidden();

        Sanctum::actingAs($root->refresh(), ['*']);
        $this->postJson("/api/v1/admin/users/{$owner->id}/mfa-reset", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/users/{$owner->id}/mfa-reset", ['reason' => 'corto'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/users/{$owner->id}/mfa-reset", ['reason' => 'Verificación de identidad por videollamada con el propietario'])->assertOk()
            ->assertJsonPath('data.mfa_enabled', false)->assertJsonPath('data.revoked_login_tokens', 1);

        $this->assertFalse($owner->refresh()->hasMfaEnabled());
        $log = AuditLog::query()->where('action', 'mfa.reset_by_platform')->firstOrFail();
        $this->assertSame($root->id, $log->user_id);
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => self::PASSWORD])->assertOk()->assertJsonPath('data.token_type', 'Bearer');
    }

    public function test_a_user_cannot_use_another_persons_challenge_or_codes(): void
    {
        [, $alice] = $this->owner();
        [, $bob] = $this->owner();
        $aliceMfa = $this->enableMfa($alice);
        $this->enableMfa($bob);
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $bob->email, 'password' => self::PASSWORD])->json('data.challenge');

        // Bob tiene el desafío; el código de Alice no lo completa (el desafío ata a Bob).
        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $challenge, 'code' => $this->freshCode($aliceMfa['secret'])])->assertUnprocessable()->assertJsonPath('code', 'mfa_invalid_code');
        $this->assertSame(0, (int) DB::table('personal_access_tokens')->where('tokenable_id', $bob->id)->count());
    }
}
