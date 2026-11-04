<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bridge;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bridge\BridgeEnrolmentIssueRequest;
use App\Models\BridgeDevice;
use App\Models\DataSource;
use App\Models\User;
use App\Services\Bridge\BridgeCheckpointService;
use App\Services\Bridge\BridgeDeviceService;
use App\Services\Bridge\BridgeEnrolmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Administración de dispositivos Bridge por la empresa (habilidad datasources.manage): código de registro, lista, detalle, revocación y reinicio de checkpoints. */
final class BridgeDeviceController extends Controller
{
    public function __construct(
        private readonly BridgeEnrolmentService $enrolment,
        private readonly BridgeDeviceService $devices,
        private readonly BridgeCheckpointService $checkpoints,
    ) {}

    /** El código se muestra UNA vez: de él solo se guarda el SHA-256. */
    public function issueEnrolment(BridgeEnrolmentIssueRequest $request, DataSource $dataSource): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $issued = $this->enrolment->issue($dataSource, $user, $request->validated('device_name_hint'), $request->validated('ttl_minutes'));

        return response()->json(['data' => [
            'code' => $issued['code'],
            'expires_at' => $issued['expires_at']->toIso8601String(),
            'data_source_id' => $dataSource->id,
            'single_use' => true,
            'note' => 'Muestre este código solo al instalador del agente: no se vuelve a mostrar y caduca.',
        ]], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['data_source_id' => ['sometimes', 'integer'], 'status' => ['sometimes', 'in:all,active,revoked']]);
        $query = BridgeDevice::query()->orderByDesc('id');
        if ($request->filled('data_source_id')) {
            $query->where('data_source_id', (int) $request->input('data_source_id'));
        }
        match ($request->input('status', 'all')) {
            'active' => $query->whereNull('revoked_at'),
            'revoked' => $query->whereNotNull('revoked_at'),
            default => null,
        };

        return response()->json(['data' => $query->get()->map(fn (BridgeDevice $d) => $this->devices->present($d))->all()]);
    }

    public function show(BridgeDevice $device): JsonResponse
    {
        return response()->json(['data' => $this->devices->present($device, withCheckpoints: true)]);
    }

    public function revoke(Request $request, BridgeDevice $device): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->devices->present($this->devices->revoke($device, $user, $data['reason'] ?? null))]);
    }

    /** Pide una carga completa nueva de la entidad: borra su marca de agua y cursor (los datos ya recibidos no se tocan). */
    public function resetCheckpoint(Request $request, BridgeDevice $device, string $entity): Response
    {
        abort_unless(preg_match('/^[a-z_]{2,30}$/', $entity) === 1, 404);
        /** @var User $user */
        $user = $request->user();
        $this->checkpoints->reset($device, $entity, $user->id);

        return response()->noContent();
    }
}
