<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Mfa\RecoveryCodes;
use App\Services\Auth\SessionRevoker;
use App\Support\Privacy\PiiMasker;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Security\Support\SecurityFixtures;
use Tests\TestCase;

/**
 * V2.5 · W6-A (revisión M2, L1, L2, L5 y supervivientes de pruebas): activar el segundo factor exige la contraseña actual; el desafío de inicio de sesión se completa una sola vez
 * incluso con peticiones paralelas; activar/desactivar/restablecer cierra las sesiones web; los códigos de recuperación sobreviven a la rotación de APP_KEY con APP_PREVIOUS_KEYS;
 * y los controles que las pruebas anteriores no fijaban (segundo factor fresco en la sesión de la SPA, campos ocultos, enmascarado, clave del HMAC, modelo cargado a medias).
 */
final class MfaHardeningTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase, SecurityFixtures;

    private const PASSWORD = 'password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
    }

    /**
     * Llamada de la SPA con su propia «jarra de cookies» (la sesión vive en la cookie, como en un navegador).
     *
     * @param  array<string, string>  $jar
     * @param  array<string, mixed>  $data
     */
    private function spa(string $method, string $uri, array $data = [], array &$jar = [], ?Tenant $tenant = null): TestResponse
    {
        Auth::forgetGuards();   // cada petición real parte de un guard limpio: sin esto el guard de sesión conserva a la persona de la llamada anterior dentro del mismo proceso de prueba
        $server = ['HTTP_ORIGIN' => 'http://localhost:5173', 'HTTP_REFERER' => 'http://localhost:5173/app', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($tenant !== null) {
            $server['HTTP_X_TENANT_ID'] = $tenant->uuid;
        }
        $response = $this->call($method, $uri, [], $jar, [], $server, $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR));
        foreach ($response->headers->getCookies() as $cookie) {
            $jar[$cookie->getName()] = (string) $cookie->getValue();
        }

        return $response;
    }

    /** Inicia sesión como la SPA (con segundo factor si lo hay) y devuelve la jarra de cookies. @return array<string, string> */
    private function spaLogin(User $user, ?string $secret = null): array
    {
        $jar = [];
        $login = $this->spa('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD], $jar);
        if ($secret !== null) {
            $login->assertStatus(202);
            $this->spa('POST', '/api/v1/auth/mfa/challenge', ['challenge' => $login->json('data.challenge'), 'code' => $this->freshCode($secret)], $jar)->assertOk();
        } else {
            $login->assertOk();
        }

        return $jar;
    }

    // ── M2: contraseña actual para activar el segundo factor ─────────────────────────────────────────────────────────────────────────────────

    public function test_m8_enrolment_needs_the_current_password_so_a_token_or_session_holder_cannot_enrol_their_own_authenticator(): void
    {
        [, $user] = $this->createTenantWithUser(TenantRole::Owner);
        Sanctum::actingAs($user->refresh(), ['*']);

        // Sin contraseña o con una incorrecta: nada se genera.
        $this->postJson('/api/v1/auth/mfa/setup')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->withHeader('User-Agent', 'Mozilla/5.0 (prueba)')->postJson('/api/v1/auth/mfa/setup', ['password' => 'no-es-la-clave'])->assertUnprocessable()->assertJsonPath('code', 'mfa_invalid_password');
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('mfa_secret_encrypted'), 'ni siquiera se generó un secreto');
        $this->assertSame(1, AuditLog::query()->where('action', 'mfa.password_failed')->count());

        $secret = $this->postJson('/api/v1/auth/mfa/setup', ['password' => self::PASSWORD])->assertCreated()->json('data.secret');
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->totp($secret)])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->totp($secret), 'password' => 'otra'])->assertUnprocessable()->assertJsonPath('code', 'mfa_invalid_password');
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('mfa_enabled_at'), 'un código correcto sin la contraseña no activa');
        $codes = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->totp($secret), 'password' => self::PASSWORD])->assertOk()->json('data.recovery_codes');
        $this->assertCount(10, $codes);

        // Regenerar los códigos de recuperación también exige la contraseña, además de un código vigente.
        $this->postJson('/api/v1/auth/mfa/recovery-codes', ['code' => $this->freshCode($secret)])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/mfa/recovery-codes', ['code' => $this->freshCode($secret), 'password' => 'otra'])->assertUnprocessable()->assertJsonPath('code', 'mfa_invalid_password');
        $this->postJson('/api/v1/auth/mfa/recovery-codes', ['code' => $this->freshCode($secret), 'password' => self::PASSWORD])->assertOk();

        // Quedó el rastro de seguridad: quién, cuándo, desde qué IP y con qué cliente (sin secretos ni códigos).
        $started = AuditLog::query()->where('action', 'mfa.enrolment_started')->firstOrFail();
        $this->assertSame($user->id, $started->user_id);
        $this->assertNotNull($started->ip_address);
        $this->assertNotNull(AuditLog::query()->where('action', 'mfa.enabled')->firstOrFail()->created_at);
        $failed = AuditLog::query()->where('action', 'mfa.password_failed')->get();
        $this->assertTrue($failed->contains(fn ($l) => str_contains((string) $l->user_agent, 'Mozilla/5.0')), 'el cliente queda registrado');
        $this->assertStringNotContainsString('no-es-la-clave', json_encode($failed->pluck('metadata'), JSON_THROW_ON_ERROR));
    }

    public function test_m8_wrong_passwords_count_against_the_same_lock_as_codes(): void
    {
        [, $user] = $this->createTenantWithUser(TenantRole::Owner);
        Sanctum::actingAs($user->refresh(), ['*']);

        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/v1/auth/mfa/setup', ['password' => 'mala-'.$i])->assertUnprocessable()->assertJsonPath('code', 'mfa_invalid_password');
        }
        $this->postJson('/api/v1/auth/mfa/setup', ['password' => 'mala-5'])->assertStatus(423)->assertJsonPath('code', 'mfa_locked');
        $this->postJson('/api/v1/auth/mfa/setup', ['password' => self::PASSWORD])->assertStatus(423);   // y la contraseña correcta tampoco desde esa IP
    }

    // ── L1: el desafío se completa una sola vez ──────────────────────────────────────────────────────────────────────────────────────────────

    public function test_l1_a_challenge_held_by_another_request_is_refused_and_the_code_is_not_consumed(): void
    {
        [, $user] = $this->createTenantWithUser(TenantRole::Owner);
        $mfa = $this->enableMfa($user);
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('data.challenge');
        $code = $this->freshCode($mfa['secret']);

        // Otra petición paralela con el MISMO desafío lo tiene tomado (candado de corta vida): esta no verifica ni emite nada.
        $lock = Cache::lock('mfa:challenge-lock:'.hash('sha256', $challenge), 15);
        $this->assertTrue($lock->get());
        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $challenge, 'code' => $code])->assertStatus(409)->assertJsonPath('code', 'mfa_challenge_busy');
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count(), 'ninguna sesión por la petición rechazada');
        $this->assertSame(0, AuditLog::query()->where('action', 'auth.login')->where('user_id', $user->id)->count());
        $lock->release();

        // Liberado el candado, el mismo código (que NO se consumió) completa el inicio de sesión una sola vez.
        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $challenge, 'code' => $code])->assertOk();
        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $challenge, 'code' => $this->freshCode($mfa['secret'])])->assertUnprocessable()->assertJsonPath('code', 'mfa_challenge_invalid');
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->where('name', 'api-login')->count(), 'exactamente UNA sesión por desafío');
    }

    public function test_l1_a_failed_verification_releases_the_challenge_for_a_retry(): void
    {
        [, $user] = $this->createTenantWithUser(TenantRole::Owner);
        $mfa = $this->enableMfa($user);
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->json('data.challenge');

        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $challenge, 'code' => '000000'])->assertUnprocessable()->assertJsonPath('code', 'mfa_invalid_code');
        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $challenge, 'code' => $this->freshCode($mfa['secret'])])->assertOk();
    }

    // ── L2: sesiones web ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_l2_enrolling_disabling_and_the_platform_reset_close_the_other_web_sessions_and_remember_cookies(): void
    {
        [, $user] = $this->createTenantWithUser(TenantRole::Owner);
        $a = $this->spaLogin($user);
        $b = $this->spaLogin($user);
        $this->spa('GET', '/api/v1/auth/me', [], $a)->assertOk();
        $this->spa('GET', '/api/v1/auth/me', [], $b)->assertOk();
        $this->travel(2)->seconds();   // el reloj de la prueba está congelado: las sesiones y la revocación no pueden caer en el mismo milisegundo
        $rememberBefore = DB::table('users')->where('id', $user->id)->value('remember_token');

        // Activar el segundo factor desde la sesión A cierra la B (se emitió sin segundo factor) y deja viva la A.
        $secret = $this->spa('POST', '/api/v1/auth/mfa/setup', ['password' => self::PASSWORD], $a)->assertCreated()->json('data.secret');
        $this->spa('POST', '/api/v1/auth/mfa/confirm', ['code' => $this->totp($secret), 'password' => self::PASSWORD], $a)->assertOk()->assertJsonPath('data.revoked_other_sessions', true);
        $this->spa('GET', '/api/v1/auth/me', [], $b)->assertUnauthorized();
        $this->spa('GET', '/api/v1/auth/me', [], $a)->assertOk();
        $this->assertNotSame($rememberBefore, DB::table('users')->where('id', $user->id)->value('remember_token'), 'las cookies «recordarme» quedan invalidadas');

        // Otra sesión nueva (ya con segundo factor); desactivarlo desde la A cierra la nueva y deja viva la A.
        $c = $this->spaLogin($user, $secret);
        $this->spa('GET', '/api/v1/auth/me', [], $c)->assertOk();
        $this->travel(2)->seconds();
        $this->spa('DELETE', '/api/v1/auth/mfa', ['password' => self::PASSWORD, 'code' => $this->freshCode($secret)], $a)->assertOk()->assertJsonPath('data.revoked_other_sessions', true);
        $this->spa('GET', '/api/v1/auth/me', [], $c)->assertUnauthorized();
        $this->spa('GET', '/api/v1/auth/me', [], $a)->assertOk();

        // El restablecimiento por la plataforma cierra TODAS, incluida la A.
        $this->travel(2)->seconds();
        $d = $this->spaLogin($user);
        $this->travel(2)->seconds();
        $root = User::factory()->create(['is_superadmin' => true]);
        $rootJar = $this->spaLogin($root);   // otra sesión (la del superadministrador de la plataforma), no una autenticación transitoria que taparía las sesiones de la persona
        $reset = $this->spa('POST', "/api/v1/admin/users/{$user->id}/mfa-reset", ['reason' => 'Verificación de identidad por videollamada'], $rootJar);
        $reset->assertOk()->assertJsonPath('data.revoked_sessions', true);
        $this->spa('GET', '/api/v1/auth/me', [], $a)->assertUnauthorized();
        $this->spa('GET', '/api/v1/auth/me', [], $d)->assertUnauthorized();
        // Iniciar sesión de nuevo funciona (la marca de emisión es posterior).
        $this->travel(2)->seconds();
        $e = $this->spaLogin($user);
        $this->spa('GET', '/api/v1/auth/me', [], $e)->assertOk();
    }

    // ── Supervivientes: segundo factor fresco en la sesión de la SPA ─────────────────────────────────────────────────────────────────────────

    public function test_the_second_factor_freshness_expires_on_the_spa_session_path(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner);
        $mfa = $this->enableMfa($user);
        $jar = $this->spaLogin($user, $mfa['secret']);   // el inicio de sesión con MFA cuenta como verificación

        $this->spa('PATCH', '/api/v1/tenant', ['name' => 'Nombre uno'], $jar, $tenant)->assertOk();
        $this->travel(599)->seconds();
        $this->spa('PATCH', '/api/v1/tenant', ['name' => 'Nombre dos'], $jar, $tenant)->assertOk();
        $this->travel(2)->seconds();
        $this->spa('PATCH', '/api/v1/tenant', ['name' => 'Nombre tres'], $jar, $tenant)->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
        // Otra sesión de la misma persona NO hereda la verificación.
        $other = $this->spaLogin($user, $mfa['secret']);
        $this->travel(601)->seconds();
        $this->spa('POST', '/api/v1/auth/mfa/step-up', ['code' => $this->freshCode($mfa['secret'])], $jar)->assertOk();
        $this->spa('PATCH', '/api/v1/tenant', ['name' => 'Nombre cuatro'], $jar, $tenant)->assertOk();
        $this->spa('PATCH', '/api/v1/tenant', ['name' => 'Nombre cinco'], $other, $tenant)->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
    }

    // ── Supervivientes: ocultos, enmascarado, clave del HMAC y modelo cargado a medias ───────────────────────────────────────────────────────

    public function test_the_mfa_columns_are_hidden_from_serialization(): void
    {
        [, $user] = $this->createTenantWithUser(TenantRole::Owner);
        $this->enableMfa($user);
        $fresh = User::query()->findOrFail($user->id);
        $this->assertNotNull($fresh->getAttributes()['mfa_secret_encrypted']);
        $this->assertNotNull($fresh->getAttributes()['mfa_recovery_codes']);

        $this->assertContains('mfa_secret_encrypted', $fresh->getHidden());
        $this->assertContains('mfa_recovery_codes', $fresh->getHidden());
        $json = json_encode($fresh, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('mfa_secret_encrypted', $json);
        $this->assertStringNotContainsString('mfa_recovery_codes', $json);
        $this->assertArrayNotHasKey('mfa_secret_encrypted', $fresh->toArray());
        $this->assertArrayNotHasKey('password', $fresh->toArray());
    }

    public function test_the_masker_removes_every_second_factor_secret_and_code(): void
    {
        $dirty = [];
        foreach (['otp', 'totp', 'totp_code', 'mfa_code', 'recovery_code', 'recovery_codes', 'mfa_secret', 'mfa_secret_encrypted', 'mfa_recovery_codes', 'challenge', 'otpauth_uri'] as $key) {
            $dirty[$key] = 'valor-secreto-'.$key;
        }
        $dirty['nested'] = ['recovery_codes' => ['AAAA-BBBB-CCCC-DDDD'], 'purpose' => 'step_up'];
        $clean = PiiMasker::sanitize($dirty);

        foreach (array_keys($dirty) as $key) {
            if ($key !== 'nested') {
                $this->assertSame('[REDACTED]', $clean[$key], $key);
            }
        }
        $this->assertSame('[REDACTED]', $clean['nested']['recovery_codes']);
        $this->assertSame('step_up', $clean['nested']['purpose'], 'lo que no es secreto se conserva');
        $this->assertStringNotContainsString('valor-secreto', json_encode($clean, JSON_THROW_ON_ERROR));
    }

    public function test_the_recovery_hash_is_a_keyed_hmac_not_a_plain_digest(): void
    {
        $code = 'ABCDEFGHJKMNPQRS';
        $current = RecoveryCodes::hash($code);

        $this->assertNotSame(hash('sha256', $code), $current, 'no es un SHA-256 simple');
        $this->assertNotSame(hash_hmac('sha256', $code, 'x'), $current);
        $this->assertNotSame($code, $current);
        $this->assertSame(64, strlen($current));
        // Depende de la clave de la aplicación: con otra clave el hash es otro, y una base robada no permite probar candidatos sin ella.
        $this->app->instance('encrypter', new Encrypter(random_bytes(32), (string) config('app.cipher')));
        $this->assertNotSame($current, RecoveryCodes::hash($code));
    }

    public function test_has_mfa_enabled_reads_the_database_when_the_model_is_loaded_partially_or_stale(): void
    {
        [, $user] = $this->createTenantWithUser(TenantRole::Owner);
        $partial = fn () => User::query()->select('id', 'name', 'email')->findOrFail($user->id);

        $this->assertFalse($partial()->hasMfaEnabled());
        $this->enableMfa($user);
        // Un modelo cargado a medias (sin las columnas MFA) NO se interpreta como «sin MFA»: se lee de la base.
        $this->assertTrue($partial()->hasMfaEnabled(), 'falla cerrado ante un modelo parcial');
        $this->assertFalse(array_key_exists('mfa_enabled_at', $partial()->getAttributes()));
        $this->assertTrue($user->hasMfaEnabled());
        // Un modelo nuevo sin guardar nunca tiene MFA.
        $this->assertFalse((new User)->hasMfaEnabled());
    }

    // ── L5: rotación de APP_KEY ──────────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_l5_recovery_codes_and_the_secret_survive_an_app_key_rotation_while_the_previous_key_is_configured(): void
    {
        $cipher = (string) config('app.cipher');
        $old = random_bytes(32);
        $new = random_bytes(32);
        $this->app->instance('encrypter', new Encrypter($old, $cipher));
        Facade::clearResolvedInstance('encrypter');
        [, $user] = $this->createTenantWithUser(TenantRole::Owner);
        $mfa = $this->enableMfa($user);   // secreto cifrado y códigos con hash de la clave VIEJA

        // Rotación con APP_PREVIOUS_KEYS: la clave nueva es la vigente y la vieja sigue verificando.
        $rotated = new Encrypter($new, $cipher);
        $rotated->previousKeys([$old]);
        $this->app->instance('encrypter', $rotated);
        Facade::clearResolvedInstance('encrypter');
        Sanctum::actingAs($user->refresh(), ['*']);

        $this->getJson('/api/v1/auth/mfa')->assertOk()->assertJsonPath('data.recovery_codes_on_previous_key', 10)->assertJsonPath('data.recovery_codes_remaining', 10);
        $this->postJson('/api/v1/auth/mfa/step-up', ['recovery_code' => $mfa['recovery'][0]])->assertOk()->assertJsonPath('data.method', 'recovery');
        $this->assertTrue((bool) AuditLog::query()->where('action', 'mfa.recovery_used')->firstOrFail()->metadata['previous_app_key'], 'el uso con la clave anterior queda auditado');
        $this->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->freshCode($mfa['secret'])])->assertOk()->assertJsonPath('data.method', 'totp');   // el secreto cifrado también se lee
        // Códigos nuevos se calculan con la clave vigente: ya no quedan códigos en la clave anterior.
        $this->postJson('/api/v1/auth/mfa/recovery-codes', ['code' => $this->freshCode($mfa['secret']), 'password' => self::PASSWORD])->assertOk();
        $this->getJson('/api/v1/auth/mfa')->assertJsonPath('data.recovery_codes_on_previous_key', 0);

        // Retirada la clave anterior SIN regenerar, un código no usado de la clave vieja deja de servir (documentado): hay que regenerar antes de retirarla.
        $this->app->instance('encrypter', new Encrypter($new, $cipher));
        Facade::clearResolvedInstance('encrypter');
        $victim = User::factory()->create();
        $this->app->instance('encrypter', new Encrypter($old, $cipher));
        Facade::clearResolvedInstance('encrypter');
        $legacy = $this->enableMfa($victim);
        $this->app->instance('encrypter', new Encrypter($new, $cipher));
        Facade::clearResolvedInstance('encrypter');
        Sanctum::actingAs($victim->refresh(), ['*']);
        $this->postJson('/api/v1/auth/mfa/step-up', ['recovery_code' => $legacy['recovery'][0]])->assertUnprocessable()->assertJsonPath('code', 'mfa_invalid_code');
    }

    // ── Segunda ronda de supervivientes (mutaciones del revisor) ─────────────────────────────────────────────────────────────────────────────

    public function test_the_lockout_policy_defaults_are_the_documented_ones(): void
    {
        // docs/security/ENTERPRISE_SECURITY.md: 5 fallos de una persona desde una IP, 30 de una IP, 15 de la persona desde varias IP; 15 minutos de bloqueo y de ventana.
        $this->assertSame(5, config('security.mfa.max_failed_attempts_per_ip_user'));
        $this->assertSame(30, config('security.mfa.max_failed_attempts_per_ip'));
        $this->assertSame(15, config('security.mfa.max_failed_attempts'));
        $this->assertSame(900, config('security.mfa.lockout_seconds'));
        $this->assertSame(1, config('security.mfa.window'));
        $this->assertSame(300, config('security.mfa.challenge_ttl_seconds'));
        $this->assertSame(600, config('security.mfa.step_up_ttl_seconds'));
        $this->assertGreaterThan(config('security.mfa.max_failed_attempts_per_ip_user'), config('security.mfa.max_failed_attempts'), 'el nivel de la persona escala más despacio que el de persona + IP');
        $this->assertGreaterThan(config('security.mfa.max_failed_attempts'), config('security.mfa.max_failed_attempts_per_ip'), 'el de la IP tolera varias personas');
    }

    public function test_a_locked_person_neither_extends_the_lock_nor_adds_attempts_when_others_keep_trying(): void
    {
        [, $user] = $this->createTenantWithUser(TenantRole::Owner);
        $this->enableMfa($user);
        $until = now()->addMinutes(10);
        DB::table('users')->where('id', $user->id)->update(['mfa_failed_attempts' => 20, 'mfa_locked_until' => $until]);
        Sanctum::actingAs($user->refresh(), ['*']);

        // Intentos desde una IP distinta cada vez (así el bloqueo que cuenta es el de la persona, en la base).
        foreach (['203.0.113.10', '203.0.113.11', '203.0.113.12'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/mfa/step-up', ['code' => '000000'])->assertStatus(423)->assertJsonPath('code', 'mfa_locked');
        }
        $row = DB::table('users')->where('id', $user->id)->first(['mfa_failed_attempts', 'mfa_locked_until']);
        $this->assertSame(20, (int) $row->mfa_failed_attempts, 'un intento bloqueado no suma: quien insiste no puede alargar el bloqueo de la víctima');
        $this->assertSame($until->getTimestamp(), Carbon::parse($row->mfa_locked_until)->getTimestamp(), 'el bloqueo no se extiende');
    }

    public function test_a_parallel_request_that_claims_the_same_step_first_wins_and_the_other_is_refused(): void
    {
        [, $user] = $this->createTenantWithUser(TenantRole::Owner);
        $mfa = $this->enableMfa($user);
        Sanctum::actingAs($user->refresh(), ['*']);
        $code = $this->freshCode($mfa['secret']);
        $claimed = intdiv(now()->getTimestamp(), 30) + 2;
        $raced = false;
        // La otra petición reclama el paso entre la lectura de la fila y el reclamo de ESTA petición (la carrera que el reclamo atómico debe resolver).
        DB::listen(function ($query) use (&$raced, $user, $claimed): void {
            if (! $raced && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"mfa_last_used_step"')) {
                $raced = true;
                DB::table('users')->where('id', $user->id)->update(['mfa_last_used_step' => $claimed]);
            }
        });

        $this->postJson('/api/v1/auth/mfa/step-up', ['code' => $code])->assertUnprocessable()->assertJsonPath('code', 'mfa_invalid_code');
        $this->assertTrue($raced, 'la carrera se simuló');
        $this->assertSame($claimed, (int) DB::table('users')->where('id', $user->id)->value('mfa_last_used_step'), 'el paso reclamado por la otra petición no se pisa');
    }

    // ── Revocación de sesiones: el instante exacto del corte ─────────────────────────────────────────────────────────────────────────────────

    public function test_l2_a_session_issued_exactly_at_the_cutoff_instant_is_closed_and_a_later_one_is_not(): void
    {
        [, $user] = $this->createTenantWithUser(TenantRole::Owner);
        $a = $this->spaLogin($user);
        $this->spa('GET', '/api/v1/auth/me', [], $a)->assertOk();

        // El reloj está congelado: el corte cae en el MISMO milisegundo en que se emitió la sesión (lo emitido «en o antes» del corte ya no vale).
        DB::table('users')->where('id', $user->id)->update(['sessions_valid_after' => SessionRevoker::nowMs()]);
        $this->spa('GET', '/api/v1/auth/me', [], $a)->assertUnauthorized();

        $this->travel(2)->seconds();
        $b = $this->spaLogin($user);   // emitida después del corte
        $this->spa('GET', '/api/v1/auth/me', [], $b)->assertOk();
    }
}
