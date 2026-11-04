<?php

declare(strict_types=1);

namespace Tests\Feature\Bridge;

use App\Models\BridgeDevice;
use App\Services\Bridge\BridgeSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\Feature\Bridge\Support\FakeBridgeDevice;
use Tests\Feature\Bridge\Support\InteractsWithBridge;
use Tests\TestCase;

/**
 * W6-B · Autenticación del dispositivo: token + firma Ed25519 por petición, anti-repetición, deriva de reloj y REVOCACIÓN inmediata.
 */
final class BridgeDeviceAuthTest extends TestCase
{
    use InteractsWithBridge, InteractsWithTenants, RefreshDatabase;

    private FakeBridgeDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');   // los lotes de prueba no se escriben en el disco real
        $this->setUpBridgeTenant();
        $this->device = $this->enrolDevice();
    }

    public function test_heartbeat_authenticates_with_token_and_signature_and_updates_last_seen(): void
    {
        DB::table('bridge_devices')->where('id', $this->device->deviceId)->update(['last_seen_at' => now()->subHour(), 'agent_version' => null]);
        $this->travel(1)->minutes();
        $response = $this->deviceCall($this->device, 'POST', '/api/v1/bridge/heartbeat', [
            'agent_version' => '0.1.7', 'platform' => 'windows',
            'health' => ['status' => 'ok', 'pending_batches' => 0, 'last_error' => null, 'entities' => [['entity' => 'sales', 'status' => 'idle', 'rows_sent' => 12]], 'source' => ['kind' => 'postgresql', 'writable_account' => false]],
        ])->assertOk();
        $response->assertJsonPath('data.status', 'active')->assertJsonPath('data.protocol', 1)->assertJsonPath('data.limits.max_checkpoint_length', 200);
        $this->assertContains('sales', $response->json('data.entities'));

        $row = DB::table('bridge_devices')->where('id', $this->device->deviceId)->first();
        $this->assertSame('0.1.7', $row->agent_version);
        $this->assertSame('windows', $row->platform);
        $this->assertTrue(now()->diffInSeconds($row->last_seen_at, true) < 5);
        $shown = $this->getJson("/api/v1/bridge/devices/{$this->device->deviceId}")->assertOk();
        $shown->assertJsonPath('data.status', 'active')->assertJsonPath('data.health.status', 'ok')->assertJsonPath('data.health.source.kind', 'postgresql');
    }

    public function test_missing_unknown_or_malformed_credentials_are_rejected(): void
    {
        $this->call('POST', '/api/v1/bridge/heartbeat', [], [], [], ['HTTP_ACCEPT' => 'application/json'], '{}')->assertStatus(401)->assertJsonPath('code', 'device_unauthorized');
        $this->deviceCall($this->device, 'POST', '/api/v1/bridge/heartbeat', [], ['token' => 'brt_'.BridgeSignature::encode(random_bytes(32))])->assertStatus(401)->assertJsonPath('code', 'device_unauthorized');
        $this->deviceCall($this->device, 'POST', '/api/v1/bridge/heartbeat', [], ['token' => 'no-es-un-token'])->assertStatus(401)->assertJsonPath('code', 'device_unauthorized');
        // Un token de usuario (Sanctum) no sirve en las rutas del agente.
        $user = $this->owner->createToken('x', ['*'])->plainTextToken;
        $this->call('POST', '/api/v1/bridge/heartbeat', [], [], [], ['HTTP_AUTHORIZATION' => "Bearer {$user}", 'HTTP_ACCEPT' => 'application/json'], '{}')->assertStatus(401);
    }

    public function test_token_without_the_device_key_is_useless(): void
    {
        // Token válido, pero firmado por OTRA clave (un token robado sin la clave privada del dispositivo).
        $thief = new FakeBridgeDevice;
        $thief->token = $this->device->token;
        $this->deviceCall($thief, 'POST', '/api/v1/bridge/heartbeat', [])->assertStatus(401)->assertJsonPath('code', 'device_signature_invalid');

        // Clave correcta anunciada pero firma de otra clave.
        $server = $this->device->signedServerVars('POST', '/api/v1/bridge/heartbeat', '{}');
        $server['HTTP_X_BRIDGE_SIGNATURE'] = $thief->signedServerVars('POST', '/api/v1/bridge/heartbeat', '{}')['HTTP_X_BRIDGE_SIGNATURE'];
        $this->call('POST', '/api/v1/bridge/heartbeat', [], [], [], $server, '{}')->assertStatus(401)->assertJsonPath('code', 'device_signature_invalid');

        // Sin encabezados de firma.
        $this->call('POST', '/api/v1/bridge/heartbeat', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->device->token, 'HTTP_ACCEPT' => 'application/json'], '{}')
            ->assertStatus(401)->assertJsonPath('code', 'device_signature_invalid');
    }

    public function test_signature_covers_method_path_query_and_body(): void
    {
        $body = '{"agent_version":"1"}';
        $server = $this->device->signedServerVars('POST', '/api/v1/bridge/heartbeat', $body);
        $this->call('POST', '/api/v1/bridge/heartbeat', [], [], [], $server, '{"agent_version":"2"}')->assertStatus(401)->assertJsonPath('code', 'device_signature_invalid');

        // Firma de otra ruta reutilizada en esta.
        $other = $this->device->signedServerVars('POST', '/api/v1/bridge/checkpoints', $body);
        $this->call('POST', '/api/v1/bridge/heartbeat', [], [], [], $other, $body)->assertStatus(401)->assertJsonPath('code', 'device_signature_invalid');

        // Firma de otro método.
        $get = $this->device->signedServerVars('GET', '/api/v1/bridge/checkpoints');
        $this->call('POST', '/api/v1/bridge/checkpoints', [], [], [], $get, '')->assertStatus(401);
    }

    public function test_replayed_requests_and_clock_skew_are_rejected(): void
    {
        $server = $this->device->signedServerVars('POST', '/api/v1/bridge/heartbeat', '{}');
        $this->call('POST', '/api/v1/bridge/heartbeat', [], [], [], $server, '{}')->assertOk();
        $this->call('POST', '/api/v1/bridge/heartbeat', [], [], [], $server, '{}')->assertStatus(401)->assertJsonPath('code', 'device_replay');

        $old = $this->deviceCall($this->device, 'POST', '/api/v1/bridge/heartbeat', [], ['timestamp' => time() - 4000])->assertStatus(401);
        $old->assertJsonPath('code', 'device_clock_skew');
        $this->assertEqualsWithDelta(time(), $old->json('context.server_time'), 5);
    }

    public function test_revocation_is_immediate_for_every_endpoint_and_pending_upload_urls(): void
    {
        $rows = FakeBridgeDevice::salesRows('REV');
        $batch = FakeBridgeDevice::batch($rows);
        $declare = $this->deviceCall($this->device, 'POST', '/api/v1/bridge/uploads', [
            'entity' => 'sales', 'sha256' => $batch['sha256'], 'size_bytes' => $batch['size_bytes'], 'row_count' => $batch['row_count'], 'content_type' => 'application/gzip', 'columns' => $batch['columns'],
        ])->assertCreated();
        $path = (string) $declare->json('data.path');

        $this->postJson("/api/v1/bridge/devices/{$this->device->deviceId}/revoke", ['reason' => 'Equipo retirado'])->assertOk()
            ->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.revoked_by', $this->owner->id);

        foreach ([['POST', '/api/v1/bridge/heartbeat', []], ['GET', '/api/v1/bridge/checkpoints', null], ['POST', '/api/v1/bridge/checkpoints', ['run_id' => 'run-0001', 'entity' => 'sales', 'run_complete' => false]],
            ['POST', '/api/v1/bridge/uploads', []]] as [$method, $uri, $json]) {
            $this->deviceCall($this->device, $method, $uri, $json)->assertStatus(401)->assertJsonPath('code', 'device_revoked');
        }
        // La URL prefirmada que ya estaba emitida (y es válida) tampoco sube nada.
        $this->putBatch($path, $batch['bytes'])->assertStatus(401)->assertJsonPath('code', 'device_revoked');
        $this->assertSame(0, DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, DB::table('sales')->where('tenant_id', $this->tenant->id)->count());

        // Revocar es idempotente y deja la fuente inactiva (era su único dispositivo).
        $this->postJson("/api/v1/bridge/devices/{$this->device->deviceId}/revoke")->assertOk();
        $this->assertSame('inactive', $this->getJson('/api/v1/data-sources')->json('data.0.status'));
        $this->assertNotNull(DB::table('audit_logs')->where('action', 'bridge.device_revoked')->first());

        // Un dispositivo nuevo vuelve a activar la fuente.
        $this->enrolDevice();
        $this->assertSame('active', $this->getJson('/api/v1/data-sources')->json('data.0.status'));
    }

    public function test_revoking_one_device_does_not_affect_another_of_the_same_source(): void
    {
        $second = $this->enrolDevice();
        $this->postJson("/api/v1/bridge/devices/{$this->device->deviceId}/revoke")->assertOk();
        $this->deviceCall($this->device, 'POST', '/api/v1/bridge/heartbeat', [])->assertStatus(401);
        $this->deviceCall($second, 'POST', '/api/v1/bridge/heartbeat', [])->assertOk();
        $this->assertSame('active', $this->getJson('/api/v1/data-sources')->json('data.0.status'));
    }

    public function test_device_of_another_company_never_touches_this_companys_data(): void
    {
        // Empresa B con su propia fuente y dispositivo.
        [$tenantB, $ownerB] = $this->createTenantWithUser();
        $this->createBranch($tenantB, '001');
        $this->actingAsTenantUser($ownerB, $tenantB);
        $sourceB = $this->createBridgeSource('Fuente B');
        $deviceB = $this->enrolDevice($sourceB);

        // La empresa A sube un lote.
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $upload = $this->uploadBatch($this->device, FakeBridgeDevice::salesRows('A'));
        $this->assertSame(201, $upload['put']->status());

        // El dispositivo B no ve ni confirma nada de A: sus checkpoints están vacíos y citar el lote de A es «no encontrado».
        $this->assertSame([], $this->deviceCall($deviceB, 'GET', '/api/v1/bridge/checkpoints')->assertOk()->json('data'));
        $this->deviceCall($deviceB, 'POST', '/api/v1/bridge/checkpoints', ['run_id' => 'run-0001', 'run_batch_index' => 1, 'entity' => 'sales', 'batch_sha256' => $upload['batch']['sha256'], 'checkpoint' => 'x', 'run_complete' => false])
            ->assertStatus(409)->assertJsonPath('code', 'batch_not_found');
        // El mismo contenido subido por B es un objeto de B (no «duplicado» del de A): cada empresa tiene su Bronze.
        $other = $this->uploadBatch($deviceB, FakeBridgeDevice::salesRows('A'));
        $this->assertSame('upload', $other['declare']->json('data.status'));
        $this->assertSame(201, $other['put']->status());
        $this->assertSame(1, DB::table('bronze_objects')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, DB::table('bronze_objects')->where('tenant_id', $tenantB->id)->count());

        // Una URL prefirmada emitida para A, pero reclamando otra empresa, no sirve (la firma cubre la empresa).
        $declare = $this->deviceCall($this->device, 'POST', '/api/v1/bridge/uploads', array_merge(
            ['entity' => 'sales', 'content_type' => 'application/gzip'],
            ['sha256' => hash('sha256', 'x'), 'size_bytes' => 1, 'row_count' => 1, 'columns' => ['document_number' => 'string', 'issued_at' => 'string', 'product_sku' => 'string', 'quantity' => 'int', 'unit_price' => 'string']],
        ))->assertCreated();
        $tampered = str_replace($this->tenant->uuid, $tenantB->uuid, (string) $declare->json('data.path'));
        $this->putBatch($tampered, 'x')->assertForbidden();
    }

    public function test_source_deleted_or_company_suspended_blocks_the_device(): void
    {
        $this->deleteJson("/api/v1/data-sources/{$this->sourceId}")->assertNoContent();
        $this->deviceCall($this->device, 'POST', '/api/v1/bridge/heartbeat', [])->assertStatus(403)->assertJsonPath('code', 'data_source_unavailable');

        $other = $this->enrolDevice($this->createBridgeSource('Otra'));
        DB::table('tenants')->where('id', $this->tenant->id)->update(['status' => 'suspended']);
        $this->deviceCall($other, 'POST', '/api/v1/bridge/heartbeat', [])->assertStatus(423)->assertJsonPath('code', 'tenant_inactive');
    }

    public function test_device_requests_are_throttled(): void
    {
        config(['bridge.device_requests_per_minute' => 2]);
        $this->deviceCall($this->device, 'GET', '/api/v1/bridge/checkpoints')->assertOk();
        $this->deviceCall($this->device, 'GET', '/api/v1/bridge/checkpoints')->assertOk();
        $this->deviceCall($this->device, 'GET', '/api/v1/bridge/checkpoints')->assertStatus(429);
        $this->assertInstanceOf(BridgeDevice::class, BridgeDevice::withoutTenantScope()->find($this->device->deviceId));
    }
}
