<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Base de las peticiones analíticas. Los permisos se validan con el middleware
 * tenant.ability; aquí solo validación estricta de entrada, con existencia de ids
 * restringida a la empresa activa (un id de otra empresa responde 422).
 */
abstract class AnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function tenantId(): int
    {
        return (int) app(TenantContext::class)->id();
    }

    protected function existsInTenant(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', $this->tenantId());
    }

    /** @return list<int> */
    protected function horizons(): array
    {
        return array_map('intval', (array) config('engine.horizons', [7, 14, 30, 60, 90]));
    }

    public function intOrNull(string $key): ?int
    {
        $value = $this->validated($key);

        return $value === null || $value === '' ? null : (int) $value;
    }

    /** @return array{branch_id: int|null, category_id: int|null, product_id: int|null} */
    public function nodeFilters(): array
    {
        return [
            'branch_id' => $this->intOrNull('branch_id'),
            'category_id' => $this->intOrNull('category_id'),
            'product_id' => $this->intOrNull('product_id'),
        ];
    }

    /** @return array<string, mixed> */
    protected function nodeRules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', $this->existsInTenant('branches')],
            'category_id' => ['nullable', 'integer', $this->existsInTenant('product_categories')],
            'product_id' => ['nullable', 'integer', $this->existsInTenant('products')],
        ];
    }

    /** @return array<string, mixed> */
    protected function runRule(bool $required = false): array
    {
        return ['run_id' => [$required ? 'required' : 'nullable', 'integer', 'min:1']];
    }

    public function horizon(?int $default = null): int
    {
        return (int) ($this->validated('horizon') ?? $default ?? 30);
    }
}
