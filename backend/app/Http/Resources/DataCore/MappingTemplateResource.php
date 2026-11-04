<?php

declare(strict_types=1);

namespace App\Http\Resources\DataCore;

use App\Models\MappingTemplate;
use App\Services\DataCore\Mapping\Templates\MappingTemplateService;
use App\Support\Results\JsonMap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plantilla de mapeo del Auto Mapper. Solo metadatos del esquema de origen (tablas, columnas, tipos) y el mapa propuesto; nunca valores de negocio.
 * `confidence` es un puntaje HEURÍSTICO (no una probabilidad calibrada). `read_only` es verdadero en las plantillas públicas de la plataforma.
 *
 * @mixin MappingTemplate
 */
class MappingTemplateResource extends JsonResource
{
    /** Con `withQuery` incluye la consulta SELECT que se generaría (para que la persona que aprueba vea el texto exacto). */
    public function __construct($resource, private readonly bool $withQuery = false)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $service = app(MappingTemplateService::class);
        $missing = $service->missingRequired($this->resource);
        // La identidad del personal de la plataforma no se expone a las empresas: en una plantilla pública, quién la creó y la aprobó solo lo ve el superadministrador (`/admin`).
        $hideStaff = $this->tenant_id === null && ! $request->is('api/v1/admin/*');
        $data = [
            'id' => $this->id,
            'scope' => $this->tenant_id === null ? 'platform' : 'tenant',
            'read_only' => $this->tenant_id === null,
            'system_kind' => $this->system_kind,
            'vendor' => $this->vendor,
            'entity' => $this->entity,
            'version' => $this->version,
            'status' => $this->status,
            'schema_fingerprint' => $this->schema_fingerprint,
            'data_source_id' => $this->data_source_id,
            'forked_from_id' => $this->forked_from_id,
            'field_map' => JsonMap::orEmpty($this->field_map),
            'value_maps' => JsonMap::orEmpty($this->value_maps),
            'confidence' => JsonMap::orEmpty($this->confidence),
            'source_spec' => JsonMap::orEmpty($this->source_spec),
            'drift' => $this->drift === null ? null : JsonMap::orEmpty($this->drift),
            'invalidated_at' => $this->invalidated_at?->toIso8601String(),
            'complete' => $missing === [],
            'missing_required' => $missing,
            'approved_by' => $hideStaff ? null : ($this->relationLoaded('approver') && $this->approver !== null ? ['id' => $this->approver->id, 'name' => $this->approver->name] : ($this->approved_by === null ? null : ['id' => $this->approved_by])),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_by' => $hideStaff ? null : $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
        if ($this->withQuery) {
            $preview = $service->preview($this->resource);
            $data['query'] = $preview['sql'];
            $data['query_error'] = $preview['error'];
        }

        return $data;
    }
}
