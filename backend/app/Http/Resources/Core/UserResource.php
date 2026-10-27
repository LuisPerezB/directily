<?php

declare(strict_types=1);

namespace App\Http\Resources\Core;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'locale' => $this->locale,
            'is_superadmin' => (bool) $this->is_superadmin,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
