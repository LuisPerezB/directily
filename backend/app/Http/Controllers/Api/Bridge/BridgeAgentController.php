<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bridge;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bridge\BridgeCheckpointCommitRequest;
use App\Http\Requests\Bridge\BridgeEnrolRequest;
use App\Http\Requests\Bridge\BridgeHeartbeatRequest;
use App\Http\Requests\Bridge\BridgeUploadDeclareRequest;
use App\Models\BridgeDevice;
use App\Models\DataSource;
use App\Services\Bridge\BridgeBatchIntake;
use App\Services\Bridge\BridgeCheckpointService;
use App\Services\Bridge\BridgeDeviceAuthenticator;
use App\Services\Bridge\BridgeDeviceService;
use App\Services\Bridge\BridgeEnrolmentService;
use App\Services\Bridge\BridgeRejected;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Endpoints del agente Predictily Bridge (ADR-V25-10). Todas las rutas, salvo `enrol` (público y acotado por IP) y el PUT de subida
 * (autorizado por la firma de su URL, de un solo lote), exigen token de dispositivo + firma de la petición (AuthenticateBridgeDevice).
 * La empresa y la fuente salen SIEMPRE del dispositivo autenticado: ningún parámetro del agente puede elegirlas.
 */
final class BridgeAgentController extends Controller
{
    public function __construct(
        private readonly BridgeEnrolmentService $enrolment,
        private readonly BridgeDeviceService $devices,
        private readonly BridgeBatchIntake $intake,
        private readonly BridgeCheckpointService $checkpoints,
        private readonly BridgeDeviceAuthenticator $authenticator,
    ) {}

    public function enrol(BridgeEnrolRequest $request): JsonResponse
    {
        $result = $this->enrolment->enrol($request->validated());

        return response()->json(['data' => [
            'device_id' => $result['device']->id,
            'token' => $result['token'],
            'tenant' => ['uuid' => $result['tenant']->uuid],
            'data_source' => ['id' => $result['source']->id, 'name' => $result['source']->name],
        ] + $this->contract()], 201);
    }

    public function heartbeat(BridgeHeartbeatRequest $request): JsonResponse
    {
        /** @var BridgeDevice $device */
        $device = $request->attributes->get('bridge_device');
        /** @var DataSource $source */
        $source = $request->attributes->get('bridge_source');
        $this->devices->heartbeat($device, $request->validated());

        return response()->json(['data' => ['status' => 'active', 'source_status' => $source->status] + $this->contract()]);
    }

    public function checkpoints(Request $request): JsonResponse
    {
        /** @var BridgeDevice $device */
        $device = $request->attributes->get('bridge_device');

        return response()->json(['data' => array_map(fn ($c) => $this->devices->presentCheckpoint($c), $this->checkpoints->list($device))]);
    }

    public function commit(BridgeCheckpointCommitRequest $request): JsonResponse
    {
        /** @var BridgeDevice $device */
        $device = $request->attributes->get('bridge_device');
        /** @var DataSource $source */
        $source = $request->attributes->get('bridge_source');
        $row = $this->checkpoints->commit($device, $source, $request->validated());

        return response()->json(['data' => $this->devices->presentCheckpoint($row)]);
    }

    public function declareUpload(BridgeUploadDeclareRequest $request): JsonResponse
    {
        /** @var BridgeDevice $device */
        $device = $request->attributes->get('bridge_device');
        /** @var DataSource $source */
        $source = $request->attributes->get('bridge_source');
        $data = $request->validated();
        if ((int) $data['size_bytes'] > $this->intake->maxBytes()) {
            throw new BridgeRejected('El lote supera el tamaño máximo permitido ('.$this->intake->maxBytes().' bytes).', 'batch_too_large', 413, ['max_bytes' => $this->intake->maxBytes()]);
        }
        $result = $this->intake->declare($device, $source, app(TenantContext::class)->require(), $data);

        return response()->json(['data' => $result], $result['status'] === 'upload' ? 201 : 200);
    }

    /** PUT prefirmado: la firma de la URL (relativa) autoriza UN lote de UN dispositivo; aun así se relee el estado del dispositivo. */
    public function putUpload(Request $request, int $device, string $sha256): JsonResponse
    {
        $claims = Validator::make($request->query(), [
            'e' => ['required', 'string', 'regex:/^[a-z_]{2,30}$/'],
            'n' => ['required', 'integer', 'min:1'],
            'r' => ['nullable', 'integer', 'min:0'],
            't' => ['required', 'string', 'size:36'],
            'c' => ['required', 'string'],
            'tz' => ['nullable', 'string', 'max:64'],
            'run' => ['nullable', 'string', 'max:40'],
            'seq' => ['nullable', 'integer'],
            'rm' => ['nullable', 'in:full,incremental'],
            'tv' => ['nullable', 'integer'],
        ])->validate();
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new BridgeRejected('SHA-256 no válido en la URL de subida.', 'upload_url_invalid', 422);
        }
        $auth = $this->authenticator->authorizeById($device, (string) $claims['t']);
        $outcome = $this->intake->receive(
            $auth['device'], $auth['source'], $auth['tenant'], $sha256, $claims, $request->getContent(true),
            $request->header('Content-Type'), $request->header('Content-Length') !== null ? (int) $request->header('Content-Length') : null,
        );
        $object = $outcome['object'];

        return response()->json(['data' => [
            'status' => $outcome['created'] ? 'stored' : 'duplicate',
            'bronze_object_id' => $object->id,
            'sha256' => $object->sha256,
            'size_bytes' => (int) $object->size_bytes,
            'processing' => (string) ($object->metadata['bridge']['processing']['status'] ?? 'queued'),
        ]], $outcome['created'] ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function contract(): array
    {
        return [
            'protocol' => 1,
            'server_time' => time(),
            'entities' => array_values((array) config('bridge.entities', [])),
            'limits' => [
                'max_batch_bytes' => $this->intake->maxBytes(),
                'max_batch_rows' => (int) config('bridge.max_batch_rows', 50_000),
                'upload_url_ttl_seconds' => (int) config('bridge.upload_url_ttl_seconds', 600),
                'max_checkpoint_length' => 200,
            ],
        ];
    }
}
