<?php

declare(strict_types=1);

namespace App\Http\Resources\Core;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Empresa en la lista "mis empresas" (requiere pivot tenant_users cargado). @mixin \App\Models\Tenant */
final class TenantSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'sector' => $this->sector,
            'status' => $this->status,
            'role' => $this->pivot?->role,
            'onboarding_completed_at' => $this->onboarding_completed_at?->toIso8601String(),
        ];
    }
}
