<?php

declare(strict_types=1);

namespace App\Http\Resources\Models;

use App\Models\ModelRegistryEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ModelRegistryEvent */
class ModelRegistryEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'actor_user_id' => $this->actor_user_id,
            'actor_kind' => $this->actor_kind,
            'reason' => $this->reason,
            'evidence' => (object) ($this->evidence ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
