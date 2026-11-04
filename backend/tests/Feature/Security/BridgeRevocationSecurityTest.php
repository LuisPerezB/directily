<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Bridge\Support\InteractsWithBridge;
use Tests\Feature\Security\Support\SecurityFixtures;
use Tests\TestCase;

/**
 * V2.5 · W6-A (ADR-V25-15) sobre el Bridge de W6-B (ADR-V25-10): la revocación de un dispositivo es una acción SENSIBLE (`datasources.manage`): quien tiene MFA activo necesita
 * un segundo factor fresco, y una vez revocado el dispositivo no puede enviar nada en su SIGUIENTE petición. (La mecánica del Bridge —firma, URL prefirmada, checkpoints— se prueba
 * en tests/Feature/Bridge; aquí solo el cruce con la seguridad.)
 */
final class BridgeRevocationSecurityTest extends TestCase
{
    use InteractsWithBridge, InteractsWithTenants, RefreshDatabase, SecurityFixtures;

    public function test_revoking_a_device_needs_a_fresh_second_factor_and_is_immediate(): void
    {
        $this->freezeClock();
        $this->setUpBridgeTenant();
        $device = $this->enrolDevice();
        $this->deviceCall($device, 'POST', '/api/v1/bridge/heartbeat', ['agent_version' => '0.1.0', 'platform' => 'linux', 'health' => ['status' => 'ok']])->assertOk();

        $mfa = $this->enableMfa($this->owner);
        $this->actingAsTenantUser($this->owner, $this->tenant)->postJson("/api/v1/bridge/devices/{$device->deviceId}/revoke", ['reason' => 'Equipo retirado'])
            ->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
        $this->assertNull(DB::table('bridge_devices')->where('id', $device->deviceId)->value('revoked_at'), 'sin segundo factor no se revoca');
        $this->deviceCall($device, 'POST', '/api/v1/bridge/heartbeat', ['agent_version' => '0.1.0', 'platform' => 'linux', 'health' => ['status' => 'ok']])->assertOk();

        $this->actingAsTenantUser($this->owner, $this->tenant)->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->freshCode($mfa['secret'])])->assertOk();
        $this->actingAsTenantUser($this->owner, $this->tenant)->postJson("/api/v1/bridge/devices/{$device->deviceId}/revoke", ['reason' => 'Equipo retirado'])->assertOk()->assertJsonPath('data.status', 'revoked');

        $this->deviceCall($device, 'POST', '/api/v1/bridge/heartbeat', ['agent_version' => '0.1.0', 'platform' => 'linux', 'health' => ['status' => 'ok']])
            ->assertStatus(401)->assertJsonPath('code', 'device_revoked');
        $this->deviceCall($device, 'GET', '/api/v1/bridge/checkpoints')->assertStatus(401)->assertJsonPath('code', 'device_revoked');
    }

    public function test_a_user_token_is_not_a_device_credential_and_a_device_token_is_not_a_user_token(): void
    {
        $this->setUpBridgeTenant();
        $device = $this->enrolDevice();
        $user = $this->owner->createToken('t', ['*'])->plainTextToken;

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        // El token del dispositivo no abre la API de usuario (ni con el encabezado de empresa).
        $this->withToken($device->token)->withHeader('X-Tenant-ID', $this->tenant->uuid)->getJson('/api/v1/products')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($user)->postJson('/api/v1/bridge/heartbeat', [])->assertUnauthorized();
    }
}
