<?php

declare(strict_types=1);

namespace App\Http\Requests\Security;

use App\Enums\CommercialRole;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rol comercial y alcance de un miembro (V2.5 · W6-A). `commercial_role: null` lo quita. El alcance solo referencia clientes, segmentos y personas de LA MISMA empresa
 * (TenantRule): un identificador de otra empresa es un 422, no un alcance sobre datos ajenos.
 */
final class CommercialRoleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'commercial_role' => ['present', 'nullable', Rule::enum(CommercialRole::class)],
            'commercial_scope' => ['sometimes', 'nullable', 'array:customer_ids,zones,segment_ids,team_user_ids'],
            'commercial_scope.customer_ids' => ['sometimes', 'array', 'max:500'],
            'commercial_scope.customer_ids.*' => ['integer', TenantRule::exists('customers')],
            'commercial_scope.zones' => ['sometimes', 'array', 'max:100'],
            'commercial_scope.zones.*' => ['string', 'max:80'],
            'commercial_scope.segment_ids' => ['sometimes', 'array', 'max:100'],
            'commercial_scope.segment_ids.*' => ['integer', TenantRule::exists('customer_segments')],
            'commercial_scope.team_user_ids' => ['sometimes', 'array', 'max:200'],
            'commercial_scope.team_user_ids.*' => ['integer', Rule::exists('tenant_users', 'user_id')->where('tenant_id', app(TenantContext::class)->require()->id)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'commercial_role.present' => 'Indique el rol comercial (o null para quitarlo).',
            'commercial_scope.array' => 'Alcance no reconocido.',
            'commercial_scope.customer_ids.*.exists' => 'Un cliente del alcance no pertenece a la empresa.',
            'commercial_scope.segment_ids.*.exists' => 'Un segmento del alcance no pertenece a la empresa.',
            'commercial_scope.team_user_ids.*.exists' => 'Una persona del equipo no es miembro de la empresa.',
        ];
    }
}
