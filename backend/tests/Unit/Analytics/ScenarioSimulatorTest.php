<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Services\Scenarios\ScenarioBaseline;
use App\Services\Scenarios\ScenarioSimulator;
use PHPUnit\Framework\TestCase;

class ScenarioSimulatorTest extends TestCase
{
    private ScenarioSimulator $simulator;

    protected function setUp(): void
    {
        $this->simulator = new ScenarioSimulator;
    }

    private function baseline(array $replenishment = [], ?float $score = 80.0): ScenarioBaseline
    {
        return new ScenarioBaseline(30, [
            ['product_id' => 1, 'units' => 100.0, 'revenue' => 1000.0, 'unit_cost' => 6.0],   // precio 10
            ['product_id' => 2, 'units' => 50.0, 'revenue' => 1000.0, 'unit_cost' => 15.0],   // precio 20
        ], $replenishment, $score);
    }

    private function rec(array $over = []): array
    {
        return array_merge([
            'product_id' => 1, 'branch_id' => 1, 'suggested_qty' => 40.0, 'reorder_point' => 60.0, 'available' => 20.0,
            'demand_lead_time' => 50.0, 'safety_stock' => 10.0, 'lead_time_days' => 7, 'review_period_days' => 7,
            'unit_cost' => 6.0, 'risk_level' => 'high',
        ], $over);
    }

    public function test_no_assumptions_reproduces_base(): void
    {
        $r = $this->simulator->simulate($this->baseline(), []);

        $this->assertSame('simulation', $r['type']);
        $this->assertSame(ScenarioSimulator::DISCLAIMER, $r['disclaimer']);
        $this->assertSame($r['base'], $r['scenario']);
        $this->assertEqualsWithDelta(150.0, $r['base']['units'], 1e-9);
        $this->assertEqualsWithDelta(2000.0, $r['base']['revenue'], 1e-9);
        $this->assertEqualsWithDelta(100 * 6 + 50 * 15, $r['base']['cost'], 1e-9);
        $this->assertEqualsWithDelta(650.0, $r['base']['gross_margin'], 1e-9);
        $this->assertEqualsWithDelta(0.325, $r['base']['gross_margin_pct'], 1e-9);
        $this->assertSame(0.0, $r['delta']['revenue']);
        $this->assertSame(1.0, $r['cost_coverage']);
    }

    public function test_price_discount_cost_and_demand_rules(): void
    {
        $r = $this->simulator->simulate($this->baseline(), [
            'price_change_pct' => 0.10, 'discount_pct' => 0.05, 'cost_change_pct' => 0.20, 'demand_change_pct' => -0.10,
        ]);

        $priceFactor = 1.10 * 0.95;
        $this->assertEqualsWithDelta(135.0, $r['scenario']['units'], 1e-9);
        $this->assertEqualsWithDelta(2000 * 0.9 * $priceFactor, $r['scenario']['revenue'], 0.01);
        $this->assertEqualsWithDelta((100 * 6 + 50 * 15) * 0.9 * 1.2, $r['scenario']['cost'], 0.01);
        $this->assertEqualsWithDelta($r['scenario']['revenue'] - $r['scenario']['cost'], $r['scenario']['gross_margin'], 0.02);
        $this->assertEqualsWithDelta(-15.0, $r['delta']['units'], 1e-9);
        // El cambio de precio NO modifica la demanda por sí solo.
        $onlyPrice = $this->simulator->simulate($this->baseline(), ['price_change_pct' => -0.3]);
        $this->assertEqualsWithDelta(150.0, $onlyPrice['scenario']['units'], 1e-9);
        $this->assertContains('price_without_demand_assumption', array_column($onlyPrice['risks'], 'code'));
    }

    public function test_recommendations_without_quantity_are_reported_not_silently_dropped(): void
    {
        $baseline = new ScenarioBaseline(30, [
            ['product_id' => 1, 'units' => 100.0, 'revenue' => 1000.0, 'unit_cost' => 6.0],
        ], [$this->rec()], 80.0, 3);

        $r = $this->simulator->simulate($baseline, []);
        $risk = collect($r['risks'])->firstWhere('code', 'coverage_insufficient');
        $this->assertNotNull($risk);
        $this->assertStringContainsString('3 recomendaciones', $risk['message']);
        $this->assertNull(collect($this->simulator->simulate($this->baseline([$this->rec()]), [])['risks'])->firstWhere('code', 'coverage_insufficient'));
    }

    public function test_negative_margin_is_flagged_as_critical(): void
    {
        $r = $this->simulator->simulate($this->baseline(), ['price_change_pct' => -0.5, 'cost_change_pct' => 0.5]);

        $this->assertLessThan(0, $r['scenario']['gross_margin']);
        $risk = collect($r['risks'])->firstWhere('code', 'negative_margin');
        $this->assertNotNull($risk);
        $this->assertSame('critical', $risk['severity']);
    }

