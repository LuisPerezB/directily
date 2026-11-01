<?php

declare(strict_types=1);

namespace App\Services\Conversation\Tools;

use App\Enums\TenantRole;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;

/** Quién consulta: empresa activa, rol y token (si lo hay). Las abilities se evalúan igual que en EnsureTenantAbility. */
final class ToolContext
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly ?User $user,
        public readonly ?TenantRole $role,
        public readonly ?PersonalAccessToken $token = null,
    ) {}

    public static function fromRequest(Request $request, Tenant $tenant): self
    {
        $token = $request->user()?->currentAccessToken();

        return new self(
            $tenant,
            $request->user(),
            $request->attributes->get('tenant_role'),
            $token instanceof PersonalAccessToken && $token->exists ? $token : null,
        );
    }

    /** Mismo criterio que el middleware tenant.ability: el rol debe permitirla y, con token real, el token también. */
    public function can(string $ability): bool
    {
        $roleAllows = $this->role !== null && $this->role->can($ability);
        $tokenAllows = $this->token === null || $this->token->can($ability);

        return $roleAllows && $tokenAllows;
    }
}
