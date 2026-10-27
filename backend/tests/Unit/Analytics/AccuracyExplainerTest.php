<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Services\Analytics\AccuracyExplainer;
use App\Services\Analytics\ProductForecastQuery;
use App\Services\Analytics\Support\ForecastNode;
use App\Services\Analytics\Support\ModelCatalog;
use App\Services\Analytics\Support\Num;
use PHPUnit\Framework\TestCase;

class AccuracyExplainerTest extends TestCase
{
    private function metrics(float $wape, ?float $bias = 0.0, ?float $coverage = 0.8, string $family = 'lightgbm_global'): array
    {
        return ['model_family' => $family, 'wape' => $wape, 'bias' => $bias, 'coverage_80' => $coverage];
    }

    public function test_explains_improvement_over_baseline(): void
    {
        $lines = (new AccuracyExplainer)->explain([
            'backtest' => [
                'selected' => $this->metrics(0.20, 0.08, 0.6),
                'baseline' => $this->metrics(0.30, null, null, 'seasonal_naive'),
                'n_windows' => 3,
                'by_horizon' => [['horizon_days' => 7, 'wape' => 0.15], ['horizon_days' => 30, 'wape' => 0.22]],
            ],
            'data' => ['has_inventory' => true, 'censored_days' => 0],
        ]);
        $text = implode("\n", $lines);

        $this->assertStringContainsString('3 cortes sucesivos', $text);
        $this->assertStringContainsString('WAPE de 20%', $text);
        $this->assertStringContainsString('30% del método de referencia «Estacional ingenuo', $text);
        $this->assertStringContainsString('10 puntos porcentuales menos', $text);
        $this->assertStringContainsString('unas 20 unidades', $text);
        $this->assertStringContainsString('sobreestimar la demanda en aproximadamente 8%', $text);
        $this->assertStringContainsString('60% de las veces; es menos de lo esperado', $text);
        $this->assertStringContainsString('15% a 7 días, 22% a 30 días', $text);
        $this->assertStringContainsString('no garantizan la precisión futura', end($lines));
    }

    public function test_explains_when_baseline_wins_and_data_limitations(): void
    {
        $lines = (new AccuracyExplainer)->explain([
            'backtest' => ['selected' => $this->metrics(0.30, 0.01, null, 'seasonal_naive'), 'baseline' => $this->metrics(0.30, null, null, 'seasonal_naive')],
            'realized' => ['wape' => 0.55, 'n_obs' => 120],
            'data' => ['has_inventory' => false],
        ]);
        $text = implode("\n", $lines);

        $this->assertStringContainsString('ningún modelo superó al método de referencia', $text);
        $this->assertStringContainsString('No se observa un sesgo relevante', $text);
        $this->assertStringContainsString('No hay datos de inventario', $text);
        $this->assertStringContainsString('error observado fue 55% (120 observaciones)', $text);
        $this->assertStringContainsString('conviene reentrenar', $text);
        foreach ($lines as $line) {
            $this->assertStringNotContainsString('R²', $line);
            $this->assertDoesNotMatchRegularExpression('/\d+% de (confianza|precisión garantizada)/u', $line);
        }
    }

    public function test_no_metrics_message(): void
    {
        $lines = (new AccuracyExplainer)->explain(['backtest' => [], 'data' => ['has_inventory' => true, 'censored_days' => 4, 'censored_series' => 2]]);

        $this->assertStringContainsString('Todavía no hay métricas', $lines[0]);
        $this->assertStringContainsString('4 días con posible quiebre de stock en 2 series', implode(' ', $lines));
    }

    public function test_node_selection_rules(): void
    {
        $this->assertSame('total', ForecastNode::fromFilters(null, null, null)->key);
        $this->assertSame('branch:3', ForecastNode::fromFilters(3, null, null)->key);
        $this->assertSame('category:7', ForecastNode::fromFilters(null, 7, null)->key);
        $this->assertSame('branch_category:3:7', ForecastNode::fromFilters(3, 7, null)->key);
        $this->assertSame('product:45', ForecastNode::fromFilters(null, 7, 45)->key);
        $node = ForecastNode::fromFilters(3, 7, 45);
        $this->assertSame('series:3:45', $node->key);
        $this->assertSame('series', $node->level);
        $this->assertTrue($node->isItemLevel());
        $this->assertFalse(ForecastNode::fromFilters(3, null, null)->isItemLevel());
    }

    public function test_trend_and_helpers(): void
    {
        $this->assertSame('up', ProductForecastQuery::trend(0.051, 10, 10.51));
        $this->assertSame('down', ProductForecastQuery::trend(-0.2, 10, 8));
        $this->assertSame('flat', ProductForecastQuery::trend(0.03, 10, 10.3));
        $this->assertSame('up', ProductForecastQuery::trend(null, 0, 5));
        $this->assertSame('flat', ProductForecastQuery::trend(null, 0, 0));
        $this->assertSame(0.25, Num::change(125.0, 100.0));
        $this->assertNull(Num::change(5.0, 0.0));
        $this->assertSame('Croston SBA (demanda intermitente)', ModelCatalog::label('croston_sba'));
        $this->assertSame('intermitente', ModelCatalog::demandClass('intermittent'));
    }
}
