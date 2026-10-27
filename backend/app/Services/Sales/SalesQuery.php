<?php

declare(strict_types=1);

namespace App\Services\Sales;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Definición única de "venta válida" para estadísticas y resúmenes:
 * documento completado (no anulado ni pendiente), tipo factura/nota de venta/otro,
 * línea que no es devolución. Coincide con la regla del motor (docs/06 §4.1).
 */
final class SalesQuery
{
    public const VALID_DOCUMENT_TYPES = ['invoice', 'sales_note', 'other'];

    /** sale_items si JOIN sales s, filtrado por tenant y ventas válidas. */
    public static function validItems(int $tenantId): Builder
    {
        return DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('si.tenant_id', $tenantId)
            ->where('s.tenant_id', $tenantId)
            ->where('s.status', 'completed')
            ->whereIn('s.document_type', self::VALID_DOCUMENT_TYPES)
            ->where('si.is_return', false);
    }

    /** Líneas de devolución (notas de crédito o líneas marcadas) de documentos no anulados. */
    public static function returnItems(int $tenantId): Builder
    {
        return DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('si.tenant_id', $tenantId)
            ->where('s.tenant_id', $tenantId)
            ->where('s.status', '!=', 'voided')
            ->where(fn (Builder $q) => $q->where('si.is_return', true)->orWhere('s.document_type', 'credit_note'));
    }
}
