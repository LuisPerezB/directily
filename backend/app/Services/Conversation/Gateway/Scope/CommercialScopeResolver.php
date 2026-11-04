<?php

declare(strict_types=1);

namespace App\Services\Conversation\Gateway\Scope;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Container\Attributes\Bind;

/**
 * Cómo obtiene el gateway el alcance comercial de quien consulta cuando el contexto no lo trae ya resuelto (canales fuera de una petición HTTP). Con una petición web, el
 * alcance lo resuelve `ResolveTenant` (W6-A) y viaja en el ToolContext. El gateway nunca lo lee del modelo: se pide con el usuario y la empresa de la PETICIÓN.
 */
#[Bind(MembershipCommercialScopeResolver::class)]
interface CommercialScopeResolver
{
    public function resolve(Tenant $tenant, ?User $user, ?TenantRole $role): GatewayScope;
}
