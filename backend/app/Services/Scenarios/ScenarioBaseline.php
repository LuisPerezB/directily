<?php

declare(strict_types=1);

namespace App\Services\Scenarios;

/**
 * Datos base (calculados) sobre los que se aplican los supuestos del escenario.
 *
 * products: [{product_id, units, revenue, unit_cost|null}] — pronóstico del horizonte.
 * replenishment: [{product_id, branch_id, suggested_qty, reorder_point, available|null,
 *                  demand_lead_time, safety_stock, lead_time_days, review_period_days,
 *                  unit_cost|null, risk_level}] — recomendaciones del motor.
 */
final class ScenarioBaseline
{
    /**
     * @param  list<array{product_id: int, units: float, revenue: float|null, unit_cost: float|null}>  $products
     * @param  list<array<string, mixed>>  $replenishment
     */
    public function __construct(
        public readonly int $horizonDays,
        public readonly array $products,
        public readonly array $replenishment = [],
        public readonly ?float $dataQualityScore = null,
    ) {}
}
