<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Models\CoreRun;
use App\Models\Tenant;
use App\Support\Results\ResultEnvelope;

/** Envoltura común (ADR-V2-03) de las respuestas del Customer Core. */
final class CustomerEnvelope
{
    /** @var array<string, string> */
    public const DEFINITIONS = [
        'purchase_probability' => 'Probabilidad de al menos una compra válida del producto por el comprador en (fecha, fecha + H días]; excluye anuladas y notas de crédito; todas las sucursales.',
        'quantity_if_purchase' => 'Suma de TODAS las compras válidas de la ventana, condicionada a que haya al menos una; las devoluciones se informan aparte.',
        'expected_quantity' => 'purchase_probability × quantity_if_purchase (el cero de «no compra» está incluido). No incluye pedidos confirmados: ver committed_quantity.',
        'expected_amount' => 'expected_quantity × último precio neto de la relación (supuesto explícito, sin impuestos). Es una esperanza, no una venta confirmada.',
        'committed_quantity' => 'Pendiente (cantidad − cumplida) de pedidos/contratos confirmados, conocidos a la fecha, no cancelados y con entrega dentro del horizonte. La esperanza no se suma al comprometido: la demanda del comprador es max(esperanza, comprometido).',
        'ranking_source' => 'Fuente del ranking de productos probables, elegida en el holdout por precision@k (regla cust-ranking-v1): model (probabilidad servida), popularity (compradores distintos en 90 días) o recency (compra más reciente). Popularidad es la fuente por defecto: otra solo se sirve si la supera por un margen mínimo.',
        'inactivity' => 'Ausencia de compras respecto al patrón propio del comprador (percentiles 75/90 de sus intervalos). No es abandono definitivo.',
    ];

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function forRun(CoreRun $run, Tenant $tenant, ?int $horizon = null, array $extra = []): array
    {
        $over = [
            'entity_keys' => ['customer_id', 'product_id'],
            'unit' => 'product_unit',
            'currency' => $tenant->currency ?: 'USD',
        ];
        if ($horizon !== null && $run->as_of !== null) {
            $over['horizon_start'] = $run->as_of->addDay()->toDateString();
            $over['horizon_end'] = $run->as_of->addDays($horizon)->toDateString();
            $over['target_definition'] = array_merge((array) $run->target_definition, ['horizon_days' => $horizon]);
        }

        return ResultEnvelope::fromCoreRun($run, array_merge($over, $extra));
    }

    /**
     * Respuesta sin corrida: abstención explícita (no hay datos que mostrar), nunca ceros inventados.
     *
     * @return array<string, mixed>
     */
    public static function missing(Tenant $tenant, string $resultType, string $reason): array
    {
        return [
            'tenant_id' => (int) $tenant->id, 'run_id' => null, 'core' => 'customer', 'run_version' => null, 'status' => null,
            'as_of' => null, 'horizon_start' => null, 'horizon_end' => null, 'target_definition' => null,
            'entity_keys' => ['customer_id', 'product_id'], 'unit' => 'product_unit', 'currency' => $tenant->currency ?: 'USD',
            'result_type' => $resultType, 'model_version' => null, 'data_snapshot_id' => null, 'config_version' => null,
            'assumptions' => [], 'limitations' => [$reason], 'quality_status' => 'insufficient_data', 'uncertainty_source' => 'unknown',
        ];
    }
}
