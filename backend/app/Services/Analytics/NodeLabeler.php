<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Analytics\Support\ForecastNode;

/** Etiquetas legibles de nodos de la jerarquía (consultas acotadas a la empresa activa). */
class NodeLabeler
{
    public function label(ForecastNode $node): string
    {
        $branch = $node->branchId ? Branch::query()->whereKey($node->branchId)->value('name') : null;

        return match ($node->level) {
            ForecastNode::LEVEL_TOTAL => 'Total empresa',
            ForecastNode::LEVEL_BRANCH => (string) $branch,
            ForecastNode::LEVEL_CATEGORY => (string) ProductCategory::query()->whereKey($node->categoryId)->value('name'),
            ForecastNode::LEVEL_BRANCH_CATEGORY => ProductCategory::query()->whereKey($node->categoryId)->value('name').' — '.$branch,
            ForecastNode::LEVEL_PRODUCT => (string) Product::query()->whereKey($node->productId)->value('name'),
            ForecastNode::LEVEL_SERIES => Product::query()->whereKey($node->productId)->value('name').' — '.$branch,
            default => $node->key,
        };
    }
}
