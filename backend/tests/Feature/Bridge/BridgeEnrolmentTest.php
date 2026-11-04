<?php

declare(strict_types=1);

namespace Tests\Feature\Bridge;

use App\Enums\TenantRole;
use App\Models\BridgeDevice;
use App\Services\Bridge\BridgeEnrolmentService;
use App\Services\Bridge\BridgeSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Bridge\Support\FakeBridgeDevice;
use Tests\Feature\Bridge\Support\InteractsWithBridge;
use Tests\TestCase;

/**
 * W6-B · Alta del dispositivo Bridge (ADR-V25-10): código de registro de un solo uso generado por un administrador, canje con la clave pública y
 * la prueba de posesión, huella y token con hash, aislamiento entre empresas y permisos.
 */
final class BridgeEnrolmentTest extends TestCase
{
    use InteractsWithBridge, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBridgeTenant();
    }

    public function test_bridge_source_starts_inactive_and_connector_declares_honest_state(): void
    {
        $source = $this->getJson('/api/v1/data-sources')->assertOk()->json('data.0');
        $this->assertSame('inactive', $source['status']);
        $this->assertStringContainsString('código de registro', (string) $source['last_error']);
        $connectors = collect($this->getJson('/api/v1/connectors')->assertOk()->json('data'))->keyBy('key');
        $this->assertSame('tested_with_test_database', $connectors['predictily_bridge']['verification']);
        $this->assertNotSame('available', $connectors['predictily_bridge']['status']);
        $this->assertSame('planned', $connectors['generic_odbc']['status']);
    }

    public function test_enrolment_issues_a_single_use_code_stored_only_as_hash_and_returns_the_token_once(): void
    {
        $issued = $this->postJson("/api/v1/data-sources/{$this->sourceId}/bridge/enrolments", ['device_name_hint' => 'Bodega Quito', 'ttl_minutes' => 30])->assertCreated();
        $code = (string) $issued->json('data.code');
        $this->assertMatchesRegularExpression('/^BRG(-[0-9A-HJKMNP-TV-Z]{4}){5}$/', $code);
        $this->assertTrue($issued->json('data.single_use'));
        $this->assertTrue(now()->addMinutes(31)->greaterThan($issued->json('data.expires_at')));

        $row = DB::table('bridge_enrolment_codes')->where('tenant_id', $this->tenant->id)->first();
        $this->assertSame(BridgeEnrolmentService::hashCode(BridgeEnrolmentService::normalize($code)), $row->code_hash);
        $this->assertStringNotContainsString(BridgeEnrolmentService::normalize($code), json_encode($row));
        $this->assertNull($row->consumed_at);

        $device = new FakeBridgeDevice;
        $response = $this->postJson('/api/v1/bridge/enrol', $device->enrolPayload($code))->assertCreated();
        $token = (string) $response->json('data.token');
        $this->assertMatchesRegularExpression('/^brt_[A-Za-z0-9_-]{43}$/', $token);
        $response->assertJsonPath('data.tenant.uuid', $this->tenant->uuid)->assertJsonPath('data.data_source.id', $this->sourceId)->assertJsonPath('data.protocol', 1);

        // Solo hashes en la base: ni el token ni la clave pública en claro; la huella es el SHA-256 de la clave pública.
        $stored = DB::table('bridge_devices')->where('id', $response->json('data.device_id'))->first();
        $this->assertSame(hash('sha256', $token), $stored->token_hash);
        $this->assertSame($device->fingerprint(), $stored->public_key_fingerprint);
        $this->assertSame($this->tenant->id, (int) $stored->tenant_id);
        $this->assertSame($this->sourceId, (int) $stored->data_source_id);
        $this->assertStringNotContainsString($token, json_encode($stored));
        $this->assertNotNull(DB::table('bridge_enrolment_codes')->where('id', $row->id)->value('consumed_at'));
        $this->assertSame($stored->id, (int) DB::table('bridge_enrolment_codes')->where('id', $row->id)->value('consumed_by_device_id'));

        // El token no vuelve a aparecer en la administración ni en la bitácora.
        $listing = $this->getJson('/api/v1/bridge/devices')->assertOk();
        $this->assertStringNotContainsString($token, $listing->getContent());
        $this->assertStringNotContainsString('token_hash', $listing->getContent());
        $this->assertStringNotContainsString($token, json_encode(DB::table('audit_logs')->get()));
        $this->assertStringNotContainsString(BridgeEnrolmentService::normalize($code), json_encode(DB::table('audit_logs')->get()));

        // La primera conexión activa la fuente.
        $this->assertSame('active', $this->getJson('/api/v1/data-sources')->json('data.0.status'));
    }

    public function test_code_is_single_use_and_expires_and_replies_do_not_reveal_which(): void
    {
        $code = $this->issueCode();
        $first = new FakeBridgeDevice;
        $this->postJson('/api/v1/bridge/enrol', $first->enrolPayload($code))->assertCreated();

        $second = new FakeBridgeDevice;
        $reused = $this->postJson('/api/v1/bridge/enrol', $second->enrolPayload($code))->assertStatus(422)->assertJsonPath('code', 'invalid_enrolment_code');

        $expiring = $this->issueCode(null, ['ttl_minutes' => 1]);
        $this->travel(2)->minutes();
        $expired = $this->postJson('/api/v1/bridge/enrol', (new FakeBridgeDevice)->enrolPayload($expiring))->assertStatus(422)->assertJsonPath('code', 'invalid_enrolment_code');
        $unknown = $this->postJson('/api/v1/bridge/enrol', (new FakeBridgeDevice)->enrolPayload('BRG-AAAA-BBBB-CCCC-DDDD-EEEE'))->assertStatus(422)->assertJsonPath('code', 'invalid_enrolment_code');
        $this->assertSame($reused->json('message'), $expired->json('message'));
        $this->assertSame($reused->json('message'), $unknown->json('message'));
        $this->assertSame(1, DB::table('bridge_devices')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_enrolment_requires_proof_of_key_possession_and_a_sane_clock(): void
    {
        $code = $this->issueCode();
        $device = new FakeBridgeDevice;
        $payload = $device->enrolPayload($code);

        // Prueba firmada por OTRA clave: quien solo conoce la clave pública no puede registrarla.
        $forged = $payload;
        $forged['proof'] = BridgeSignature::encode(sodium_crypto_sign_detached('otra cosa', (new FakeBridgeDevice)->secretKey));
        $this->postJson('/api/v1/bridge/enrol', $forged)->assertStatus(422)->assertJsonPath('code', 'invalid_proof');

        // Clave pública que no es Ed25519 de 32 bytes.
        $short = $payload;
        $short['public_key'] = BridgeSignature::encode('corta');
        $this->postJson('/api/v1/bridge/enrol', $short)->assertStatus(422)->assertJsonPath('code', 'invalid_public_key');

        // Reloj desfasado.
        $this->postJson('/api/v1/bridge/enrol', $device->enrolPayload($code, time() - 4000))->assertStatus(422)->assertJsonPath('code', 'device_clock_skew');

        // El intento fallido no consume el código.
        $this->postJson('/api/v1/bridge/enrol', $payload)->assertCreated();
    }

    public function test_only_bridge_sources_admit_devices_and_only_admins_issue_codes(): void
    {
        $other = (int) $this->postJson('/api/v1/data-sources', ['type' => 'file_upload', 'name' => 'Archivo'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/data-sources/{$other}/bridge/enrolments")->assertStatus(422)->assertJsonPath('code', 'not_a_bridge_source');

        foreach ([TenantRole::Analyst, TenantRole::Viewer] as $role) {
            $user = $this->addUserToTenant($this->tenant, $role);
            $this->actingAsTenantUser($user, $this->tenant);
            $this->postJson("/api/v1/data-sources/{$this->sourceId}/bridge/enrolments")->assertForbidden();
            $this->getJson('/api/v1/bridge/devices')->assertForbidden();
        }
        $this->assertSame(0, DB::table('bridge_enrolment_codes')->count());
    }

    public function test_other_company_cannot_issue_codes_list_show_revoke_or_reset_devices(): void
    {
        $device = $this->enrolDevice();
        [$tenantB, $ownerB] = $this->createTenantWithUser();
        $this->actingAsTenantUser($ownerB, $tenantB);

        $this->postJson("/api/v1/data-sources/{$this->sourceId}/bridge/enrolments")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/bridge/devices')->assertOk()->json('data'));
        $this->getJson("/api/v1/bridge/devices/{$device->deviceId}")->assertNotFound();
        $this->postJson("/api/v1/bridge/devices/{$device->deviceId}/revoke")->assertNotFound();
        $this->deleteJson("/api/v1/bridge/devices/{$device->deviceId}/checkpoints/sales")->assertNotFound();
        $this->assertNull(DB::table('bridge_devices')->where('id', $device->deviceId)->value('revoked_at'));
        $this->assertSame(0, DB::table('bridge_enrolment_codes')->where('tenant_id', $tenantB->id)->count());
    }

    public function test_device_cannot_be_registered_twice_with_the_same_active_key(): void
    {
        $device = $this->enrolDevice();
        $code = $this->issueCode();
        $this->postJson('/api/v1/bridge/enrol', $device->enrolPayload($code))->assertStatus(409)->assertJsonPath('code', 'public_key_in_use');
        // El canje fallido no consume el código.
        $this->postJson('/api/v1/bridge/enrol', (new FakeBridgeDevice)->enrolPayload($code))->assertCreated();
    }

    public function test_enrolment_is_throttled_per_ip(): void
    {
        config(['bridge.enrol_attempts_per_minute' => 3]);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/bridge/enrol', (new FakeBridgeDevice)->enrolPayload('BRG-AAAA-BBBB-CCCC-DDDD-EEEE'))->assertStatus(422);
        }
        $this->postJson('/api/v1/bridge/enrol', (new FakeBridgeDevice)->enrolPayload('BRG-AAAA-BBBB-CCCC-DDDD-EEEE'))->assertStatus(429);
    }

    public function test_device_model_hides_token_hash_and_status_reflects_heartbeat(): void
    {
        $device = $this->enrolDevice();
        $model = BridgeDevice::withoutTenantScope()->findOrFail($device->deviceId);
        $this->assertArrayNotHasKey('token_hash', $model->toArray());
        $this->assertSame('active', $model->status());
        $this->travel(2)->hours();
        $this->assertSame('offline', $model->refresh()->status());
    }
}
