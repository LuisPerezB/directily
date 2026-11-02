<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Models\ForecastRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\Feature\Analytics\Support\ModelQualityFixtures;
use Tests\Feature\Customer\Support\CustomerFixtures;
use Tests\TestCase;

/**
 * V2.5 · W3-D (decisión del arquitecto «a»): los consumidores de `model_evaluations` anteriores a W3 NO cambian su salida porque el motor escriba filas
 * nuevas (métricas por segmento de Demand y de Customer, evaluación prospectiva del shadow y las métricas de S05). Cada prueba toma la respuesta de un
 * consumidor, añade las filas nuevas —incluidas filas «trampa» con claves que podrían confundirse con las de siempre— y exige la MISMA respuesta byte a byte.
 *
 * Consumidores auditados: AccuracyService (GET /accuracy/summary), AccuracyController::evaluations (GET /model-evaluations), ExportController::accuracy
 * (GET /exports/accuracy.csv), ProductForecastQuery (GET /forecasts/products, WAPE por producto), CustomerEvaluationController (GET /customer/evaluation),
 * ModelEvaluationResource y ForecastRun::evaluations().
 */
class ModelEvaluationConsumersUnchangedTest extends TestCase
{
    use AnalyticsFixtures;
    use CustomerFixtures;
    use ModelQualityFixtures;
    use RefreshDatabase;

    private const CUTOFF = '2026-09-30';

    /** Filas de W3 que NO existían antes, incluidas «trampas» con claves y marcas que un filtro descuidado confundiría con las clásicas. */
    private function addW3Rows(int $tid, int $runId, array $cat): void
    {
        $this->seedSegmentEvaluations($tid, $runId);
        $this->seedShadow($tid, $runId, true);
        $now = now();
        $trap = fn (array $over) => array_merge([
            'tenant_id' => $tid, 'core' => 'demand', 'forecast_run_id' => $runId, 'model_family' => 'lightgbm_global', 'evaluation_type' => 'backtest', 'level' => 'overall',
            'level_key' => null, 'horizon_days' => null, 'is_baseline' => false, 'is_selected' => true, 'wape' => 0.01, 'mae' => 0.1, 'rmse' => 0.1, 'bias' => 0.0,
            'coverage_80' => 0.99, 'coverage_95' => 0.99, 'n_obs' => 99999, 'n_windows' => 9, 'created_at' => $now,
        ], $over);
        $rows = [
            // Evaluación prospectiva con la marca de «seleccionado» y los mismos niveles/claves que usan las vistas clásicas.
            $trap(['evaluation_type' => 'shadow_prospective', 'level' => 'overall']),
            $trap(['evaluation_type' => 'shadow_prospective', 'level' => 'horizon', 'level_key' => 'h1-7', 'horizon_days' => 7]),
            $trap(['evaluation_type' => 'shadow_prospective', 'level' => 'demand_class', 'level_key' => 'smooth']),
            $trap(['evaluation_type' => 'shadow_prospective', 'level' => 'branch', 'level_key' => 'b'.$cat['branches']['b1']]),
        ];
        foreach ($cat['products'] as $productId) {
            $rows[] = $trap(['evaluation_type' => 'shadow_prospective', 'level' => 'product', 'level_key' => "p{$productId}"]);
            $rows[] = $trap(['level' => 'segment', 'level_key' => "p{$productId}", 'horizon_days' => 7]);   // nivel segmento con la clave de un producto
        }
        DB::table('model_evaluations')->insert($rows);
    }

    /** @return array<string, string> respuesta cruda de cada consumidor de Demand */
    private function demandSnapshot(int $runId): array
    {
        $api = $this->withHeaders(['Accept' => 'application/json']);

        return [
            'summary' => $api->getJson("/api/v1/accuracy/summary?run_id={$runId}")->assertOk()->getContent(),
            'summary_current' => $api->getJson('/api/v1/accuracy/summary')->assertOk()->getContent(),
            'evaluations' => $api->getJson("/api/v1/model-evaluations?run_id={$runId}&per_page=100")->assertOk()->getContent(),
            'evaluations_overall' => $api->getJson("/api/v1/model-evaluations?run_id={$runId}&level=overall")->assertOk()->getContent(),
            'evaluations_backtest' => $api->getJson("/api/v1/model-evaluations?run_id={$runId}&evaluation_type=backtest&per_page=100")->assertOk()->getContent(),
            'csv' => $api->get("/api/v1/exports/accuracy.csv?run_id={$runId}")->streamedContent(),
            'csv_overall' => $api->get('/api/v1/exports/accuracy.csv?level=overall')->streamedContent(),
            'products' => $api->getJson('/api/v1/forecasts/products?horizon=7')->assertOk()->getContent(),
            'products_branch' => $api->getJson('/api/v1/forecasts/products?horizon=7&branch_id='.DB::table('branches')->orderBy('id')->value('id'))->assertOk()->getContent(),
        ];
    }

    public function test_demand_accuracy_consumers_return_exactly_the_same_with_segment_and_shadow_rows_present(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        $this->seedSales($tenant, $cat, self::CUTOFF, 60);
        $run = $this->seedForecastRun($tenant, $cat, self::CUTOFF, ['horizon' => 30, 'with_recommendations' => false]);
        $this->actingAsTenantUser($user, $tenant);
        $before = $this->demandSnapshot($run->id);
        $rowsBefore = DB::table('model_evaluations')->where('tenant_id', $tenant->id)->count();
        $evaluationsBefore = $this->withinTenant($tenant, fn () => ForecastRun::query()->find($run->id)->evaluations()->count());

        $this->addW3Rows($tenant->id, $run->id, $cat);

        $this->assertGreaterThan($rowsBefore + 10, DB::table('model_evaluations')->where('tenant_id', $tenant->id)->count(), 'la prueba debe sembrar filas nuevas');
        $after = $this->demandSnapshot($run->id);
        foreach ($before as $consumer => $content) {
            $this->assertSame($content, $after[$consumer], "El consumidor «{$consumer}» cambió su salida por las filas nuevas de W3");
        }
        $this->assertSame($evaluationsBefore, $this->withinTenant($tenant, fn () => ForecastRun::query()->find($run->id)->evaluations()->count()), 'ForecastRun::evaluations() conserva la vista clásica');
        $this->assertGreaterThan($evaluationsBefore, $this->withinTenant($tenant, fn () => ForecastRun::query()->find($run->id)->qualityEvaluations()->count()), 'qualityEvaluations() sí las incluye');
    }

