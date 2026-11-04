<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\TenantRole;
use App\Models\ForecastRun;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\Feature\Analytics\Support\ModelQualityFixtures;
use Tests\Feature\Customer\Support\CustomerFixtures;
use Tests\TestCase;

/**
 * V2.5 · W3-D: GET /models/evaluations, /models/promotion-decisions y /models/shadow-evaluations. Solo lectura (`forecast.view`), aislados por
 * empresa, paginados, sin cifras donde el motor devolvió `insufficient_data` y con el denominador y la cobertura siempre visibles.
 */
class ModelQualityApiTest extends TestCase
{
    use AnalyticsFixtures;
    use CustomerFixtures;
    use ModelQualityFixtures;
    use RefreshDatabase;

    private const CUTOFF = '2026-09-30';

    /** @return array{tenant: Tenant, user: User, cat: array, run: ForecastRun} */
    private function company(TenantRole $role = TenantRole::Owner): array
    {
        [$tenant, $user] = $this->createTenantWithUser($role);
        $cat = $this->seedCatalog($tenant);
        $run = $this->seedForecastRun($tenant, $cat, self::CUTOFF, ['horizon' => 30, 'with_recommendations' => false]);

        return compact('tenant', 'user', 'cat', 'run');
    }

    // ───────────────────────── /models/evaluations ─────────────────────────

    public function test_segment_rows_show_the_denominator_coverage_and_the_segment_of_each_figure(): void
    {
        $c = $this->company(TenantRole::Viewer);
        $this->seedSegmentEvaluations($c['tenant']->id, $c['run']->id);

        $r = $this->actingAsTenantUser($c['user'], $c['tenant'])
            ->getJson("/api/v1/models/evaluations?run_id={$c['run']->id}&level=segment&dimension=demand_class&horizon=7&evaluation_type=backtest&is_selected=1")
            ->assertOk();

        $r->assertJsonCount(2, 'data');   // smooth e intermittent (modelo servido, horizonte 7, alcance principal)
        $smooth = collect($r->json('data'))->firstWhere('level_key', 'demand_class:smooth');
        $this->assertSame(['dimension' => 'demand_class', 'value' => 'smooth'], $smooth['segment']);
        $this->assertSame('ok', $smooth['status']);
        $this->assertTrue($smooth['figures_available']);
        $this->assertSame(0.18, $smooth['wape']);
        $this->assertEquals(1200.0, $smooth['quality']['denominator']['sum_actual']);
        $this->assertSame('unidades', $smooth['quality']['denominator']['unit']);
        $this->assertSame(840, $smooth['quality']['sample']['evaluable_days']);
        $this->assertSame(30, $smooth['quality']['sample']['excluded_days']);
        $this->assertSame(870, $smooth['quality']['sample']['total_days']);
        $this->assertEquals(['stockout_days' => 20, 'missing_source_days' => 6, 'closed_days' => 4, 'scale_undefined' => 0], $smooth['quality']['sample']['exclusions']);
        $this->assertSame(14, $smooth['quality']['sample']['n_series']);
        $this->assertSame(3, $smooth['n_windows']);
        $this->assertSame('better', $smooth['quality']['comparison']['verdict']);
        $this->assertSame(0.3, $smooth['quality']['comparison']['baseline_value']);
        $this->assertSame(0.18, $smooth['quality']['stability']['wape_cv'] ?? null);
        $this->assertSame(['selected'], $smooth['roles']);
        $this->assertEquals([80 => 6.0, 95 => 9.5], $smooth['quality']['interval_width']);
        // El mapa de anchuras sale como OBJETO JSON aunque sus claves sean numéricas.
        $this->assertStringContainsString('"interval_width":{"80":6,"95":9.5}', (string) $r->getContent());
        // Las filas de otra familia y de otro alcance no aparecen con estos filtros.
        $this->assertSame(['selection'], collect($r->json('data'))->pluck('model_family')->unique()->values()->all());
    }

    public function test_insufficient_data_rows_carry_sample_sizes_but_no_error_figures(): void
    {
        $c = $this->company();
        $this->seedSegmentEvaluations($c['tenant']->id, $c['run']->id);

        $row = $this->actingAsTenantUser($c['user'], $c['tenant'])
            ->getJson("/api/v1/models/evaluations?run_id={$c['run']->id}&level_key=series_age:new&is_selected=1")->assertOk()->json('data.0');

        $this->assertSame('insufficient_data', $row['status']);
        $this->assertFalse($row['figures_available']);
        foreach (['wape', 'mae', 'rmse', 'bias', 'coverage_80', 'coverage_95'] as $figure) {
            $this->assertNull($row[$figure], "{$figure} no se publica con insufficient_data");
        }
        foreach (['wape_raw', 'mase', 'rmsse', 'sum_abs_error', 'pinball', 'interval_width', 'alternative_metric'] as $figure) {
            $this->assertNull($row['quality'][$figure], "quality.{$figure} no se publica con insufficient_data");
        }
        $this->assertNull($row['quality']['comparison']['value']);
        $this->assertNull($row['quality']['comparison']['relative_improvement']);
        $this->assertSame('insufficient_data', $row['quality']['comparison']['reason']);
        // Sí se conservan los tamaños de muestra y el denominador: explican por qué no hay cifras.
        $this->assertEquals(8.0, $row['quality']['denominator']['sum_actual']);
        $this->assertSame(12, $row['quality']['sample']['evaluable_days']);
        $this->assertSame('few_series', $row['quality']['insufficient_reasons'][0]['code']);
    }

    public function test_even_if_the_engine_stored_figures_on_an_insufficient_row_the_api_does_not_publish_them(): void
    {
        $c = $this->company();
        $this->seedSegmentEvaluations($c['tenant']->id, $c['run']->id);
        DB::table('model_evaluations')->where('tenant_id', $c['tenant']->id)->where('level_key', 'series_age:new')->update(['wape' => 0.5, 'mae' => 2.0, 'coverage_80' => 0.7]);

        $row = $this->actingAsTenantUser($c['user'], $c['tenant'])
            ->getJson("/api/v1/models/evaluations?run_id={$c['run']->id}&level_key=series_age:new&is_selected=1")->assertOk()->json('data.0');

        $this->assertNull($row['wape']);
        $this->assertNull($row['mae']);
        $this->assertNull($row['coverage_80']);
    }

