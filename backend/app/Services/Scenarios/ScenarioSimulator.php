<?php

declare(strict_types=1);

namespace App\Services\Scenarios;

/**
 * Simulador de escenarios: cálculo MATEMÁTICO y determinista sobre supuestos explícitos.
 *
 * No es una predicción estadística ni una estimación causal: el cambio de demanda es un
 * supuesto del usuario y NO se deriva del cambio de precio (la elasticidad se estimará en
 * una fase posterior). Reglas: docs/06 §3.11.
 */
final class ScenarioSimulator
{
    public const DISCLAIMER = 'Simulación matemática basada en supuestos ingresados por el usuario. No es una predicción estadística ni una estimación causal: el cambio de demanda NO se deriva del cambio de precio.';

    public const DEFAULT_ASSUMPTIONS = [
        'price_change_pct' => 0.0,
        'discount_pct' => 0.0,
        'cost_change_pct' => 0.0,
        'demand_change_pct' => 0.0,
        'lead_time_days' => null,
        'purchase_budget' => null,
        'macro_note' => null,
    ];

    /**
     * @param  array<string, mixed>  $assumptions
     * @return array<string, mixed>
     */
    public function simulate(ScenarioBaseline $baseline, array $assumptions): array
    {
        $a = array_merge(self::DEFAULT_ASSUMPTIONS, array_intersect_key($assumptions, self::DEFAULT_ASSUMPTIONS));
        $priceFactor = (1 + (float) $a['price_change_pct']) * (1 - (float) $a['discount_pct']);
        $costFactor = 1 + (float) $a['cost_change_pct'];
        $demandFactor = 1 + (float) $a['demand_change_pct'];
        $leadTimeOverride = $a['lead_time_days'] !== null ? (int) $a['lead_time_days'] : null;

        $base = $this->emptyTotals();
        $scenario = $this->emptyTotals();
        $revenueWithoutCost = 0.0;
        $productsWithoutCost = 0;
        $costByProduct = [];

        foreach ($baseline->products as $line) {
            $units = max(0.0, (float) $line['units']);
            $revenue = (float) ($line['revenue'] ?? 0.0);
            $price = $units > 0 ? $revenue / $units : 0.0;
            $cost = $line['unit_cost'] !== null ? (float) $line['unit_cost'] : null;
            $costByProduct[(int) $line['product_id']] = $cost;

            $sUnits = $units * $demandFactor;
            $sRevenue = $sUnits * $price * $priceFactor;

            $base['units'] += $units;
            $base['revenue'] += $revenue;
            $scenario['units'] += $sUnits;
            $scenario['revenue'] += $sRevenue;

            if ($cost === null) {
                if ($units > 0) {
                    $productsWithoutCost++;
                    $revenueWithoutCost += $revenue;
                }

                continue;
            }
            $base['cost'] += $units * $cost;
            $base['revenue_with_cost'] += $revenue;
            $scenario['cost'] += $sUnits * $cost * $costFactor;
            $scenario['revenue_with_cost'] += $sRevenue;
        }

        foreach ($baseline->replenishment as $rec) {
            $unitCost = $rec['unit_cost'] ?? ($costByProduct[(int) $rec['product_id']] ?? null);
            $suggested = max(0.0, (float) $rec['suggested_qty']);
            $sSuggested = $this->scenarioSuggestedQty($rec, $demandFactor, $leadTimeOverride);

            $base['purchase_need_units'] += $suggested;
            $scenario['purchase_need_units'] += $sSuggested;
            if ($unitCost !== null) {
                $base['purchase_need_cost'] += $suggested * (float) $unitCost;
                $scenario['purchase_need_cost'] += $sSuggested * (float) $unitCost * $costFactor;
            }
        }

        $base = $this->finalize($base);
        $scenario = $this->finalize($scenario);

        $budget = null;
        if ($a['purchase_budget'] !== null) {
            $budgetValue = max(0.0, (float) $a['purchase_budget']);
            $need = $scenario['purchase_need_cost'];
            $budget = [
                'purchase_budget' => round($budgetValue, 2),
                'coverage_pct' => $need > 0 ? round(min(1.0, $budgetValue / $need), 4) : 1.0,
                'shortfall' => round(max(0.0, $need - $budgetValue), 2),
            ];
        }

        $totalRevenue = $base['revenue'];
        $costCoverage = $totalRevenue > 0 ? round(1 - $revenueWithoutCost / $totalRevenue, 4) : null;

        return [
            'type' => 'simulation',
            'disclaimer' => self::DISCLAIMER,
            'horizon_days' => $baseline->horizonDays,
            'base' => $base,
            'scenario' => $scenario,
            'delta' => [
                'units' => round($scenario['units'] - $base['units'], 4),
                'revenue' => round($scenario['revenue'] - $base['revenue'], 2),
                'gross_margin' => round($scenario['gross_margin'] - $base['gross_margin'], 2),
                'gross_margin_pct' => $scenario['gross_margin_pct'] !== null && $base['gross_margin_pct'] !== null
                    ? round($scenario['gross_margin_pct'] - $base['gross_margin_pct'], 4) : null,
                'purchase_need_cost' => round($scenario['purchase_need_cost'] - $base['purchase_need_cost'], 2),
            ],
            'budget' => $budget,
            'cost_coverage' => $costCoverage,
            'risks' => $this->risks($baseline, $a, $base, $scenario, $budget, $productsWithoutCost, $costCoverage),
            'assumptions_used' => [
                'price_change_pct' => (float) $a['price_change_pct'],
                'discount_pct' => (float) $a['discount_pct'],
                'cost_change_pct' => (float) $a['cost_change_pct'],
                'demand_change_pct' => (float) $a['demand_change_pct'],
                'lead_time_days' => $leadTimeOverride,
                'purchase_budget' => $a['purchase_budget'] !== null ? (float) $a['purchase_budget'] : null,
                'macro_note' => $a['macro_note'],
                'rules' => [
                    'Unidades del escenario = unidades pronosticadas × (1 + cambio de demanda supuesto).',
                    'Precio del escenario = precio supuesto del pronóstico (último precio neto observado) × (1 + cambio de precio) × (1 − descuento).',
                    'Costo del escenario = último costo conocido × (1 + cambio de costo).',
                    'Compra sugerida del escenario: se ajusta el punto de reorden del motor según el cambio de demanda y de plazo de abastecimiento (la variabilidad escala con la raíz del plazo).',
                    'El margen bruto se calcula solo sobre productos con costo conocido.',
                ],
            ],
        ];
    }

