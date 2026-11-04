<?php

declare(strict_types=1);

namespace Tests\Feature\Bridge\Support;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Bridge\BridgeEnrolmentService;
use Illuminate\Testing\TestResponse;

/** Ayudas de las pruebas del backend del Bridge: fuente Bridge, código, alta del dispositivo y llamadas firmadas. */
trait InteractsWithBridge
{
    protected Tenant $tenant;

    protected User $owner;

    protected int $sourceId;

    /** Empresa con propietario, sucursal 001 y una fuente Bridge (autenticado como el propietario). */
    protected function setUpBridgeTenant(): void
    {
        [$this->tenant, $this->owner] = $this->createTenantWithUser();
        $this->createBranch($this->tenant, '001', 'Matriz');
        $this->actingAsTenantUser($this->owner, $this->tenant);
        $this->sourceId = $this->createBridgeSource();
    }

    protected function createBridgeSource(string $name = 'ERP en la red del cliente'): int
    {
        return (int) $this->postJson('/api/v1/data-sources', ['type' => 'connector', 'connector_key' => BridgeEnrolmentService::CONNECTOR_KEY, 'name' => $name])
            ->assertCreated()->json('data.id');
    }

    protected function issueCode(?int $sourceId = null, array $body = []): string
    {
        return (string) $this->postJson('/api/v1/data-sources/'.($sourceId ?? $this->sourceId).'/bridge/enrolments', $body)->assertCreated()->json('data.code');
    }

    /** Registra un dispositivo nuevo contra la fuente (el administrador genera el código; el agente lo canjea). */
    protected function enrolDevice(?int $sourceId = null, ?FakeBridgeDevice $device = null): FakeBridgeDevice
    {
        $device ??= new FakeBridgeDevice;
        $code = $this->issueCode($sourceId);
        $response = $this->postJson('/api/v1/bridge/enrol', $device->enrolPayload($code))->assertCreated();
        $device->token = (string) $response->json('data.token');
        $device->deviceId = (int) $response->json('data.device_id');

        return $device;
    }

    /** @param array<string, mixed>|null $json */
    protected function deviceCall(FakeBridgeDevice $device, string $method, string $path, ?array $json = null, array $overrides = []): TestResponse
    {
        $body = $json !== null ? (string) json_encode($json) : '';
        $server = $device->signedServerVars($method, $path, $body, '', $overrides['timestamp'] ?? null, $overrides['nonce'] ?? null, $overrides['token'] ?? null);

        return $this->call($method, $path, [], [], [], $server, $body);
    }

    /** Declara y sube un lote (URL prefirmada) y devuelve ambas respuestas. @return array{declare: TestResponse, put: ?TestResponse, batch: array<string, mixed>} */
    protected function uploadBatch(FakeBridgeDevice $device, array $rows, string $entity = 'sales', array $declareOverrides = []): array
    {
        $batch = FakeBridgeDevice::batch($rows);
        $declare = $this->deviceCall($device, 'POST', '/api/v1/bridge/uploads', array_merge([
            'entity' => $entity, 'sha256' => $batch['sha256'], 'size_bytes' => $batch['size_bytes'], 'row_count' => $batch['row_count'],
            'content_type' => 'application/gzip', 'columns' => $batch['columns'], 'run_id' => 'run-0001', 'seq' => 1, 'run_mode' => 'full',
        ], $declareOverrides));
        $put = null;
        if ($declare->json('data.status') === 'upload') {
            $put = $this->putBatch((string) $declare->json('data.path'), $batch['bytes']);
        }

        return ['declare' => $declare, 'put' => $put, 'batch' => $batch];
    }

    protected function putBatch(string $path, string $bytes, string $contentType = 'application/gzip'): TestResponse
    {
        return $this->call('PUT', $path, [], [], [], ['CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_LENGTH' => (string) strlen($bytes)], $bytes);
    }

    protected function secondOwner(): array
    {
        return $this->createTenantWithUser(TenantRole::Owner);
    }
}
