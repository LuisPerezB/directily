<?php

declare(strict_types=1);

namespace App\Http\Resources\Models;

use App\Http\Resources\Analytics\ModelRegistryResource;
use App\Models\ModelPromotionDecision;
use App\Models\ModelRegistry;
use Illuminate\Http\Request;

/**
 * Detalle de un modelo del registro: los campos de la lista, sus eventos de auditoría (de la más antigua a la más reciente) y la decisión
 * de promoción de la política asociada. Nunca expone la clave interna del almacén de objetos.
 *
 * @mixin ModelRegistry
 */
class ModelRegistryDetailResource extends ModelRegistryResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $decision = $this->promotion_decision_id !== null ? ModelPromotionDecision::query()->find($this->promotion_decision_id) : null;

        return parent::toArray($request) + [
            'events' => ModelRegistryEventResource::collection($this->events)->resolve($request),
            'promotion_decision' => $decision === null ? null : [
                'id' => $decision->id,
                'policy' => $decision->policy,
                'scope_key' => $decision->scope_key,
                'decision' => $decision->decision,
                'is_effective' => (bool) $decision->is_effective,
                'reason' => $decision->reason,
                'approved_by' => $decision->approved_by,
                'approved_at' => $decision->approved_at?->toIso8601String(),
                'decided_at' => $decision->decided_at?->toIso8601String(),
            ],
            'published' => $this->published_at !== null || $this->published_object_key !== null,
        ];
    }
}