    public function test_an_unstable_wape_is_flagged_and_comes_with_its_explicit_alternative_metric(): void
    {
        $c = $this->company();
        $this->seedSegmentEvaluations($c['tenant']->id, $c['run']->id);

        $row = $this->actingAsTenantUser($c['user'], $c['tenant'])
            ->getJson("/api/v1/models/evaluations?run_id={$c['run']->id}&dimension=volume_tier&is_selected=1")->assertOk()->json('data.0');

        $this->assertNull($row['wape'], 'nunca un WAPE con denominador insuficiente');
        $this->assertTrue($row['quality']['wape_unstable']);
        $this->assertSame('unstable', $row['quality']['wape_status']);
        $this->assertSame('aggregate_absolute_error', $row['quality']['alternative_metric']['name']);
        $this->assertSame('wape_unstable', $row['quality']['alternative_metric']['used_because']);
        $this->assertEquals(12.0, $row['quality']['denominator']['sum_actual']);
        $this->assertEquals(30.0, $row['quality']['denominator']['min_required']);
        $this->assertTrue($row['figures_available'], 'es un segmento con datos: lo que falta es la estabilidad del WAPE, no la muestra');
    }

    public function test_filters_pagination_and_ordering(): void
    {
        $c = $this->company();
        $this->seedSegmentEvaluations($c['tenant']->id, $c['run']->id);
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);
        $total = DB::table('model_evaluations')->where('tenant_id', $c['tenant']->id)->where('level', 'segment')->count();

        $all = $api->getJson("/api/v1/models/evaluations?run_id={$c['run']->id}&level=segment&per_page=3")->assertOk();
        $all->assertJsonPath('meta.per_page', 3)->assertJsonPath('meta.total', $total)->assertJsonCount(3, 'data');
        $this->assertGreaterThan(1, $all->json('meta.last_page'));
        $page2 = $api->getJson("/api/v1/models/evaluations?run_id={$c['run']->id}&level=segment&per_page=3&page=2")->assertOk();
        $this->assertEmpty(array_intersect(array_column($all->json('data'), 'id'), array_column($page2->json('data'), 'id')), 'las páginas no se repiten');

