<?php

declare(strict_types=1);

namespace App\Services\Conversation\Gateway\Scope;

use App\Enums\CommercialRole;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Commercial\CommercialScope as AuthScope;
use Illuminate\Support\Facades\DB;

/**
 * Resolvedor por defecto: lee la membresía (`tenant_users.commercial_role` y `commercial_scope`) y construye el alcance con el MISMO analizador de W6-A
 * (`CommercialScope::fromMembership`), de modo que el gateway y la API no puedan divergir. Cierra en falso: sin usuario, sin membresía o con un rol comercial desconocido
 * (que W6-A trataría como «sin rol») el alcance es «unresolved».
 */
final class MembershipCommercialScopeResolver implements CommercialScopeResolver
{
    public function resolve(Tenant $tenant, ?User $user, ?TenantRole $role): GatewayScope
    {
        if ($user === null || $role === null) {
            return GatewayScope::unresolved();
        }
        $row = DB::table('tenant_users')->where('tenant_id', $tenant->getKey())->where('user_id', $user->getKey())->first(['commercial_role', 'commercial_scope']);
        if ($row === null) {
            return GatewayScope::unresolved();
        }
        // V2.5 · W6-A (revisión L4): la MISMA lectura que la API (`CommercialRole::parseStored`): solo NULL es «sin rol comercial»; un valor no reconocido (incluida la cadena vacía o con
        // espacios, que antes se trataba como sin rol y abría el alcance) deja el alcance sin resolver (el más restrictivo).
        [, $unexpected] = CommercialRole::parseStored($row->commercial_role);
        if ($unexpected) {
            return GatewayScope::unresolved();
        }

        return GatewayScope::from(AuthScope::fromMembership((int) $user->getKey(), $row->commercial_role, $row->commercial_scope), 'membership');
    }
}
