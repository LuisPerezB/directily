<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/** Reglas de validación acotadas a la empresa activa (evitan referenciar ids de otra empresa). */
final class TenantRule
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where('tenant_id', app(TenantContext::class)->require()->id);
    }

    public static function unique(string $table, string $column, ?int $ignoreId = null): Unique
    {
        $rule = Rule::unique($table, $column)->where('tenant_id', app(TenantContext::class)->require()->id);

        return $ignoreId !== null ? $rule->ignore($ignoreId) : $rule;
    }
}
