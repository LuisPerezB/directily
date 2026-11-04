<?php

declare(strict_types=1);

namespace App\Services\Outcomes;

use Carbon\CarbonImmutable;

/**
 * Resumen puro (sin base de datos) de las líneas de venta de UN cliente dentro de un alcance de productos (V2.5 · W5-C).
 *
 *  - Compras = líneas de venta que no son devolución; devoluciones = `is_return` o nota de crédito. Se restan por producto y NUNCA bajan de cero: las devoluciones de
 *    compras anteriores a la ventana no crean «ventas negativas». `net_units` = unidades netas; hay «compra» solo si `net_units > 0`. Las devoluciones y notas de
 *    crédito con fecha POSTERIOR al cierre de la ventana pero conocidas al corte de conocimiento también se restan (se informan en `returns_after_window`).
 *  - «Después de la acción»: solo cuenta lo emitido (`issued_at`) ESTRICTAMENTE posterior al momento en que se registró la acción. Sin acción registrada no hay
 *    «después». Registrar tarde la acción solo hace la medición más conservadora. `after_action` resume SOLO esas líneas (compras posteriores netas de las devoluciones
 *    emitidas también después de la acción): es lo único que se puede asociar a la acción; lo anterior queda en la observación de la ventana completa.
 *  - `lines`: las líneas leídas (venta, línea, producto, unidades, importe, devolución, posterior a la acción) para que el informe no cuente dos veces la misma línea
 *    cuando varias recomendaciones (otro horizonte, contacto y recuperación) la observan.
 *  - Sin datos personales: devuelve ids de venta (`sale:<id>`), no nombres ni documentos.
 */
final class ObservationCalculator
{
    private const SALE_IDS_CAP = 20;

    /** Tope de líneas guardadas por resultado para la deduplicación; más allá se marca `lines_truncated` y el informe lo declara. */
    public const LINES_CAP = 100;

    /**
     * @param  list<object>  $lines  líneas conocidas al corte de UN cliente
     * @param  list<int>|null  $productIds  alcance (null = cualquier producto)
     * @param  string|null  $windowTo  último día de la ventana (para separar las devoluciones posteriores a ella)
     * @return array<string, mixed>
     */
    public function summarize(array $lines, ?array $productIds, ?CarbonImmutable $actionAt, ?string $windowTo = null): array
    {
        $entries = [];
        $keys = [];
        foreach ($lines as $l) {
            $pid = (int) $l->product_id;
            if ($productIds !== null && ! in_array($pid, $productIds, true)) {
                continue;
            }
            $keys[] = [(int) $l->sale_id, (int) $l->revision, (int) $l->line_number, $pid, (int) $l->customer_id, number_format((float) $l->quantity, 4, '.', ''), number_format((float) $l->net_amount, 4, '.', ''), (bool) $l->is_return];
            $issued = $l->issued_at === null ? null : CarbonImmutable::parse((string) $l->issued_at);
            $entries[] = [
                'pid' => $pid, 'units' => (float) $l->quantity, 'amount' => (float) $l->net_amount, 'ret' => (bool) $l->is_return, 'sale' => (int) $l->sale_id, 'line' => (int) $l->line_number,
                'after' => $actionAt !== null && $issued !== null && $issued->greaterThan($actionAt),
                'late' => $windowTo !== null && substr((string) $l->sale_date, 0, 10) > $windowTo,
            ];
        }

        $whole = $this->net($entries);
        $purchases = array_values(array_filter($entries, fn (array $e) => ! $e['ret']));
        $afterPurchases = array_values(array_filter($purchases, fn (array $e) => $e['after']));
        $beforePurchases = $actionAt === null ? [] : array_values(array_filter($purchases, fn (array $e) => ! $e['after']));
        $saleIds = [];
        foreach ($purchases as $e) {
            $saleIds[$e['sale']] = true;
        }
        $lateReturns = array_values(array_filter($entries, fn (array $e) => $e['ret'] && $e['late']));

        $afterAction = null;
        if ($actionAt !== null) {
            $after = $this->net(array_values(array_filter($entries, fn (array $e) => $e['after'])));
            $afterAction = [
                'basis' => 'compras emitidas después de la acción registrada, netas de las devoluciones emitidas también después',
                'by_product' => $after['by_product'], 'net_units' => $after['net_units'], 'net_amount' => $after['net_amount'], 'gross_units' => $after['gross_units'],
                'gross_net_amount' => $after['gross_net_amount'], 'returned_units' => $after['returned_units'], 'returned_net_amount' => $after['returned_net_amount'],
                'purchase_lines' => count($afterPurchases),
            ];
        }
        usort($keys, fn (array $a, array $b) => $a <=> $b);

        $stored = array_map(fn (array $e) => [$e['sale'], $e['line'], $e['pid'], round($e['units'], 4), round($e['amount'], 4), $e['ret'] ? 1 : 0, $e['after'] ? 1 : 0], $entries);

        return [
            'by_product' => $whole['by_product'],
            'net_units' => $whole['net_units'],
            'net_amount' => $whole['net_amount'],
            'gross_units' => $whole['gross_units'],
            'gross_net_amount' => $whole['gross_net_amount'],
            'returned_units' => $whole['returned_units'],
            'returned_net_amount' => $whole['returned_net_amount'],
            'returns_after_window' => [
                'lines' => count($lateReturns), 'units' => round(array_sum(array_map(fn (array $e) => $e['units'], $lateReturns)), 4),
                'net_amount' => round(array_sum(array_map(fn (array $e) => $e['amount'], $lateReturns)), 4),
            ],
            'purchase_lines' => count($purchases),
            'purchases_after_action' => count($afterPurchases),
            'purchases_before_action' => count($beforePurchases),
            'after_action' => $afterAction,
            'first_ref' => $purchases[0]['sale'] ?? null,
            'first_after_action_ref' => $afterPurchases[0]['sale'] ?? null,
            'sale_ids' => array_slice(array_keys($saleIds), 0, self::SALE_IDS_CAP),
            'lines' => array_slice($stored, 0, self::LINES_CAP),
            'lines_truncated' => count($stored) > self::LINES_CAP,
            'fingerprint' => hash('sha256', json_encode($keys, JSON_THROW_ON_ERROR)),
            'lines_read' => count($keys),
        ];
    }