    /**
     * Cantidad sugerida del escenario a partir de la recomendación del motor, ajustando
     * la demanda en el plazo y el stock de seguridad. Conserva la estructura de cálculo del
     * motor aplicando la variación del punto de reorden.
     *
     * @param  array<string, mixed>  $rec
     */
    public function scenarioSuggestedQty(array $rec, float $demandFactor, ?int $leadTimeOverride): float
    {
        $leadTime = max(0, (int) $rec['lead_time_days']);
        $review = max(0, (int) ($rec['review_period_days'] ?? 0));
        $newLeadTime = $leadTimeOverride ?? $leadTime;
        $timeFactor = ($leadTime + $review) > 0 ? ($newLeadTime + $review) / ($leadTime + $review) : 1.0;

        $demand = (float) $rec['demand_lead_time'] * $demandFactor * $timeFactor;
        $safety = (float) $rec['safety_stock'] * $demandFactor * sqrt($timeFactor);
        $newReorderPoint = $demand + $safety;

        $available = (float) ($rec['available'] ?? 0.0);
        $gap = (float) $rec['reorder_point'] - $available;
        $newGap = $newReorderPoint - $available;
        $suggested = max(0.0, (float) $rec['suggested_qty']);

        return round($suggested > 0 ? max(0.0, $suggested + ($newGap - $gap)) : max(0.0, $newGap), 4);
    }

    /** @return array<string, float> */
    private function emptyTotals(): array
    {
        return ['units' => 0.0, 'revenue' => 0.0, 'cost' => 0.0, 'revenue_with_cost' => 0.0, 'purchase_need_units' => 0.0, 'purchase_need_cost' => 0.0];
    }

