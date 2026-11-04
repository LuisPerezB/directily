<?php

declare(strict_types=1);

namespace App\Http\Resources\Decision;

use App\Models\DecisionEvent;
use App\Services\Decision\ExperimentRedaction;
use App\Support\Results\JsonMap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Evento de auditoría de una recomendación (de la más antigua a la más reciente). No expone la clave de idempotencia interna. @mixin DecisionEvent */
class DecisionEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // La asignación al experimento (grupo y clave) solo la ve quien puede aprobar: el payload de un evento nunca delata al grupo de control ni al de tratamiento.
        $ctx = $request->attributes->get('decision_ctx');
        $payload = (array) $this->payload;
        if (! ExperimentRedaction::canSee($ctx['role'] ?? null)) {
            $payload = ExperimentRedaction::strip($payload);
        }

        return [
            'id' => $this->id,
            'event' => $this->event,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'agenda_status' => $this->agenda_status,
            'actor_user_id' => $this->actor_user_id,
            'actor_kind' => $this->actor_kind,
            'reason' => $this->reason,
            'payload' => JsonMap::orEmpty($payload),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
