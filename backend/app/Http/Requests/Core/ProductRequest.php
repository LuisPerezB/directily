<?php

declare(strict_types=1);

namespace App\Http\Requests\Core;

use App\Support\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

final class ProductRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('product')?->id;
        $req = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'sku' => [$req, 'string', 'max:100', TenantRule::unique('products', 'sku', $id)],
            'name' => [$req, 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('product_categories')],
            'unit' => ['sometimes', 'string', 'max:20'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:100'],
            'current_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'current_cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['sku' => 'código (SKU)', 'name' => 'nombre', 'category_id' => 'categoría', 'current_price' => 'precio', 'current_cost' => 'costo'];
    }
}