    /**
     * @param  array<string, float>  $t
     * @return array<string, float|null>
     */
    private function finalize(array $t): array
    {
        $margin = $t['revenue_with_cost'] - $t['cost'];

        return [
            'units' => round($t['units'], 4),
            'revenue' => round($t['revenue'], 2),
            'cost' => round($t['cost'], 2),
            'gross_margin' => round($margin, 2),
            'gross_margin_pct' => $t['revenue_with_cost'] > 0 ? round($margin / $t['revenue_with_cost'], 4) : null,
            'purchase_need_units' => round($t['purchase_need_units'], 4),
            'purchase_need_cost' => round($t['purchase_need_cost'], 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, float|null>  $base
     * @param  array<string, float|null>  $scenario
     * @param  array<string, float>|null  $budget
     * @return list<array{code: string, severity: string, message: string}>
     */
    private function risks(ScenarioBaseline $baseline, array $a, array $base, array $scenario, ?array $budget, int $productsWithoutCost, ?float $costCoverage): array
    {
        $risks = [];
        $money = fn (float $v) => '$'.number_format($v, 2, ',', '.');
        $pct = fn (float $v) => number_format($v * 100, 1, ',', '.').'%';

        if (($scenario['gross_margin'] ?? 0) < 0) {
            $risks[] = ['code' => 'negative_margin', 'severity' => 'critical', 'message' => 'Con estos supuestos el margen bruto es negativo ('.$money((float) $scenario['gross_margin']).'): se vendería por debajo del costo.'];
        } elseif ($scenario['gross_margin_pct'] !== null && $base['gross_margin_pct'] !== null && $scenario['gross_margin_pct'] < $base['gross_margin_pct'] - 0.05) {
            $risks[] = ['code' => 'margin_drop', 'severity' => 'warning', 'message' => 'El margen bruto baja de '.$pct((float) $base['gross_margin_pct']).' a '.$pct((float) $scenario['gross_margin_pct']).'.'];
        }

        if ($budget !== null && $budget['shortfall'] > 0) {
            $risks[] = ['code' => 'budget_shortfall', 'severity' => 'warning', 'message' => 'El presupuesto cubre el '.$pct((float) $budget['coverage_pct']).' de la compra sugerida; faltan '.$money((float) $budget['shortfall']).'.'];
        }

        $atRisk = count(array_filter($baseline->replenishment, fn ($r) => in_array($r['risk_level'] ?? null, ['high', 'critical'], true)));
        if ($atRisk > 0) {
            $extra = ($budget !== null && $budget['shortfall'] > 0) || (float) $a['demand_change_pct'] > 0
                ? ' Con este escenario el riesgo aumenta si no se cubre la compra sugerida.' : '';
            $risks[] = ['code' => 'stockout_risk', 'severity' => 'warning', 'message' => "{$atRisk} productos tienen riesgo alto de quiebre de stock en el escenario base.{$extra}"];
        }

        if ($productsWithoutCost > 0) {
            $share = $costCoverage !== null ? ' ('.$pct(1 - $costCoverage).' de los ingresos)' : '';
            $risks[] = ['code' => 'cost_unknown', 'severity' => 'warning', 'message' => "No hay costo registrado para {$productsWithoutCost} productos{$share}: el margen se calcula solo sobre productos con costo conocido."];
        }

        if ($baseline->dataQualityScore !== null && $baseline->dataQualityScore < 60) {
            $risks[] = ['code' => 'low_data_quality', 'severity' => 'warning', 'message' => 'La calidad de los datos es baja (puntaje '.number_format($baseline->dataQualityScore, 0).'/100): los resultados del escenario son menos confiables.'];
        }
        if ($baseline->products === []) {
            $risks[] = ['code' => 'low_data_quality', 'severity' => 'warning', 'message' => 'No hay pronóstico para el alcance seleccionado; el escenario no tiene base de cálculo.'];
        }

        if (((float) $a['price_change_pct'] !== 0.0 || (float) $a['discount_pct'] > 0) && (float) $a['demand_change_pct'] === 0.0) {
            $risks[] = ['code' => 'price_without_demand_assumption', 'severity' => 'info', 'message' => 'Cambió el precio pero no la demanda: el simulador no supone que la demanda reaccione al precio. Si espera un efecto, ingréselo como cambio de demanda.'];
        }

        return $risks;
    }
}
