<?php

declare(strict_types=1);

namespace App\Services\DataCore\Quality;

use App\Services\Imports\Parsing\ValueParser;

/**
 * Cifras de una fila de ventas LEÍDAS DEL ORIGEN (capa fuente de la reconciliación): unidades e importe neto firmados, tipo y estado,
 * interpretados con el mismo criterio que el normalizador pero SIN validar, de modo que una fila rechazada o en cuarentena también
 * aporta su cifra a la fuente (y su diferencia con Silver queda explicada, no escondida).
 *
 * Unidades = |cantidad| de líneas que no son devolución; importe neto firmado = −neto en devoluciones (nota de crédito o cantidad
 * negativa). Neto = `net_amount` del origen o, si falta, cantidad × precio − descuento (igual que `SalesLineNormalizer`).
 */
final class SalesFigures
{
    /**
     * @param  array<string, mixed>  $raw  fila canónica cruda (con mapeos de valores ya aplicados)
     * @return array{units: float, net: float, is_return: bool, type: string, status: string, amount_known: bool}
     */
    public static function fromRaw(array $raw, ValueParser $p): array
    {
        $type = ValueParser::documentType($p->string($raw['document_type'] ?? null, 50)) ?? 'other';
        $status = ValueParser::documentStatus($p->string($raw['status'] ?? null, 50)) ?? 'unknown';
        $quantity = ValueParser::isBlank($raw['quantity'] ?? null) ? null : $p->number($raw['quantity']);
        $isReturn = $type === 'credit_note' || ($quantity !== null && $quantity < 0);
        $abs = $quantity !== null ? abs($quantity) : null;

        $net = null;
        if (! ValueParser::isBlank($raw['net_amount'] ?? null) && ($value = $p->number($raw['net_amount'])) !== null) {
            $net = abs($value);
        } elseif ($abs !== null && ! ValueParser::isBlank($raw['unit_price'] ?? null) && ($price = $p->number($raw['unit_price'])) !== null) {
            $discount = 0.0;
            if (! ValueParser::isBlank($raw['discount'] ?? null)) {
                $pct = $p->percentage($raw['discount']);
                $discount = $pct !== null ? $abs * $price * $pct : (float) ($p->number($raw['discount']) ?? 0.0);
            }
            $net = round($abs * $price - $discount, 4);
        }

        return [
            'units' => $abs !== null && ! $isReturn ? $abs : 0.0,
            'net' => $net === null ? 0.0 : ($isReturn ? -$net : $net),
            'is_return' => $isReturn,
            'type' => $type,
            'status' => $status,
            'amount_known' => $net !== null,
        ];
    }

    /**
     * Cifras de una línea ya normalizada.
     *
     * @param  array<string, mixed>  $line  línea de `SalesLineNormalizer::normalize`
     * @return array{units: float, net: float, is_return: bool, type: string, status: string, amount_known: bool}
     */
    public static function fromLine(array $line): array
    {
        $isReturn = (bool) $line['is_return'];

        return [
            'units' => $isReturn ? 0.0 : (float) $line['quantity'],
            'net' => $isReturn ? -(float) $line['net_amount'] : (float) $line['net_amount'],
            'is_return' => $isReturn,
            'type' => (string) $line['document_type'],
            'status' => (string) $line['status'],
            'amount_known' => true,
        ];
    }

    /**
     * Cifras de un documento ensamblado (suma de sus líneas).
     *
     * @param  array<string, mixed>  $doc
     * @return array{lines: int, units: float, net: float}
     */
    public static function ofDocument(array $doc): array
    {
        $units = 0.0;
        $net = 0.0;
        foreach ($doc['lines'] as $line) {
            $units += $line['is_return'] ? 0.0 : (float) $line['quantity'];
            $net += $line['is_return'] ? -(float) $line['net_amount'] : (float) $line['net_amount'];
        }

        return ['lines' => count($doc['lines']), 'units' => $units, 'net' => $net];
    }
}
