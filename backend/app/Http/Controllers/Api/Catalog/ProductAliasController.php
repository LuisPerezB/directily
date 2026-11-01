<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Requests\Catalog\ChangeCodeRequest;
use App\Http\Requests\Catalog\StoreAliasRequest;
use App\Models\Product;
use App\Models\ProductAlias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** Alias y cambio de código de producto. Fusión y división de SKU: DISEÑADAS, no implementadas. */
final class ProductAliasController extends AbstractAliasController
{
    protected function type(): string
    {
        return 'product';
    }

    protected function aliasClass(): string
    {
        return ProductAlias::class;
    }

    public function index(Product $product): JsonResponse
    {
        return $this->aliasIndex($product);
    }

    public function store(StoreAliasRequest $request, Product $product): JsonResponse
    {
        return $this->aliasStore($request, $product);
    }

    public function destroy(Product $product, int $alias): Response
    {
        return $this->aliasDestroy($product, $alias);
    }

    public function codeChange(ChangeCodeRequest $request, Product $product): JsonResponse
    {
        $result = $this->applyCodeChange($request, $product);

        return response()->json(['data' => [
            'product_id' => $product->id,
            'sku' => $result['subject']->sku,
            'previous_sku' => $result['previous_code'],
            'effective_from' => $request->validated('effective_from'),
            'alias' => $result['alias']->toApi(),
        ]]);
    }
}