    public function test_margin_drop_warning(): void
    {
        $r = $this->simulator->simulate($this->baseline(), ['discount_pct' => 0.15]);

        $this->assertContains('margin_drop', array_column($r['risks'], 'code'));
        $this->assertNotContains('negative_margin', array_column($r['risks'], 'code'));
    }

    public function test_budget_shortfall_and_coverage(): void
    {
        $r = $this->simulator->simulate($this->baseline([$this->rec()]), ['purchase_budget' => 120]);

        $this->assertEqualsWithDelta(40.0, $r['base']['purchase_need_units'], 1e-9);
        $this->assertEqualsWithDelta(240.0, $r['base']['purchase_need_cost'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $r['budget']['coverage_pct'], 1e-9);
        $this->assertEqualsWithDelta(120.0, $r['budget']['shortfall'], 1e-9);
        $codes = array_column($r['risks'], 'code');
        $this->assertContains('budget_shortfall', $codes);
        $this->assertContains('stockout_risk', $codes);

        $enough = $this->simulator->simulate($this->baseline([$this->rec()]), ['purchase_budget' => 1000]);
        $this->assertSame(1.0, $enough['budget']['coverage_pct']);
        $this->assertSame(0.0, $enough['budget']['shortfall']);
    }

    public function test_unknown_costs_limit_margin_and_are_reported(): void
    {
        $baseline = new ScenarioBaseline(30, [
            ['product_id' => 1, 'units' => 100.0, 'revenue' => 1000.0, 'unit_cost' => 6.0],
            ['product_id' => 3, 'units' => 10.0, 'revenue' => 1000.0, 'unit_cost' => null],
        ]);
        $r = $this->simulator->simulate($baseline, []);

        $this->assertEqualsWithDelta(2000.0, $r['base']['revenue'], 1e-9);
        $this->assertEqualsWithDelta(400.0, $r['base']['gross_margin'], 1e-9); // solo producto con costo
        $this->assertEqualsWithDelta(0.4, $r['base']['gross_margin_pct'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $r['cost_coverage'], 1e-9);
        $risk = collect($r['risks'])->firstWhere('code', 'cost_unknown');
        $this->assertStringContainsString('1 productos', $risk['message']);
    }

    public function test_longer_lead_time_and_more_demand_increase_suggested_purchase(): void
    {
        $rec = $this->rec();
        $same = $this->simulator->scenarioSuggestedQty($rec, 1.0, null);
        $this->assertEqualsWithDelta(40.0, $same, 1e-9);

        $longer = $this->simulator->scenarioSuggestedQty($rec, 1.0, 14); // (14+7)/(7+7) = 1,5
        $expectedRop = 50 * 1.5 + 10 * sqrt(1.5);
        $this->assertEqualsWithDelta(40 + ($expectedRop - 60), $longer, 1e-4);

        $moreDemand = $this->simulator->scenarioSuggestedQty($rec, 1.2, null);
        $this->assertEqualsWithDelta(40 + (60 * 1.2 - 60), $moreDemand, 1e-4);

        // Sin compra sugerida en la base pero el nuevo punto de reorden supera lo disponible.
        $noNeed = $this->rec(['suggested_qty' => 0.0, 'available' => 70.0]);
        $this->assertSame(0.0, $this->simulator->scenarioSuggestedQty($noNeed, 1.0, null));
        $this->assertEqualsWithDelta(72.0 - 70.0, $this->simulator->scenarioSuggestedQty($noNeed, 1.2, null), 1e-4);
        // Menos demanda nunca produce cantidades negativas.
        $this->assertSame(0.0, $this->simulator->scenarioSuggestedQty($rec, 0.1, null));
    }

    public function test_low_data_quality_and_empty_baseline(): void
    {
        $low = $this->simulator->simulate($this->baseline([], 40.0), []);
        $this->assertContains('low_data_quality', array_column($low['risks'], 'code'));

        $empty = $this->simulator->simulate(new ScenarioBaseline(7, []), ['demand_change_pct' => 0.5]);
        $this->assertSame(0.0, $empty['scenario']['units']);
        $this->assertNull($empty['base']['gross_margin_pct']);
        $this->assertNull($empty['cost_coverage']);
        $this->assertContains('low_data_quality', array_column($empty['risks'], 'code'));
    }

    public function test_assumptions_used_are_reported_explicitly(): void
    {
        $r = $this->simulator->simulate($this->baseline(), ['demand_change_pct' => 0.2, 'lead_time_days' => 10, 'macro_note' => 'Inflación 3%', 'unknown' => 5]);

        $this->assertSame(0.2, $r['assumptions_used']['demand_change_pct']);
        $this->assertSame(10, $r['assumptions_used']['lead_time_days']);
        $this->assertSame('Inflación 3%', $r['assumptions_used']['macro_note']);
        $this->assertArrayNotHasKey('unknown', $r['assumptions_used']);
        $this->assertNotEmpty($r['assumptions_used']['rules']);
    }
}
