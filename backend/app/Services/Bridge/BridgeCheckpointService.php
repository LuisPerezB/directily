<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use App\Models\BridgeCheckpoint;
use App\Models\BridgeDevice;
use App\Models\BridgeRunBatch;
use App\Models\BronzeObject;
use App\Models\DataSource;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Checkpoints del Bridge (ADR-V25-10): «checkpoints confirmados solo tras el acuse del servidor».
 *
 * Por dispositivo y entidad el servidor guarda:
 *  - `watermark`: marca de agua CONFIRMADA de la última extracción completa. La acota el agente al inicio de su extracción (misma regla que
 *    SourceSyncService) y el servidor la VUELVE A ACOTAR: nunca puede quedar en el futuro ni retroceder;
 *  - `checkpoint`: cursor OPACO de la extracción en curso (≤ 200 caracteres), para reanudar sin repetir lotes;
 *  - `bronze_object_id`: el último lote confirmado.
 * El agente solo avanza su estado local tras recibir la respuesta de `commit`, que a su vez exige que el lote citado ya esté en Bronze
 * (misma empresa, misma entidad, misma fuente del dispositivo). Es idempotente: repetir el mismo `commit` devuelve el mismo estado.
 */
final class BridgeCheckpointService
{
    public function __construct(private readonly BridgeBatchIntake $intake, private readonly AuditLogger $audit) {}

    /** @return list<BridgeCheckpoint> */
    public function list(BridgeDevice $device): array
    {
        return BridgeCheckpoint::query()->where('bridge_device_id', $device->id)->orderBy('entity')->get()->all();
    }

