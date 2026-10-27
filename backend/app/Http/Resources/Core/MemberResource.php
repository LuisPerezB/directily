<?php

declare(strict_types=1);

namespace App\Http\Resources\Core;

use App\Enums\TenantRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Miembro de la empresa (requiere pivot). @mixin \App\Models\User */
final class MemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $role = TenantRole::tryFrom((string) $this->pivot?->role);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $role?->value,
            'role_label' => $role?->label(),
            'joined_at' => $this->pivot?->created_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
