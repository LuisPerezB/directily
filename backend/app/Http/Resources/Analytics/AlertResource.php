<?php

declare(strict_types=1);

namespace App\Http\Resources\Analytics;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Alert */
class AlertResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'severity' => $this->severity,
            'title' => $this->title,
            'message' => $this->message,
            'payload' => $this->payload,
            'related_type' => $this->related_type,
            'related_id' => $this->related_id,
            'status' => $this->status,
            'acknowledged_by' => $this->acknowledged_by,
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
