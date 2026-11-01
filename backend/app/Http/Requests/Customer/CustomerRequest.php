<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/** Base de las peticiones del Customer Core: permisos por middleware `tenant.ability`; ids acotados a la empresa activa. */
abstract class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function existsInTenant(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', (int) app(TenantContext::class)->id());
    }
}
