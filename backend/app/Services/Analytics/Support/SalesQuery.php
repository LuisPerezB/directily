<?php

declare(strict_types=1);

namespace App\Services\Analytics\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Definición ÚNICA de "venta válida para demanda", idéntica a la del motor (docs/06 §4.1):
 * documentos completados (no anulados) de tipo invoice/sales_note/other y líneas que no
 * son devolución. Las devoluciones se reportan aparte, no se restan de la demanda.
 */
final class SalesQuery
{
    public const DEMAND_DOCUMENT_TYPES = ['invoice', 'sales_note', 'other'];

    /** Líneas de venta válidas de la empresa (alias: si = sale_items, s = sales). */
    public static function validItems(int $tenantId): Builder
    {
        return DB::table('sale_items as si')
            ->join('sales as s', function ($join) {
                $join->on('s.id', '=', 'si.sale_id')->on('s.tenant_id', '=', 'si.tenant_id');
            })
            ->where('si.tenant_id', $tenantId)
            ->where('s.status', 'completed')
            ->whereIn('s.document_type', self::DEMAND_DOCUMENT_TYPES)
            ->where('si.is_return', false);
    }

    /** Líneas de devolución (notas de crédito o líneas marcadas), excluyendo anuladas. */
    public static function returnItems(int $tenantId): Builder
    {
        return DB::table('sale_items as si')
            ->join('sales as s', function ($join) {
                $join->on('s.id', '=', 'si.sale_id')->on('s.tenant_id', '=', 'si.tenant_id');
            })
            ->where('si.tenant_id', $tenantId)
            ->where('s.status', 'completed')
            ->where(function (Builder $q) {
                $q->where('si.is_return', true)->orWhere('s.document_type', 'credit_note');
            });
    }

    /** Aplica filtros de nodo (sucursal, categoría, producto) a una consulta sobre `si`. */
    public static function applyFilters(Builder $query, int $tenantId, ?int $branchId, ?int $categoryId, ?int $productId): Builder
    {
        if ($branchId !== null) {
            $query->where('si.branch_id', $branchId);
        }
        if ($productId !== null) {
            $query->where('si.product_id', $productId);
        } elseif ($categoryId !== null) {
            $query->whereExists(function (Builder $q) use ($tenantId, $categoryId) {
                $q->selectRaw('1')->from('products as pf')
                    ->whereColumn('pf.id', 'si.product_id')
                    ->where('pf.tenant_id', $tenantId)
                    ->where('pf.category_id', $categoryId);
            });
        }

        return $query;
    }

    public static function applyNode(Builder $query, int $tenantId, ForecastNode $node): Builder
    {
        return self::applyFilters($query, $tenantId, $node->branchId, $node->categoryId, $node->productId);
    }

    /** Última fecha con ventas válidas (null si la empresa no tiene ventas). */
    public static function lastSaleDate(int $tenantId): ?string
    {
        $date = self::validItems($tenantId)->max('si.sale_date');

        return $date ? substr((string) $date, 0, 10) : null;
    }
}