    /**
     * Neto por producto: compras menos devoluciones, sin bajar de cero.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array{by_product: array<int, array<string, float>>, net_units: float, net_amount: float, gross_units: float, gross_net_amount: float, returned_units: float, returned_net_amount: float}
     */
    private function net(array $entries): array
    {
        $byProduct = [];
        foreach ($entries as $e) {
            $p = $byProduct[$e['pid']] ?? ['gross_units' => 0.0, 'gross_net' => 0.0, 'ret_units' => 0.0, 'ret_net' => 0.0];
            if ($e['ret']) {
                $p['ret_units'] += $e['units'];
                $p['ret_net'] += $e['amount'];
            } else {
                $p['gross_units'] += $e['units'];
                $p['gross_net'] += $e['amount'];
            }
            $byProduct[$e['pid']] = $p;
        }
        $net = [];
        $units = 0.0;
        $amount = 0.0;
        $gross = ['units' => 0.0, 'net' => 0.0, 'ret_units' => 0.0, 'ret_net' => 0.0];
        foreach ($byProduct as $pid => $p) {
            $netUnits = max(0.0, $p['gross_units'] - $p['ret_units']);
            $netAmount = $netUnits > 0 ? max(0.0, $p['gross_net'] - $p['ret_net']) : 0.0;
            $net[$pid] = ['gross_units' => round($p['gross_units'], 4), 'returned_units' => round($p['ret_units'], 4), 'net_units' => round($netUnits, 4), 'net_amount' => round($netAmount, 4)];
            $units += $netUnits;
            $amount += $netAmount;
            $gross['units'] += $p['gross_units'];
            $gross['net'] += $p['gross_net'];
            $gross['ret_units'] += $p['ret_units'];
            $gross['ret_net'] += $p['ret_net'];
        }
        ksort($net);

        return [
            'by_product' => $net, 'net_units' => round($units, 4), 'net_amount' => round($amount, 4), 'gross_units' => round($gross['units'], 4), 'gross_net_amount' => round($gross['net'], 4),
            'returned_units' => round($gross['ret_units'], 4), 'returned_net_amount' => round($gross['ret_net'], 4),
        ];
    }
}
