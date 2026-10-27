<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = ProductCategory::query()->withCount('products')->orderBy('name')->get();

        return response()->json(['data' => $categories->map(fn (ProductCategory $c) => $this->present($c))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', TenantRule::unique('product_categories', 'name')],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'parent_id' => ['sometimes', 'nullable', 'integer', TenantRule::exists('product_categories')],
        ]);
        $category = ProductCategory::query()->create($data);
        $category->products_count = 0;

        return response()->json(['data' => $this->present($category)], 201);
    }

    public function update(Request $request, ProductCategory $productCategory): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150', TenantRule::unique('product_categories', 'name', $productCategory->id)],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'not_in:'.$productCategory->id, TenantRule::exists('product_categories')],
        ]);
        $productCategory->fill($data)->save();
        $productCategory->loadCount('products');

        return response()->json(['data' => $this->present($productCategory)]);
    }

    /** @return array<string, mixed> */
    private function present(ProductCategory $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'code' => $c->code,
            'parent_id' => $c->parent_id,
            'products_count' => (int) ($c->products_count ?? 0),
        ];
    }
}
