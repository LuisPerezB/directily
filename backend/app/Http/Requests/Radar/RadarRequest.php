<?php

declare(strict_types=1);

namespace App\Http\Requests\Radar;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Base de las peticiones del Revenue Radar (V2.5 · W5-A): los permisos los fija el middleware `tenant.ability`; aquí solo validación estricta de la entrada,
 * con los ids acotados a la empresa activa (un id de otra empresa responde 422, como en el resto de la API).
 */
abstract class RadarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function tenantId(): int
    {
        return (int) app(TenantContext::class)->id();
    }

    public function intOrNull(string $key): ?int
    {
        $value = $this->validated($key);

        return $value === null || $value === '' ? null : (int) $value;
    }

    protected function existsInTenant(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', $this->tenantId());
    }
}
