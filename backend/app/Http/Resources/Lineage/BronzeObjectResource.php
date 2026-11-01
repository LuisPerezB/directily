<?php

declare(strict_types=1);

namespace App\Http\Resources\Lineage;

use App\Models\BronzeObject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Metadatos de un objeto Bronze. Nunca incluye `object_key` (clave interna del almacén) ni el contenido; del `metadata` se
 * omite el nombre del disco. El contenido solo se entrega por el endpoint de descarga (propietario/administrador, auditado).
 *
 * @mixin BronzeObject
 */
final class BronzeObjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $metadata = (array) ($this->metadata ?? []);
        unset($metadata['disk']);

        return [
            'id' => $this->id,
            'data_source_id' => $this->data_source_id,
            'kind' => $this->kind,
            'entity' => $this->entity,
            'storage_driver' => $this->storage_driver,
            'sha256' => $this->sha256,
            'size_bytes' => (int) $this->size_bytes,
            'content_type' => $this->content_type,
            'schema_fingerprint' => $this->schema_fingerprint,
            'schema_version' => $this->schema_version,
            'row_count' => $this->row_count,
            'original_name' => $this->original_name,
            'received_by_user_id' => $this->received_by_user_id,
            'received_from_device' => $this->received_from_device,
            'received_at' => $this->received_at?->toIso8601String(),
            'retention_until' => $this->retention_until?->toDateString(),
            'status' => $this->status,
            'content_available' => $this->status !== 'purged',
            'metadata' => $metadata === [] ? (object) [] : $metadata,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