    /**
     * @param  array{entity: string, run_id: string, batch_sha256?: ?string, run_batch_index?: ?int, run_batches?: ?int, checkpoint?: ?string, watermark?: ?string, run_complete: bool}  $data
     *
     * @throws BridgeRejected
     */
    public function commit(BridgeDevice $device, DataSource $source, array $data): BridgeCheckpoint
    {
        $entity = (string) $data['entity'];
        $runId = (string) $data['run_id'];
        $this->intake->assertEntity($entity);
        $complete = (bool) $data['run_complete'];
        $cursor = $data['checkpoint'] ?? null;
        $watermark = isset($data['watermark']) ? CarbonImmutable::parse($data['watermark'])->utc() : null;
        $batches = isset($data['run_batches']) ? (int) $data['run_batches'] : null;
        $index = isset($data['run_batch_index']) ? (int) $data['run_batch_index'] : null;

        if ($complete) {
            if ($watermark === null) {
                throw new BridgeRejected('Al completar una extracción hay que indicar la marca de agua.', 'watermark_required', 422);
            }
            if ($cursor !== null) {
                throw new BridgeRejected('Una extracción completa no deja cursor de reanudación.', 'checkpoint_not_allowed', 422);
            }
            $skew = (int) config('bridge.signature_skew_seconds', 300);
            if ($watermark->greaterThan(CarbonImmutable::now()->addSeconds($skew))) {
                throw new BridgeRejected('La marca de agua no puede estar en el futuro (se acota al inicio de la extracción).', 'watermark_in_future', 422);
            }
            if ($batches === null) {
                throw new BridgeRejected('Al completar una extracción hay que indicar cuántos lotes tuvo (run_batches).', 'run_batches_required', 422);
            }
            // La PRUEBA de una extracción con filas es el libro de lotes confirmados (1…N, contiguos, ninguno fallido): se comprueba al completar (assertRunIsComplete).
            // Si cita su último lote, este debe ocupar la posición N; una extracción sin lotes no cita ninguno (B-3).
            if ($batches === 0 && ($data['batch_sha256'] ?? null) !== null) {
                throw new BridgeRejected('Una extracción sin lotes no cita ningún lote.', 'batch_not_allowed', 422);
            }
            if (($data['batch_sha256'] ?? null) !== null && $index !== $batches) {
                throw new BridgeRejected('El lote citado debe ser el último de la extracción (run_batch_index = run_batches).', 'run_batch_index_mismatch', 422);
            }
        } else {
            if ($watermark !== null) {
                throw new BridgeRejected('La marca de agua solo se confirma al completar la extracción.', 'watermark_not_allowed', 422);
            }
            if (! is_string($cursor) || $cursor === '') {
                throw new BridgeRejected('Falta el cursor de reanudación de la extracción en curso.', 'checkpoint_required', 422);
            }
            if (($data['batch_sha256'] ?? null) === null) {
                throw new BridgeRejected('Un checkpoint intermedio debe citar el lote que confirma.', 'batch_required', 422);
            }
        }

        $object = null;
        if (($data['batch_sha256'] ?? null) !== null) {
            $object = $this->citedBatch($source, $entity, strtolower((string) $data['batch_sha256']));
        }

        return DB::transaction(function () use ($device, $entity, $runId, $watermark, $cursor, $object, $index, $batches, $complete): BridgeCheckpoint {
            // Serializa confirmaciones concurrentes del mismo dispositivo y entidad.
            DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [7435, $device->id]);
            if ($object !== null && $index !== null) {
                $this->recordInLedger($device, $entity, $runId, $index, $object);
            }
            if ($complete) {
                $this->assertRunIsComplete($device, $entity, $runId, (int) $batches);
            }
            $row = BridgeCheckpoint::query()->where('bridge_device_id', $device->id)->where('entity', $entity)->first();
            if ($complete && $watermark !== null && $row?->watermark !== null && $watermark->lessThan(CarbonImmutable::instance($row->watermark))) {
                throw new BridgeRejected('La marca de agua no puede retroceder.', 'watermark_regression', 409, ['current' => $row->watermark->toIso8601String()]);
            }
            $row ??= new BridgeCheckpoint;
            $row->forceFill(array_filter([
                'bridge_device_id' => $device->id,
                'entity' => $entity,
                'bronze_object_id' => $object?->id ?? $row->bronze_object_id,
            ], fn ($v) => $v !== null) + ['checkpoint' => $cursor]);
            if ($complete) {
                $row->watermark = $watermark;
            }
            $row->save();
            if ($complete) {
                // El libro de las corridas anteriores ya no hace falta: solo se conserva el de la corrida que acaba de completarse.
                BridgeRunBatch::query()->where('bridge_device_id', $device->id)->where('entity', $entity)->where('run_id', '!=', $runId)->delete();
            }
            $this->audit->log('bridge.checkpoint', $row, ['device_id' => $device->id, 'entity' => $entity, 'run_complete' => $complete, 'bronze_object_id' => $object?->id, 'run_batches' => $batches], $device->tenant_id);

            return $row;
        });
    }

    /**
     * El lote citado debe estar en Bronze para esta fuente y entidad, y NO haber fallado: confirmar el checkpoint de un lote fallido o puesto en cuarentena
     * haría que el agente descartara datos que Predictily no pudo procesar (409 `batch_failed`).
     */
    private function citedBatch(DataSource $source, string $entity, string $sha): BronzeObject
    {
        $object = BronzeObject::query()->where('sha256', $sha)->where('entity', $entity)->where('kind', 'bridge_batch')->where('data_source_id', $source->id)->first();
        if ($object === null || $object->status === 'purged') {
            throw new BridgeRejected('El lote citado no está registrado para esta fuente: suba el lote antes de confirmar el checkpoint.', 'batch_not_found', 409);
        }
        $processing = (string) ($object->metadata['bridge']['processing']['status'] ?? 'queued');
        if ($object->status === 'quarantined' || $processing === 'failed') {
            throw new BridgeRejected('El lote citado falló al procesarse en Predictily (o está en cuarentena): no se confirma su checkpoint. El agente lo reenviará cuando se corrija la causa.', 'batch_failed', 409, ['processing' => $processing, 'bronze_status' => $object->status]);
        }

        return $object;
    }

    /** Registra el lote en su posición de la corrida (idempotente; otra posición con OTRO lote es un conflicto). */
    private function recordInLedger(BridgeDevice $device, string $entity, string $runId, int $index, BronzeObject $object): void
    {
        $existing = BridgeRunBatch::query()->where('bridge_device_id', $device->id)->where('entity', $entity)->where('run_id', $runId)->where('batch_index', $index)->first();
        if ($existing !== null) {
            if ((int) $existing->bronze_object_id !== (int) $object->id) {
                throw new BridgeRejected('Esa posición de la extracción ya está ocupada por otro lote.', 'run_batch_conflict', 409, ['run_batch_index' => $index]);
            }

            return;
        }
        $row = new BridgeRunBatch;
        $row->forceFill(['bridge_device_id' => $device->id, 'entity' => $entity, 'run_id' => $runId, 'batch_index' => $index, 'bronze_object_id' => $object->id, 'row_count' => (int) $object->row_count])->save();
    }

    /** Al completar: los lotes de la corrida son EXACTAMENTE 1…N, sin huecos y ninguno fallido (409 `run_incomplete` / `batch_failed`). */
    private function assertRunIsComplete(BridgeDevice $device, string $entity, string $runId, int $batches): void
    {
        $rows = BridgeRunBatch::query()->where('bridge_device_id', $device->id)->where('entity', $entity)->where('run_id', $runId)->orderBy('batch_index')->get();
        $indexes = $rows->pluck('batch_index')->map(fn ($i) => (int) $i)->all();
        if ($indexes !== ($batches > 0 ? range(1, $batches) : [])) {
            $missing = $batches > 0 ? array_values(array_diff(range(1, $batches), $indexes)) : [];
            throw new BridgeRejected('La extracción no tiene confirmados todos sus lotes en orden (sin huecos): no se mueve la marca de agua.', 'run_incomplete', 409, ['expected_batches' => $batches, 'confirmed_batches' => count($indexes), 'missing' => array_slice($missing, 0, 20)]);
        }
        $failed = BronzeObject::query()->whereIn('id', $rows->pluck('bronze_object_id')->filter()->all())
            ->where(fn ($q) => $q->where('status', 'quarantined')->orWhereRaw("metadata->'bridge'->'processing'->>'status' = 'failed'"))->count();
        if ($failed > 0) {
            throw new BridgeRejected("{$failed} lote(s) de la extracción fallaron al procesarse: no se mueve la marca de agua.", 'batch_failed', 409, ['failed_batches' => $failed]);
        }
    }

    /** Restablece el estado de una entidad (el administrador pide una carga completa nueva). */
    public function reset(BridgeDevice $device, string $entity, ?int $userId): bool
    {
        $deleted = BridgeCheckpoint::query()->where('bridge_device_id', $device->id)->where('entity', $entity)->delete() > 0;
        $this->audit->log('bridge.checkpoint_reset', $device, ['entity' => $entity, 'had_checkpoint' => $deleted], $device->tenant_id, $userId);

        return $deleted;
    }
}
