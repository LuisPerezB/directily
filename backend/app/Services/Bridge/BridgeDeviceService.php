<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use App\Models\BridgeCheckpoint;
use App\Models\BridgeDevice;
use App\Models\DataSource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Revocación, latido y presentación de dispositivos Bridge (ADR-V25-10). */
final class BridgeDeviceService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Revoca el dispositivo: desde este instante su token, su firma y cualquier URL prefirmada pendiente dejan de servir (cada petición relee
     * la fila). Idempotente. Si era el último dispositivo activo de la fuente, la fuente queda `inactive` hasta que se registre otro.
     */
    public function revoke(BridgeDevice $device, User $user, ?string $reason = null): BridgeDevice
    {
        if ($device->isRevoked()) {
            return $device;
        }
        DB::transaction(function () use ($device, $user): void {
            $device->forceFill(['revoked_at' => now(), 'revoked_by' => $user->id])->save();
            $stillActive = BridgeDevice::query()->where('data_source_id', $device->data_source_id)->whereNull('revoked_at')->exists();
            if (! $stillActive) {
                DataSource::query()->whereKey($device->data_source_id)->update(['status' => 'inactive', 'last_error' => 'Todos los dispositivos Bridge de la fuente fueron revocados.']);
            }
        });
        Cache::forget("bridge:health:{$device->id}");
        $this->audit->log('bridge.device_revoked', $device, ['data_source_id' => $device->data_source_id, 'reason' => $reason !== null ? mb_substr($reason, 0, 200) : null], userId: $user->id);

        return $device->refresh();
    }

    /**
     * Latido: actualiza `last_seen_at`, versión y plataforma, y recuerda (24 h) la salud informada por el agente. La salud es informativa y
     * viene del dispositivo: se acota y no contiene secretos (el agente no los conoce más allá de su propio token).
     *
     * @param  array<string, mixed>  $data
     */
    public function heartbeat(BridgeDevice $device, array $data): BridgeDevice
    {
        $device->forceFill(array_filter([
            'last_seen_at' => now(),
            'agent_version' => isset($data['agent_version']) ? mb_substr((string) $data['agent_version'], 0, 40) : null,
            'platform' => isset($data['platform']) ? mb_substr((string) $data['platform'], 0, 40) : null,
        ], fn ($v) => $v !== null))->save();
        if (isset($data['health']) && is_array($data['health'])) {
            Cache::put("bridge:health:{$device->id}", $data['health'] + ['reported_at' => now()->toIso8601String()], now()->addDay());
        }

        return $device;
    }

    /** @return array<string, mixed> */
    public function present(BridgeDevice $device, bool $withCheckpoints = false): array
    {
        $out = [
            'id' => $device->id,
            'data_source_id' => $device->data_source_id,
            'name' => $device->name,
            'status' => $device->status(),
            'public_key_fingerprint' => $device->public_key_fingerprint,
            'agent_version' => $device->agent_version,
            'platform' => $device->platform,
            'registered_at' => $device->registered_at?->toIso8601String(),
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'revoked_at' => $device->revoked_at?->toIso8601String(),
            'revoked_by' => $device->revoked_by,
            'health' => (object) (Cache::get("bridge:health:{$device->id}") ?? []),
        ];
        if ($withCheckpoints) {
            $out['checkpoints'] = array_map(fn (BridgeCheckpoint $c) => $this->presentCheckpoint($c), $device->checkpoints()->orderBy('entity')->get()->all());
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function presentCheckpoint(BridgeCheckpoint $c): array
    {
        return [
            'entity' => $c->entity,
            'watermark' => $c->watermark?->toIso8601String(),
            'checkpoint' => $c->checkpoint,
            'bronze_object_id' => $c->bronze_object_id,
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }
}