    public function test_the_new_rows_do_appear_in_the_classic_endpoints_only_when_explicitly_asked_for(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        $run = $this->seedForecastRun($tenant, $cat, self::CUTOFF, ['horizon' => 30, 'with_recommendations' => false]);
        $this->addW3Rows($tenant->id, $run->id, $cat);
        $api = $this->actingAsTenantUser($user, $tenant);

        $segments = $api->getJson("/api/v1/model-evaluations?run_id={$run->id}&level=segment&per_page=100")->assertOk();
        $this->assertNotEmpty($segments->json('data'));
        $this->assertSame(['segment'], collect($segments->json('data'))->pluck('level')->unique()->values()->all());
        $shadow = $api->getJson("/api/v1/model-evaluations?run_id={$run->id}&evaluation_type=shadow_prospective&per_page=100")->assertOk();
        $this->assertNotEmpty($shadow->json('data'));
        $this->assertSame(['shadow_prospective'], collect($shadow->json('data'))->pluck('evaluation_type')->unique()->values()->all());
        $csv = $api->get("/api/v1/exports/accuracy.csv?run_id={$run->id}&level=segment")->streamedContent();
        $this->assertStringContainsString('demand_class:smooth', $csv);
        $this->assertStringNotContainsString('demand_class:smooth', $api->get("/api/v1/exports/accuracy.csv?run_id={$run->id}")->streamedContent());
    }

    public function test_the_customer_evaluation_keeps_its_previous_content_with_segment_and_s05_rows_present(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCustomerCatalog($tenant);
        $this->seedPredictionRun($tenant, $cat);
        $training = $this->seedTrainingRun($tenant);
        $api = $this->actingAsTenantUser($user, $tenant);
        $before = [
            'all' => $api->getJson('/api/v1/customer/evaluation')->assertOk()->getContent(),
            'h30' => $api->getJson('/api/v1/customer/evaluation?horizon=30')->assertOk()->getContent(),
            'run' => $api->getJson("/api/v1/customer/evaluation?run_id={$training}")->assertOk()->getContent(),
        ];

        $this->seedCustomerSegmentEvaluations($tenant->id, $training);
        // Trampa: una fila de segmento con la clave de una de las clásicas.
        DB::table('model_evaluations')->insert(['tenant_id' => $tenant->id, 'core' => 'customer', 'core_run_id' => $training, 'model_family' => 'customer_segment_calibration',
            'evaluation_type' => 'holdout', 'level' => 'segment', 'level_key' => 'h30:probability', 'horizon_days' => 30, 'n_obs' => 1, 'n_windows' => 0, 'created_at' => now()]);

        $this->assertSame($before['all'], $api->getJson('/api/v1/customer/evaluation')->assertOk()->getContent());
        $this->assertSame($before['h30'], $api->getJson('/api/v1/customer/evaluation?horizon=30')->assertOk()->getContent());
        $this->assertSame($before['run'], $api->getJson("/api/v1/customer/evaluation?run_id={$training}")->assertOk()->getContent());
        // Lo nuevo está en el explorador de calidad.
        $segments = $api->getJson("/api/v1/models/evaluations?core=customer&core_run_id={$training}&level=segment")->assertOk();
        $this->assertSame(['frequency:high', 'frequency:low', 'h30:probability', 'history:cold_start'], collect($segments->json('data'))->pluck('level_key')->sort()->values()->all());
        $s05 = $api->getJson("/api/v1/models/evaluations?core=customer&core_run_id={$training}&level_key=window")->assertOk();
        $this->assertSame('customer_window', $s05->json('data.0.model_family'));
    }

    public function test_the_previous_customer_prediction_fields_and_order_are_untouched_by_the_s05_columns(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCustomerCatalog($tenant);
        $run = $this->seedPredictionRun($tenant, $cat);
        $api = $this->actingAsTenantUser($user, $tenant);
        $before = $api->getJson('/api/v1/customer/predictions?horizon=30')->assertOk()->json('data');

        $this->seedCustomerS05($tenant->id, $run, $cat);
        DB::table('customer_predictions')->where('core_run_id', $run)->update(['quality_flags' => json_encode([])]);   // las banderas no son parte de este contraste
        $after = $api->getJson('/api/v1/customer/predictions?horizon=30')->assertOk()->json('data');

        $this->assertSame(count($before), count($after));
        $s05 = array_flip(['expected_orders', 'quantity_per_order', 'expected_units', 'window', 'segment']);
        foreach ($before as $i => $row) {
            $this->assertSame(array_diff_key($row, $s05), array_diff_key($after[$i], $s05), 'los campos anteriores no cambian; los de S05 se agregan');
        }
        $this->assertNull($before[0]['expected_orders'], 'sin estimación de S05: nulo, nunca un cero');
        $this->assertNotNull(collect($after)->firstWhere('customer.id', $cat['customers']['c1'])['expected_orders']);
        $this->assertSame(array_column($before, 'customer'), array_column($after, 'customer'), 'el orden por defecto no cambia');
    }
}
