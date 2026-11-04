<?php

declare(strict_types=1);

namespace App\Http\Resources\Core;

use App\Enums\CommercialRole;
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
        [$commercial, $unexpected] = CommercialRole::parseStored($this->pivot?->commercial_role);
        $scope = $this->pivot?->commercial_scope;
        $scope = is_string($scope) ? (json_decode($scope, true) ?: []) : (is_array($scope) ? $scope : []);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $role?->value,
            'role_label' => $role?->label(),
            // V2.5 · W6-A: el rol comercial se SUMA al de acceso (alcance sobre la cartera; «servicio» = solo lectura).
            'commercial_role' => $commercial?->value,
            'commercial_role_label' => $commercial?->label(),
            // Valor guardado que no es gerente, vendedor ni servicio (dato dañado): la persona opera con alcance cerrado y solo lectura hasta que se corrija.
            'commercial_role_unexpected' => $unexpected,
            'commercial_scope' => (object) ($commercial !== null ? $scope : []),
            'mfa_enabled' => $this->resource->hasMfaEnabled(),
            'joined_at' => $this->pivot?->created_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
