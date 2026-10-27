<?php

declare(strict_types=1);

namespace App\Services\Analytics\Support;

/**
 * Nodo de la jerarquía de pronóstico (docs/06 §3.8):
 * total | branch:{b} | category:{c} | branch_category:{b}:{c} | product:{p} | series:{b}:{p}
 */
final class ForecastNode
{
    public const LEVEL_TOTAL = 'total';
    public const LEVEL_BRANCH = 'branch';
    public const LEVEL_CATEGORY = 'category';
    public const LEVEL_BRANCH_CATEGORY = 'branch_category';
    public const LEVEL_PRODUCT = 'product';
    public const LEVEL_SERIES = 'series';

    private function __construct(
        public readonly string $level,
        public readonly string $key,
        public readonly ?int $branchId = null,
        public readonly ?int $categoryId = null,
        public readonly ?int $productId = null,
    ) {
    }

    /** El producto prevalece sobre la categoría (un producto pertenece a una sola categoría). */
    public static function fromFilters(?int $branchId, ?int $categoryId, ?int $productId): self
    {
        if ($productId !== null) {
            return $branchId !== null
                ? new self(self::LEVEL_SERIES, "series:{$branchId}:{$productId}", $branchId, null, $productId)
                : new self(self::LEVEL_PRODUCT, "product:{$productId}", null, null, $productId);
        }
        if ($categoryId !== null) {
            return $branchId !== null
                ? new self(self::LEVEL_BRANCH_CATEGORY, "branch_category:{$branchId}:{$categoryId}", $branchId, $categoryId)
                : new self(self::LEVEL_CATEGORY, "category:{$categoryId}", null, $categoryId);
        }
        if ($branchId !== null) {
            return new self(self::LEVEL_BRANCH, "branch:{$branchId}", $branchId);
        }

        return new self(self::LEVEL_TOTAL, 'total');
    }

    /** ¿El nodo corresponde a una serie o a un producto (donde el quiebre de stock es binario)? */
    public function isItemLevel(): bool
    {
        return in_array($this->level, [self::LEVEL_SERIES, self::LEVEL_PRODUCT], true);
    }
}
