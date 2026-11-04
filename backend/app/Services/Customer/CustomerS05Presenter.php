<?php

declare(strict_types=1);

namespace App\Services\Customer;

/**
 * Presenta las cantidades de S05 del Customer Core (V2.5 · W3-C, ADR-V25-11) de una fila de `customer_predictions` para la API:
 * nº de pedidos esperado, cantidad por pedido, unidades esperadas y la VENTANA probable de recompra con su estado.
 *
 * Regla de honestidad: las cifras de la ventana solo salen cuando el estado las respalda (`estimated`, `overdue`). Con `insufficient_data`,
 * `censored` o sin estado (corrida anterior a S05) los cuantiles salen nulos aunque la columna trajera un valor: lo que la validación del motor
 * no respalda no se publica. Nunca se fabrica un cero.
 */
final class CustomerS05Presenter
{
    /** Estados de la ventana con cifras. */
    public const WINDOW_WITH_FIGURES = ['estimated', 'overdue'];

    /** Todos los estados que el motor puede escribir. */
    public const WINDOW_STATUSES = ['estimated', 'insufficient_data', 'overdue', 'censored'];

    /** @return array<string, mixed> */
    public static function fields(object $row): array
    {
        $status = $row->window_status ?? null;
        $figures = in_array($status, self::WINDOW_WITH_FIGURES, true);

        return [
            'expected_orders' => self::number($row->expected_orders ?? null),
            'quantity_per_order' => self::number($row->quantity_per_order ?? null),
            'expected_units' => self::number($row->expected_units ?? null),
            'window' => [
                'status' => $status,
                'p10_days' => $figures ? self::number($row->window_p10_days ?? null) : null,
                'p50_days' => $figures ? self::number($row->window_p50_days ?? null) : null,
                'p90_days' => $figures ? self::number($row->window_p90_days ?? null) : null,
            ],
            'segment' => $row->segment ?? null,
        ];
    }

    private static function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