        $this->assertSame(['selection_windows'], collect($api->getJson("/api/v1/models/evaluations?run_id={$c['run']->id}&level=segment&evaluation_type=selection_windows")->json('data'))->pluck('evaluation_type')->unique()->values()->all());
        $this->assertSame(['seasonal_naive'], collect($api->getJson("/api/v1/models/evaluations?run_id={$c['run']->id}&level=segment&model_family=seasonal_naive")->json('data'))->pluck('model_family')->unique()->values()->all());
        $baseline = $api->getJson("/api/v1/models/evaluations?run_id={$c['run']->id}&level=segment&is_baseline=1")->json('data');
        $this->assertNotEmpty($baseline);
        $this->assertSame([true], collect($baseline)->pluck('is_baseline')->unique()->values()->all());
        $this->assertSame([7, 30], collect($api->getJson("/api/v1/models/evaluations?run_id={$c['run']->id}&level_key=demand_class:smooth&is_selected=1&evaluation_type=backtest")->json('data'))->pluck('horizon_days')->all());
        $this->assertSame(['insufficient_data'], collect($api->getJson("/api/v1/models/evaluations?run_id={$c['run']->id}&status=insufficient_data&is_selected=1")->json('data'))->pluck('status')->unique()->values()->all());
        // Sin filtro de nivel el explorador también devuelve las filas clásicas (backtest overall, por horizonte…).
        $levels = collect($api->getJson("/api/v1/models/evaluations?run_id={$c['run']->id}&per_page=100")->json('data'))->pluck('level')->unique()->all();
        $this->assertContains('overall', $levels);
        $this->assertContains('segment', $levels);
    }

    public function test_validation_rejects_unknown_filters_with_422(): void
    {
        $c = $this->company();
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);
        $api->getJson('/api/v1/models/evaluations?level=inventado')->assertStatus(422)->assertJsonValidationErrors('level');
        $api->getJson('/api/v1/models/evaluations?dimension=inventada')->assertStatus(422)->assertJsonValidationErrors('dimension');
        $api->getJson('/api/v1/models/evaluations?core=supply')->assertStatus(422)->assertJsonValidationErrors('core');
        $api->getJson('/api/v1/models/evaluations?horizon=45')->assertStatus(422)->assertJsonValidationErrors('horizon');
        $api->getJson('/api/v1/models/evaluations?per_page=500')->assertStatus(422)->assertJsonValidationErrors('per_page');
        $api->getJson('/api/v1/models/promotion-decisions?policy=otra')->assertStatus(422)->assertJsonValidationErrors('policy');
        $api->getJson('/api/v1/models/shadow-evaluations?level=segment')->assertStatus(422)->assertJsonValidationErrors('level');
    }

    public function test_a_company_never_sees_the_quality_rows_of_another_company(): void
    {
        $a = $this->company();
        $b = $this->company();
        $this->seedSegmentEvaluations($a['tenant']->id, $a['run']->id);
        $this->seedSegmentEvaluations($b['tenant']->id, $b['run']->id);
        DB::table('model_evaluations')->where('tenant_id', $b['tenant']->id)->where('level', 'segment')->update(['level_key' => DB::raw("replace(level_key, ':', ':B-')")]);
        $this->seedPromotionDecisions($a['tenant']->id, $a['run']->id);
        $this->seedPromotionDecisions($b['tenant']->id, $b['run']->id);
        $this->seedShadow($a['tenant']->id, $a['run']->id, true);
        $this->seedShadow($b['tenant']->id, $b['run']->id, true, null, 'tsb');

        $apiA = $this->actingAsTenantUser($a['user'], $a['tenant']);
        $mine = $apiA->getJson('/api/v1/models/evaluations?level=segment&per_page=100')->assertOk();
        $this->assertSame(DB::table('model_evaluations')->where('tenant_id', $a['tenant']->id)->where('level', 'segment')->count(), $mine->json('meta.total'));
        $this->assertStringNotContainsString(':B-', (string) $mine->getContent());
        $ids = array_column($mine->json('data'), 'id');
        $this->assertSame([], array_values(array_intersect($ids, DB::table('model_evaluations')->where('tenant_id', $b['tenant']->id)->pluck('id')->all())));

        // Ejecuciones de la otra empresa: 404 (no se revela su existencia).
        $apiA->getJson("/api/v1/models/evaluations?run_id={$b['run']->id}")->assertNotFound();
        $apiA->getJson("/api/v1/models/promotion-decisions?run_id={$b['run']->id}")->assertNotFound();
        $apiA->getJson("/api/v1/models/shadow-evaluations?run_id={$b['run']->id}")->assertNotFound();
        $coreRunB = $this->seedTrainingRun($b['tenant']);
        $apiA->getJson("/api/v1/models/evaluations?core=customer&core_run_id={$coreRunB}")->assertNotFound();

        // Un usuario de otra empresa no puede leer esta empresa aunque envíe su encabezado.
        $this->actingAsTenantUser($b['user'], $a['tenant'])->getJson('/api/v1/models/evaluations')->assertNotFound();
        $this->actingAsTenantUser($b['user'], $a['tenant'])->getJson('/api/v1/models/promotion-decisions')->assertNotFound();
        $this->actingAsTenantUser($b['user'], $a['tenant'])->getJson('/api/v1/models/shadow-evaluations')->assertNotFound();
    }

    public function test_the_endpoints_are_read_only_and_require_a_session(): void
    {
        $c = $this->company(TenantRole::Viewer);
        foreach (['evaluations', 'promotion-decisions', 'shadow-evaluations'] as $path) {
            $this->getJson("/api/v1/models/{$path}")->assertUnauthorized();
        }
        foreach (['evaluations', 'promotion-decisions', 'shadow-evaluations'] as $path) {
            $this->actingAsTenantUser($c['user'], $c['tenant'])->postJson("/api/v1/models/{$path}", [])->assertStatus(405);
            $this->actingAsTenantUser($c['user'], $c['tenant'])->deleteJson("/api/v1/models/{$path}")->assertStatus(405);
            $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson("/api/v1/models/{$path}")->assertOk();   // el lector (forecast.view) puede leerlos
        }
    }

    /** @return array{tenant: Tenant, user: User, run: ForecastRun, train: int} empresa con horizontes 7 y 30 y SIN Customer en el plan, con filas de ambos cores */
    private function restrictedCompany(): array
    {
        $this->seed(PlanSeeder::class);
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner, ['plan_overrides' => ['horizons' => [7, 30], 'features' => ['customer' => false]]]);
        $cat = $this->seedCatalog($tenant);
        $run = $this->seedForecastRun($tenant, $cat, self::CUTOFF, ['horizon' => 30, 'with_recommendations' => false]);
        $this->seedSegmentEvaluations($tenant->id, $run->id);
        DB::table('model_evaluations')->insert(['tenant_id' => $tenant->id, 'core' => 'demand', 'forecast_run_id' => $run->id, 'model_family' => 'selection', 'evaluation_type' => 'backtest',
            'level' => 'segment', 'level_key' => 'demand_class:smooth', 'horizon_days' => 14, 'n_obs' => 1, 'n_windows' => 1, 'created_at' => now()]);
        // El plan ya no incluye Customer, pero quedan filas de cuando sí lo incluía.
        $train = $this->seedTrainingRun($tenant);
        $this->seedCustomerSegmentEvaluations($tenant->id, $train);

        return compact('tenant', 'user', 'run', 'train');
    }

    public function test_the_plan_limits_what_the_evaluations_explorer_reveals(): void
    {
        $c = $this->restrictedCompany();
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);

        // Customer no incluido en el plan: 402 explícito.
        $api->getJson('/api/v1/models/evaluations?core=customer')->assertStatus(402)->assertJsonPath('limit', 'features.customer');
        // Sin `core` pero con la ejecución de Customer indicada: ninguna de sus filas sale (no es el 402, es «no hay nada que mostrar para su plan»).
        $api->getJson("/api/v1/models/evaluations?core_run_id={$c['train']}&per_page=100")->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
        // Sin ninguna ejecución indicada: la vigente de Demand y nada de Customer.
        $default = $api->getJson('/api/v1/models/evaluations?per_page=100')->assertOk();
        $this->assertSame([], collect($default->json('data'))->where('core', 'customer')->values()->all());
        $this->assertNotEmpty($default->json('data'), 'las filas de Demand de la ejecución vigente sí salen');
        // Un horizonte fuera del plan: 402 si se pide, y no aparece si no se pide.
        $api->getJson('/api/v1/models/evaluations?horizon=90')->assertStatus(402)->assertJsonPath('limit', 'horizons');
        $this->assertNotContains(14, collect($default->json('data'))->pluck('horizon_days')->all());
        $this->assertNotContains(90, collect($default->json('data'))->pluck('horizon_days')->all());
        $this->assertContains(7, collect($default->json('data'))->pluck('horizon_days')->all());
    }

    public function test_the_plan_limits_what_the_promotion_decisions_reveal_for_customer(): void
    {
        $c = $this->restrictedCompany();
        DB::table('model_promotion_decisions')->insert([
            'tenant_id' => $c['tenant']->id, 'core' => 'customer', 'core_run_id' => $c['train'], 'scope_key' => 'global', 'policy' => 'v25', 'policy_version' => str_repeat('a', 64),
            'candidate_family' => 'customer_prob_lgbm_small', 'decision' => 'promote', 'is_effective' => false, 'gates' => '{}', 'metrics' => '{}', 'reason' => 'Decisión de Customer', 'decided_at' => now(), 'created_at' => now(),
        ]);
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);

        $api->getJson('/api/v1/models/promotion-decisions?core=customer')->assertStatus(402)->assertJsonPath('limit', 'features.customer');
        // Sin `core` pero con la ejecución de Customer indicada: el plan sin Customer no ve sus decisiones.
        $api->getJson("/api/v1/models/promotion-decisions?core_run_id={$c['train']}")->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
        $this->assertStringNotContainsString('Decisión de Customer', (string) $api->getJson('/api/v1/models/promotion-decisions')->getContent());
    }

    public function test_the_plan_limits_the_horizons_of_the_shadow_evaluations(): void
    {
        $c = $this->restrictedCompany();
        $this->seedShadow($c['tenant']->id, $c['run']->id, true);
        foreach ([14, 90] as $h) {   // filas de horizontes fuera del plan [7, 30]
            DB::table('model_evaluations')->insert(['tenant_id' => $c['tenant']->id, 'core' => 'demand', 'forecast_run_id' => $c['run']->id, 'model_family' => 'auto_ets', 'evaluation_type' => 'shadow_prospective',
                'level' => 'horizon', 'level_key' => "h{$h}", 'horizon_days' => $h, 'wape' => 0.3, 'n_obs' => 4, 'n_windows' => 1, 'created_at' => now(), 'metrics' => json_encode(['shadow' => true, 'sample' => ['sum_actual' => 100, 'n_obs' => 4, 'n_series' => 1]])]);
        }
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);

        $rows = $api->getJson("/api/v1/models/shadow-evaluations?run_id={$c['run']->id}")->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        $this->assertEqualsCanonicalizing([null, 7], array_values(array_unique(array_column($rows, 'horizon_days'), SORT_REGULAR)), 'solo horizontes del plan (y el global)');
        $api->getJson("/api/v1/models/shadow-evaluations?run_id={$c['run']->id}&horizon=90")->assertStatus(402)->assertJsonPath('limit', 'horizons');
    }

    public function test_without_a_run_the_explorer_lists_only_the_current_runs_not_the_whole_history(): void
    {
        $c = $this->company();
        $this->seedSegmentEvaluations($c['tenant']->id, $c['run']->id);
        $old = $this->seedForecastRun($c['tenant'], $c['cat'], '2026-08-31', ['horizon' => 30, 'with_recommendations' => false, 'is_current' => false]);
        $this->seedSegmentEvaluations($c['tenant']->id, $old->id);
        $oldTrain = $this->seedTrainingRun($c['tenant'], asOf: '2026-08-31');
        DB::table('core_runs')->where('id', $oldTrain)->update(['is_current' => false]);
        $this->seedCustomerSegmentEvaluations($c['tenant']->id, $oldTrain);
        $train = $this->seedTrainingRun($c['tenant']);
        $this->seedCustomerSegmentEvaluations($c['tenant']->id, $train);
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);

        $rows = $api->getJson('/api/v1/models/evaluations?level=segment&per_page=100')->assertOk()->json('data');

        $this->assertSame([$c['run']->id], array_values(array_unique(array_filter(array_column($rows, 'forecast_run_id')))), 'solo la ejecución de Demand vigente');
        $this->assertSame([$train], array_values(array_unique(array_filter(array_column($rows, 'core_run_id')))), 'solo el último entrenamiento de Customer');
        $this->assertSame(['customer', 'demand'], collect($rows)->pluck('core')->unique()->sort()->values()->all());
        // `core` acota y las ejecuciones anteriores siguen disponibles indicándolas.
        $this->assertSame(['demand'], collect($api->getJson('/api/v1/models/evaluations?core=demand&per_page=100')->json('data'))->pluck('core')->unique()->values()->all());
        $this->assertSame([$old->id], array_values(array_unique(array_column($api->getJson("/api/v1/models/evaluations?run_id={$old->id}&level=segment&per_page=100")->json('data'), 'forecast_run_id'))));
        $this->assertSame([$oldTrain], array_values(array_unique(array_column($api->getJson("/api/v1/models/evaluations?core_run_id={$oldTrain}&level=segment&per_page=100")->json('data'), 'core_run_id'))));
    }

    // ───────────────────────── /models/promotion-decisions ─────────────────────────

    public function test_promotion_decisions_list_both_policies_per_scope_with_gates_and_who_governed(): void
    {
        $c = $this->company(TenantRole::Viewer);
        DB::table('forecast_runs')->where('id', $c['run']->id)->update(['effective_config' => json_encode(['promotion' => ['governing_policy' => 'legacy', 'policy_versions' => ['legacy' => 'a', 'v25' => 'b']]])]);
        $registry = (int) DB::table('model_registry')->where('tenant_id', $c['tenant']->id)->value('id');
        $ids = $this->seedPromotionDecisions($c['tenant']->id, $c['run']->id, $registry);

        $r = $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson('/api/v1/models/promotion-decisions')->assertOk();   // sin run_id: la ejecución vigente

        $r->assertJsonPath('run.id', $c['run']->id)->assertJsonPath('summary.governing_policy', 'legacy')->assertJsonPath('summary.governing_source', 'run_config');
        $rows = collect($r->json('data'));
        $this->assertCount(6, $rows);
        $this->assertSame(['global', 'global', 'class:smooth', 'class:smooth', 'horizon:30', 'horizon:30'], $rows->pluck('scope_key')->all(), 'global, clases y horizontes; la heredada antes que la V2.5');
        $this->assertSame(['legacy', 'v25', 'legacy', 'v25', 'legacy', 'v25'], $rows->pluck('policy')->all());
        $legacy = $rows->firstWhere('id', $ids['legacy:global']);
        $v25 = $rows->firstWhere('id', $ids['v25:global']);
        $this->assertTrue($legacy['is_effective']);
        $this->assertFalse($v25['is_effective'], 'la V2.5 se registra como sombra de decisión');
        $this->assertSame(['type' => 'global', 'value' => null], $legacy['scope']);
        $this->assertSame('lightgbm_global', $legacy['served_family']);
        $this->assertSame('seasonal_naive', $v25['served_family']);
        $this->assertSame(['temporal_stability'], $v25['failed_gates']);
        $this->assertSame(['pass' => 2, 'fail' => 1, 'not_evaluable' => 0, 'not_applicable' => 0, 'pending' => 1], $v25['gates_summary']);
        $this->assertSame('Pierde 2 ventanas consecutivas (máximo permitido 1).', $v25['gates']['temporal_stability']['message']);
        $this->assertSame([1, 2, 3], $v25['gates']['temporal_stability']['evidence']['windows']);
        $this->assertSame('pending', $v25['approval']['status']);
        $this->assertNull($v25['approval']['approved_by']);
        $this->assertSame($registry, $legacy['candidate_model_registry_id']);
        $this->assertSame(hash('sha256', 'legacy-v1'), $legacy['policy_version']);
        $horizon = $rows->firstWhere('scope_key', 'horizon:30');
        $this->assertTrue($horizon['informational']);
        // Resumen: en el alcance global la V2.5 habría servido otro modelo; en la clase smooth coinciden; el horizonte es informativo y no cuenta
        // (su contraparte V2.5 sirve OTRO modelo, y aun así no entra en la comparación).
        $r->assertJsonPath('summary.agreement.scopes_compared', 2)->assertJsonPath('summary.agreement.same_served', 1)
            ->assertJsonPath('summary.agreement.different.0.scope_key', 'global')->assertJsonPath('summary.agreement.different.0.v25_served_family', 'seasonal_naive')
            ->assertJsonPath('summary.policies.legacy.effective', 2)->assertJsonPath('summary.policies.v25.effective', 0);
        $this->assertSame(['global'], array_column($r->json('summary.agreement.different'), 'scope_key'));
        $this->assertTrue($rows->where('scope_key', 'horizon:30')->firstWhere('policy', 'v25')['informational']);
        $this->assertSame('seasonal_naive', $rows->where('scope_key', 'horizon:30')->firstWhere('policy', 'v25')['served_family']);
    }

    public function test_promotion_decisions_filter_by_policy_scope_decision_and_effectiveness(): void
    {
        $c = $this->company();
        $this->seedPromotionDecisions($c['tenant']->id, $c['run']->id);
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);

        $this->assertSame(['v25'], collect($api->getJson('/api/v1/models/promotion-decisions?policy=v25')->json('data'))->pluck('policy')->unique()->values()->all());
        $this->assertCount(2, $api->getJson('/api/v1/models/promotion-decisions?scope=global')->json('data'));
        $this->assertCount(2, $api->getJson('/api/v1/models/promotion-decisions?decision=shadow')->json('data'));
        $this->assertCount(2, $api->getJson('/api/v1/models/promotion-decisions?is_effective=1')->json('data'));
        $this->assertCount(4, $api->getJson('/api/v1/models/promotion-decisions?is_effective=0')->json('data'));
        $page = $api->getJson('/api/v1/models/promotion-decisions?per_page=2')->assertOk();
        $page->assertJsonPath('meta.total', 6)->assertJsonPath('meta.per_page', 2)->assertJsonCount(2, 'data');
        $this->assertCount(0, $api->getJson('/api/v1/models/promotion-decisions?run_id='.$c['run']->id.'&scope=no-existe')->json('data'));
    }

    public function test_a_run_without_decisions_returns_an_empty_list_and_no_summary(): void
    {
        $c = $this->company();   // ejecución anterior a W3: sin decisiones

        $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson('/api/v1/models/promotion-decisions')->assertOk()
            ->assertJsonPath('data', [])->assertJsonPath('summary', null)->assertJsonPath('run.id', $c['run']->id);
    }

    public function test_the_family_really_served_after_a_training_fallback_is_reported_next_to_the_planned_one(): void
    {
        $c = $this->company();
        $ids = $this->seedPromotionDecisions($c['tenant']->id, $c['run']->id);
        DB::table('model_promotion_decisions')->where('id', $ids['legacy:global'])->update(['metrics' => json_encode([
            'served_family' => 'seasonal_naive', 'planned_family' => 'lightgbm_global', 'fallback_applied' => true, 'fallback_family' => 'seasonal_naive'])]);

        $row = collect($this->actingAsTenantUser($c['user'], $c['tenant'])->getJson('/api/v1/models/promotion-decisions?scope=global&policy=legacy')->json('data'))->first();

        $this->assertSame('seasonal_naive', $row['served_family']);
        $this->assertSame('lightgbm_global', $row['planned_family']);
        $this->assertTrue($row['fallback_applied']);
    }

    public function test_the_approval_made_by_w4_shows_up_on_the_decision(): void
    {
        $c = $this->company();
        $ids = $this->seedPromotionDecisions($c['tenant']->id, $c['run']->id);
        DB::table('model_promotion_decisions')->where('id', $ids['v25:class:smooth'])->update(['approved_by' => $c['user']->id, 'approved_at' => '2026-10-02 10:00:00+00']);

        $row = collect($this->actingAsTenantUser($c['user'], $c['tenant'])->getJson('/api/v1/models/promotion-decisions?scope=class:smooth&policy=v25')->json('data'))->first();

        $this->assertSame('approved', $row['approval']['status']);
        $this->assertSame($c['user']->id, $row['approval']['approved_by']);
    }

    // ───────────────────────── /models/shadow-evaluations ─────────────────────────

    public function test_shadow_evaluations_compare_the_unserved_challenger_with_the_served_model_on_the_same_days(): void
    {
        $c = $this->company(TenantRole::Viewer);
        $this->seedShadow($c['tenant']->id, $c['run']->id, true);

        $r = $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson("/api/v1/models/shadow-evaluations?run_id={$c['run']->id}")->assertOk();

        $r->assertJsonCount(2, 'data')->assertJsonPath('run.id', $c['run']->id);
        $overall = collect($r->json('data'))->firstWhere('level', 'overall');
        $this->assertSame('shadow_prospective', $overall['evaluation_type']);
        $this->assertSame('auto_ets', $overall['model_family']);
        $this->assertSame(0.225, $overall['wape']);
        $this->assertFalse($overall['shadow']['served']);
        $this->assertSame(0.25, $overall['shadow']['served_wape_same_series_days']);
        $this->assertSame(0.1, $overall['shadow']['relative_improvement_vs_served']);
        $this->assertSame(5, $overall['shadow']['n_served_compared']);
        $this->assertEquals(['series_days' => 6, 'evaluated_series_days' => 5, 'stockout_days' => 1, 'missing_source_days' => 0, 'closed_days' => 0], $overall['shadow']['exclusions']);
        $shadow = $r->json('shadow_forecasts.0');
        $this->assertSame('evaluated', $shadow['status']);
        $this->assertSame(6, $shadow['n_values']);
        $this->assertSame(2, $shadow['n_nodes']);
        $this->assertFalse($shadow['served']);
        $this->assertSame('2026-10-02', $shadow['first_target_date']);
        $this->assertSame('2026-10-04', $shadow['last_target_date']);
        // Nunca se devuelven los valores pronosticados del challenger.
        $this->assertStringNotContainsString('yhat', (string) $r->getContent());
        $this->assertSame(['horizon'], collect($this->actingAsTenantUser($c['user'], $c['tenant'])->getJson("/api/v1/models/shadow-evaluations?run_id={$c['run']->id}&level=horizon&horizon=7")->json('data'))->pluck('level')->unique()->values()->all());
    }

    public function test_a_challenger_without_realized_data_yet_is_reported_as_pending_not_as_zero(): void
    {
        $c = $this->company();
        $this->seedShadow($c['tenant']->id, $c['run']->id, false);

        $r = $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson('/api/v1/models/shadow-evaluations')->assertOk();   // sin run_id: la vigente

        $r->assertJsonPath('data', [])->assertJsonPath('shadow_forecasts.0.status', 'pending')->assertJsonPath('shadow_forecasts.0.n_obs_evaluated', null)
            ->assertJsonPath('shadow_forecasts.0.model_family', 'auto_ets');
    }

    public function test_shadow_evaluations_do_not_appear_in_the_classic_accuracy_endpoints(): void
    {
        $c = $this->company();
        $this->seedShadow($c['tenant']->id, $c['run']->id, true);
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);

        $this->assertNotContains('shadow_prospective', collect($api->getJson("/api/v1/model-evaluations?run_id={$c['run']->id}&per_page=100")->json('data'))->pluck('evaluation_type')->all());
        // … pero sí cuando se piden explícitamente.
        $this->assertSame(['shadow_prospective'], collect($api->getJson("/api/v1/model-evaluations?run_id={$c['run']->id}&evaluation_type=shadow_prospective")->json('data'))->pluck('evaluation_type')->unique()->values()->all());
    }

    // ───────────────────────── filtros booleanos (axios envía true/false) ─────────────────────────

    public function test_boolean_filters_accept_true_false_1_and_0_and_still_reject_garbage(): void
    {
        $c = $this->company();
        $this->seedSegmentEvaluations($c['tenant']->id, $c['run']->id);
        $this->seedPromotionDecisions($c['tenant']->id, $c['run']->id);
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);
        $selected = fn (string $v) => $api->getJson("/api/v1/models/evaluations?run_id={$c['run']->id}&level=segment&is_selected={$v}&per_page=100");

        foreach (['true', '1', 'TRUE', 'on', 'yes'] as $v) {
            $rows = $selected($v)->assertOk()->json('data');
            $this->assertNotEmpty($rows, "is_selected={$v}");
            $this->assertSame([true], array_values(array_unique(array_column($rows, 'is_selected'))), "is_selected={$v} filtra los seleccionados");
        }
        foreach (['false', '0', 'FALSE', 'off', 'no'] as $v) {
            $rows = $selected($v)->assertOk()->json('data');
            $this->assertNotEmpty($rows, "is_selected={$v}");
            $this->assertSame([false], array_values(array_unique(array_column($rows, 'is_selected'))), "is_selected={$v} filtra los no seleccionados");
        }
        $this->assertSame([true], array_values(array_unique(array_column($api->getJson("/api/v1/models/evaluations?run_id={$c['run']->id}&level=segment&is_baseline=true&per_page=100")->assertOk()->json('data'), 'is_baseline'))));
        $this->assertSame([false], array_values(array_unique(array_column($api->getJson("/api/v1/models/evaluations?run_id={$c['run']->id}&level=segment&is_baseline=false&per_page=100")->assertOk()->json('data'), 'is_baseline'))));
        // Lo mismo en las decisiones: `is_effective=true|false`.
        $this->assertSame([true], array_values(array_unique(array_column($api->getJson('/api/v1/models/promotion-decisions?is_effective=true')->assertOk()->json('data'), 'is_effective'))));
        $this->assertSame([false], array_values(array_unique(array_column($api->getJson('/api/v1/models/promotion-decisions?is_effective=false')->assertOk()->json('data'), 'is_effective'))));
        // Un valor que no es booleano sigue siendo un 422 (no se convierte en «sin filtro»).
        foreach (['maybe', 'si', '2'] as $v) {
            $selected($v)->assertStatus(422)->assertJsonValidationErrors('is_selected');
        }
        $api->getJson('/api/v1/models/promotion-decisions?is_effective=quizá')->assertStatus(422)->assertJsonValidationErrors('is_effective');
        $api->getJson("/api/v1/models/evaluations?run_id={$c['run']->id}&is_baseline[]=1")->assertStatus(422)->assertJsonValidationErrors('is_baseline');
    }

    // ───────────────────────── decisiones: horizontes del plan ─────────────────────────

    public function test_promotion_decisions_do_not_reveal_scopes_of_horizons_outside_the_plan(): void
    {
        $this->seed(PlanSeeder::class);
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner, ['plan_overrides' => ['horizons' => [7, 30]]]);
        $cat = $this->seedCatalog($tenant);
        $run = $this->seedForecastRun($tenant, $cat, self::CUTOFF, ['horizon' => 30, 'with_recommendations' => false]);
        $this->seedPromotionDecisions($tenant->id, $run->id);
        $this->seedHorizonDecisions($tenant->id, $run->id, [7, 60, 90]);
        $api = $this->actingAsTenantUser($user, $tenant);

        $all = $api->getJson('/api/v1/models/promotion-decisions?per_page=100')->assertOk();
        $scopes = array_values(array_unique(array_column($all->json('data'), 'scope_key')));
        $this->assertEqualsCanonicalizing(['global', 'class:smooth', 'horizon:30', 'horizon:7'], $scopes, 'solo los horizontes del plan');
        $this->assertStringNotContainsString('horizon:60', (string) $all->getContent());
        $this->assertStringNotContainsString('horizon:90', (string) $all->getContent());
        // Ni siquiera en el resumen: los conteos son los de lo visible.
        $all->assertJsonPath('summary.policies.legacy.decisions', 4)->assertJsonPath('summary.policies.v25.decisions', 4);
        // Pedir directamente el alcance de un horizonte fuera del plan es un 402; uno del plan, no.
        $api->getJson('/api/v1/models/promotion-decisions?scope=horizon:90')->assertStatus(402)->assertJsonPath('limit', 'horizons');
        $api->getJson('/api/v1/models/promotion-decisions?scope=horizon:60&policy=v25')->assertStatus(402);
        $api->getJson('/api/v1/models/promotion-decisions?scope=horizon:7')->assertOk()->assertJsonCount(2, 'data');
        $api->getJson('/api/v1/models/promotion-decisions?scope=class:smooth')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_without_a_current_run_the_decisions_are_empty_not_the_whole_history(): void
    {
        $c = $this->company();
        $this->seedPromotionDecisions($c['tenant']->id, $c['run']->id);
        // La única ejecución es retroactiva: no es de producción, no hay ejecución vigente.
        DB::table('forecast_runs')->where('id', $c['run']->id)->update(['is_current' => false, 'run_type' => 'historical']);
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);

        $api->getJson('/api/v1/models/promotion-decisions')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)->assertJsonPath('run', null)->assertJsonPath('summary', null);
        $api->getJson('/api/v1/models/promotion-decisions?core=demand')->assertOk()->assertJsonPath('data', []);
        // Con la ejecución indicada sí se ven.
        $api->getJson("/api/v1/models/promotion-decisions?run_id={$c['run']->id}")->assertOk()->assertJsonPath('run.id', $c['run']->id);
        $this->assertNotEmpty($api->getJson("/api/v1/models/promotion-decisions?run_id={$c['run']->id}")->json('data'));
        // Y el explorador y el shadow tampoco recorren el historial.
        $api->getJson('/api/v1/models/evaluations?level=segment')->assertOk()->assertJsonPath('data', []);
        $api->getJson('/api/v1/models/shadow-evaluations')->assertOk()->assertJsonPath('data', [])->assertJsonPath('run', null)->assertJsonPath('shadow_forecasts', []);
    }

    // ───────────────────────── shadow: estabilidad del WAPE y período evaluado ─────────────────────────

    /** Sustituye las filas `overall` del shadow por una con tan pocas ventas reales que su WAPE no es estable. */
    private function tinyShadow(array $c, array $metrics): array
    {
        $this->seedShadow($c['tenant']->id, $c['run']->id, true);
        DB::table('model_evaluations')->where('tenant_id', $c['tenant']->id)->where('evaluation_type', 'shadow_prospective')->where('level', 'overall')
            ->update(['wape' => 0, 'bias' => 0, 'n_obs' => 1, 'metrics' => json_encode($metrics)]);

        return $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson("/api/v1/models/shadow-evaluations?run_id={$c['run']->id}&level=overall")->assertOk()->json('data.0');
    }

    public function test_a_shadow_wape_over_too_few_real_sales_is_not_published_as_a_perfect_score(): void
    {
        $c = $this->company();
        $row = $this->tinyShadow($c, [
            'shadow' => true, 'sample' => ['n_obs' => 1, 'n_series' => 1, 'sum_actual' => 0.5], 'coverage_80_n' => 1, 'exclusions' => ['series_days' => 3, 'evaluated_series_days' => 1],
            'comparison' => ['served_wape_same_series_days' => 0.5, 'relative_improvement_vs_served' => 1.0, 'n_served_compared' => 1],
        ]);

        $this->assertSame('shadow_prospective', $row['evaluation_type']);
        $this->assertNull($row['wape'], 'un WAPE de 0 sobre 0,5 unidades no es un acierto perfecto');
        $this->assertNull($row['bias']);
        $this->assertTrue($row['shadow']['wape_unstable']);
        $this->assertSame('unstable', $row['shadow']['wape_status']);
        $this->assertNull($row['shadow']['served_wape_same_series_days']);
        $this->assertNull($row['shadow']['relative_improvement_vs_served'], 'ni «+100 %»');
        $this->assertEquals(['sum_actual' => 0.5, 'unit' => 'unidades', 'min_required' => 30], $row['shadow']['denominator']);
        $this->assertSame(1, $row['shadow']['evaluated']['n_series_days']);
        // La muestra siempre se informa: explica por qué no hay porcentaje.
        $this->assertSame(1, $row['n_obs']);
    }

    public function test_shadow_without_any_real_sales_has_an_undefined_wape(): void
    {
        $c = $this->company();
        $row = $this->tinyShadow($c, ['shadow' => true, 'sample' => ['n_obs' => 2, 'n_series' => 1, 'sum_actual' => 0.0], 'comparison' => ['served_wape_same_series_days' => null, 'relative_improvement_vs_served' => null]]);

        $this->assertNull($row['wape']);
        $this->assertSame('undefined', $row['shadow']['wape_status']);
        $this->assertTrue($row['shadow']['wape_unstable']);
    }

    public function test_the_runs_own_threshold_decides_the_shadow_stability(): void
    {
        $c = $this->company();
        $this->seedShadow($c['tenant']->id, $c['run']->id, true, sumActual: 280.0, legacyRows: true);   // filas anteriores a W3-D: sin denominador ni período
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);
        $url = "/api/v1/models/shadow-evaluations?run_id={$c['run']->id}&level=overall";

        $stable = $api->getJson($url)->assertOk()->json('data.0');
        $this->assertSame('ok', $stable['shadow']['wape_status']);
        $this->assertSame(0.225, $stable['wape']);
        // La ejecución trae su umbral en la configuración efectiva: 280 unidades ya no alcanzan.
        DB::table('forecast_runs')->where('id', $c['run']->id)->update(['effective_config' => json_encode(['config' => ['wape_min_denominator' => 500]])]);
        $strict = $api->getJson($url)->assertOk()->json('data.0');
        $this->assertTrue($strict['shadow']['wape_unstable']);
        $this->assertNull($strict['wape']);
        $this->assertEquals(500, $strict['shadow']['denominator']['min_required']);
    }

    public function test_the_shadow_reports_the_evaluated_period_and_tolerates_rows_written_before_it_existed(): void
    {
        $c = $this->company();
        $this->seedShadow($c['tenant']->id, $c['run']->id, true);
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);
        $new = $api->getJson("/api/v1/models/shadow-evaluations?run_id={$c['run']->id}&level=overall")->assertOk()->json('data.0.shadow');

        $this->assertEquals(['first_date' => '2026-10-02', 'last_date' => '2026-10-04', 'n_days' => 3, 'n_series_days' => 5, 'n_series' => 2], $new['evaluated']);
        $this->assertEquals(['sum_actual' => 280, 'unit' => 'unidades', 'min_required' => 30], $new['denominator']);
        $this->assertSame('ok', $new['wape_status']);

        // Filas antiguas (sin `evaluated_period` ni `denominator`): se calcula con `sample.sum_actual` y el período queda desconocido, nunca inventado.
        DB::table('model_evaluations')->where('tenant_id', $c['tenant']->id)->where('evaluation_type', 'shadow_prospective')->delete();
        $this->seedShadow($c['tenant']->id, $c['run']->id, true, null, 'tsb', 280.0, true);
        DB::table('shadow_forecast_values')->where('tenant_id', $c['tenant']->id)->where('model_family', 'auto_ets')->delete();
        $old = $api->getJson("/api/v1/models/shadow-evaluations?run_id={$c['run']->id}&level=overall")->assertOk()->json('data.0.shadow');
        $this->assertNull($old['evaluated']['first_date']);
        $this->assertNull($old['evaluated']['last_date']);
        $this->assertNull($old['evaluated']['n_days']);
        $this->assertSame(5, $old['evaluated']['n_series_days']);
        $this->assertSame('ok', $old['wape_status']);
        $this->assertEquals(280, $old['denominator']['sum_actual']);
    }

    // ───────────────────────── calibración por segmento: sin cifras con datos insuficientes ─────────────────────────

    public function test_an_insufficient_customer_segment_publishes_no_calibration_figure_anywhere_in_the_response(): void
    {
        $c = $this->company();
        $train = $this->seedTrainingRun($c['tenant']);
        $this->seedCustomerSegmentEvaluations($c['tenant']->id, $train);   // history:cold_start: insufficient_data con base_rate 0,05 y mean_predicted 0,07 en el jsonb
        $api = $this->actingAsTenantUser($c['user'], $c['tenant']);

        $r = $api->getJson("/api/v1/models/evaluations?core=customer&core_run_id={$train}&level_key=history:cold_start")->assertOk();
        $row = $r->json('data.0');

        $this->assertSame('insufficient_data', $row['status']);
        $this->assertFalse($row['figures_available']);
        foreach (['base_rate', 'mean_predicted', 'mean_ratio', 'brier', 'log_loss', 'ece', 'reference_brier', 'brier_delta_vs_reference'] as $figure) {
            $this->assertNull($row['calibration'][$figure], "calibration.{$figure} no se publica");
        }
        $this->assertSame(40, $row['calibration']['n'], 'sí se conserva el tamaño de la muestra');
        $this->assertSame(2, $row['calibration']['n_positive']);
        $this->assertNotNull($row['calibration']['reason']);
        // Ni en el jsonb crudo que se pasa tal cual.
        foreach (['base_rate', 'mean_predicted', 'mean_ratio', 'served', 'reference'] as $figure) {
            $this->assertNull($row['metrics'][$figure] ?? null, "metrics.{$figure} no se publica");
        }
        $this->assertSame(40, $row['metrics']['n']);
        $this->assertStringNotContainsString('0.07', (string) json_encode($row['metrics']));
        // Un segmento con datos suficientes, en cambio, sí las trae.
        $ok = $api->getJson("/api/v1/models/evaluations?core=customer&core_run_id={$train}&level_key=frequency:high")->assertOk()->json('data.0');
        $this->assertSame(0.1401, $ok['calibration']['mean_predicted']);
        $this->assertSame(0.1333, $ok['calibration']['base_rate']);
        $this->assertSame(0.1401, $ok['metrics']['mean_predicted']);
    }

    public function test_demand_figures_are_stripped_from_the_raw_metrics_of_an_insufficient_row_even_if_the_engine_stored_them(): void
    {
        $c = $this->company();
        $this->seedSegmentEvaluations($c['tenant']->id, $c['run']->id);
        $row = DB::table('model_evaluations')->where('tenant_id', $c['tenant']->id)->where('level_key', 'series_age:new')->where('is_selected', true)->first();
        $m = json_decode($row->metrics, true);
        $m += ['wape' => 0.5, 'mase' => 1.1, 'mean_error' => 2.0, 'sum_abs_error' => 9.0, 'horizon_total' => ['wape' => 0.2]];
        $m['stability']['wape_cv'] = 0.3;
        $m['stability']['per_window'] = [['wape' => 0.4]];
        $m['comparison']['selected_vs_baseline'] += ['value' => 0.4, 'baseline_value' => 0.3, 'relative_improvement' => -0.3];
        DB::table('model_evaluations')->where('id', $row->id)->update(['metrics' => json_encode($m)]);

        $metrics = $this->actingAsTenantUser($c['user'], $c['tenant'])->getJson("/api/v1/models/evaluations?run_id={$c['run']->id}&level_key=series_age:new&is_selected=true")->assertOk()->json('data.0.metrics');

        foreach (['wape', 'mase', 'mean_error', 'sum_abs_error', 'horizon_total'] as $figure) {
            $this->assertNull($metrics[$figure] ?? null, "metrics.{$figure}");
        }
        $this->assertNull($metrics['stability']['wape_cv']);
        $this->assertSame([], $metrics['stability']['per_window']);
        $this->assertNull($metrics['comparison']['selected_vs_baseline']['baseline_value']);
        $this->assertNull($metrics['comparison']['selected_vs_baseline']['relative_improvement']);
        $this->assertEquals(8, $metrics['denominator']['sum_actual'], 'el denominador se conserva');
        $this->assertSame(12, $metrics['sample']['evaluable_days']);
    }
}
