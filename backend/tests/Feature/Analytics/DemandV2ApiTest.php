<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\TenantRole;
use App\Models\ForecastRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\TestCase;

/**
 * Etapa 2 · P1 Demanda en la API: horizontes 7/14/30/60/90 y planes, envoltura común, manifiesto del protocolo,
 * métricas nuevas y respaldo de cada horizonte (E2-21), estados del registro de modelos y reversión (E2-24).
 */
class DemandV2ApiTest extends TestCase
{
    use AnalyticsFixtures;
    use RefreshDatabase;

    private const CUTOFF = '2026-09-30';

    /** @return array{0: Tenant, 1: User, 2: array<string, mixed>} */
    private function tenantWithCatalog(TenantRole $role = TenantRole::Owner): array
    {
        [$tenant, $user] = $this->createTenantWithUser($role);

        return [$tenant, $user, $this->seedCatalog($tenant)];
    }

    private function basicPlanId(): int
    {
        return (int) DB::table('plans')->insertGetId([
            'code' => 'basico', 'name' => 'Básico', 'limits' => json_encode(config('predictily.plans.basico.limits')),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ───────────────────────── horizontes y planes ─────────────────────────

    public function test_the_domain_and_plans_declare_the_five_horizons(): void
    {
        $this->assertSame([7, 14, 30, 60, 90], config('engine.horizons'));
        foreach (['trial', 'profesional', 'empresarial'] as $plan) {
            $this->assertSame([7, 14, 30, 60, 90], config("predictily.plans.{$plan}.limits.horizons"), $plan);
        }
        $this->assertSame([7, 14, 30], config('predictily.plans.basico.limits.horizons'));
    }

    public function test_requests_accept_14_and_60_and_reject_other_values(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser();
        $api = $this->actingAsTenantUser($user, $tenant);

        $api->postJson('/api/v1/forecast-runs', ['horizon_days' => 60])->assertStatus(202)
            ->assertJsonPath('data.horizon_days', 60)->assertJsonPath('data.options.horizons', [7, 14, 30, 60]);
        DB::table('forecast_runs')->where('tenant_id', $tenant->id)->update(['status' => 'completed']);
        $api->postJson('/api/v1/forecast-runs', ['horizon_days' => 14])->assertStatus(202)
            ->assertJsonPath('data.options.horizons', [7, 14]);
        DB::table('forecast_runs')->where('tenant_id', $tenant->id)->update(['status' => 'completed']);
        $api->postJson('/api/v1/forecast-runs', ['horizon_days' => 21])->assertStatus(422)->assertJsonValidationErrors('horizon_days');
        $api->getJson('/api/v1/forecasts/series?horizon=21')->assertStatus(422);
        $api->getJson('/api/v1/forecasts/products?horizon=14')->assertOk();
        $api->getJson('/api/v1/dashboard/summary?horizon=60')->assertOk()->assertJsonPath('data.horizon', 60);
    }

    public function test_basic_plan_allows_7_14_30_and_the_default_request_asks_for_30(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner, ['plan_id' => $this->basicPlanId()]);
        $api = $this->actingAsTenantUser($user, $tenant);

        $api->postJson('/api/v1/forecast-runs', ['horizon_days' => 14])->assertStatus(202)
            ->assertJsonPath('data.options.horizons', [7, 14]);
        DB::table('forecast_runs')->where('tenant_id', $tenant->id)->update(['status' => 'completed']);
        $api->postJson('/api/v1/forecast-runs', ['horizon_days' => 60])->assertStatus(402)->assertJsonPath('code', 'plan_limit_exceeded');
        $api->postJson('/api/v1/forecast-runs')->assertStatus(202)->assertJsonPath('data.horizon_days', 30)
            ->assertJsonPath('data.options.horizons', [7, 14, 30]);
    }

    public function test_tenant_default_horizon_can_be_14_or_60(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $api = $this->actingAsTenantUser($user, $tenant);
        $api->patchJson('/api/v1/tenant', ['settings' => ['default_horizon_days' => 60]])->assertOk();
        $api->patchJson('/api/v1/tenant', ['settings' => ['default_horizon_days' => 21]])->assertStatus(422);
    }

    // ───────────────────────── envoltura y respaldo por horizonte ─────────────────────────

    /** @return array{0: Tenant, 1: User, 2: ForecastRun} */
    private function runWithStage2Summary(): array
    {
        [$tenant, $user, $cat] = $this->tenantWithCatalog();
        $this->seedSales($tenant, $cat, self::CUTOFF, 30);
        $run = $this->seedForecastRun($tenant, $cat, self::CUTOFF, ['horizon' => 90]);
        $snapshotId = (int) DB::table('data_snapshots')->insertGetId([
            'tenant_id' => $tenant->id, 'core' => 'demand', 'as_of' => self::CUTOFF, 'hash' => str_repeat('d', 64),
            'components' => json_encode(['sales' => 'x']), 'row_counts' => '{}', 'coverage' => '{}',
        ]);
        $support = fn (string $status, array $reasons = []) => ['status' => $status, 'reasons' => $reasons, 'history_days' => 150,
            'required_history_days' => 60, 'backtest_horizon_days' => 30, 'independent_holdout' => true, 'evaluated_obs' => 6];
        DB::table('forecast_runs')->where('id', $run->id)->update([
            'config_version' => str_repeat('c', 64), 'data_snapshot_id' => $snapshotId,
            'effective_config' => json_encode(['core' => 'demand', 'run' => ['seed' => 42]]),
            'evaluation_manifest' => json_encode(['manifest_version' => 'demand-protocol-v1', 'holdout' => ['independent' => true]]),
            'summary' => json_encode([
                'series_count' => 6, 'quality_status' => 'degraded', 'uncertainty_source' => 'conformal',
                'assumptions' => ['Ingresos = unidades × último precio neto.'],
                'limitations' => [['code' => 'p50_not_published', 'message' => 'P50 no se publica: no se evaluó.']],
                'p50' => ['published' => false, 'reason' => 'no_improvement'],
                'horizon_support' => [
                    '7' => $support('ok'), '14' => $support('ok'), '30' => $support('ok'),
                    '60' => $support('insufficient_data', [['code' => 'backtest_horizon_lt_h', 'message' => 'El backtesting solo evalúa ventanas de 30 días.']]),
                    '90' => $support('insufficient_data', [['code' => 'history_lt_2h', 'message' => 'La historia es menor que 2 × 90 días.']]),
                ],
            ]),
        ]);

        return [$tenant, $user, $run->fresh()];
    }

    public function test_forecast_run_detail_carries_the_common_envelope_and_the_protocol_manifest(): void
    {
        [$tenant, $user, $run] = $this->runWithStage2Summary();
        $api = $this->actingAsTenantUser($user, $tenant);

        $r = $api->getJson("/api/v1/forecast-runs/{$run->id}")->assertOk();
        $env = $r->json('data.envelope');
        $this->assertSame($tenant->id, $env['tenant_id']);
        $this->assertSame($run->id, $env['run_id']);
        $this->assertSame('demand', $env['core']);
        $this->assertSame('forecast', $env['result_type']);
        $this->assertSame(self::CUTOFF, $env['as_of']);
        $this->assertSame('2026-10-01', $env['horizon_start']);
        $this->assertSame('2026-12-29', $env['horizon_end']);
        $this->assertSame(str_repeat('c', 64), $env['config_version']);
        $this->assertSame($run->data_snapshot_id, $env['data_snapshot_id']);
        $this->assertSame('degraded', $env['quality_status'], 'el estado técnico (status) es otro: la envoltura da la aptitud estadística');
        $this->assertSame('conformal', $env['uncertainty_source']);
        $this->assertSame(['P50 no se publica: no se evaluó.'], $env['limitations']);
        $this->assertSame(['p50_not_published'], $env['limitation_codes']);
        $this->assertSame(['branch_id', 'product_id'], $env['entity_keys']);
        $this->assertSame([7, 14, 30, 60, 90], array_keys($env['horizon_support']));
        $this->assertSame('insufficient_data', $env['horizon_support']['60']['status']);
        $this->assertSame('completed', $env['status']);
        $this->assertSame('product_unit', $env['unit']);
        // Aditivo: el contrato de la etapa 1 sigue ahí.
        $r->assertJsonPath('data.version', $run->version)->assertJsonPath('data.horizon_days', 90)
            ->assertJsonPath('data.is_current', true)->assertJsonPath('data.config_version', str_repeat('c', 64))
            ->assertJsonPath('data.evaluation_manifest.manifest_version', 'demand-protocol-v1')
            ->assertJsonPath('data.effective_config.run.seed', 42);
        // El manifiesto (voluminoso) no viaja en el listado.
        $list = $api->getJson('/api/v1/forecast-runs')->assertOk();
        $this->assertArrayNotHasKey('evaluation_manifest', $list->json('data.0'));
        $this->assertSame('demand', $list->json('data.0.envelope.core'));
    }

    public function test_series_response_carries_the_envelope_and_never_presents_an_unsupported_horizon_as_complete(): void
    {
        [$tenant, $user] = $this->runWithStage2Summary();
        $api = $this->actingAsTenantUser($user, $tenant);

        $ok = $api->getJson('/api/v1/forecasts/series?horizon=30')->assertOk();
        $ok->assertJsonPath('data.meta.horizon_support.status', 'ok')->assertJsonPath('data.envelope.quality_status', 'degraded')
            ->assertJsonPath('data.envelope.horizon_end', '2026-10-30')->assertJsonPath('data.envelope.entity_keys', []);
        $this->assertNotEmpty($ok->json('data.forecast'));
        $this->assertArrayHasKey('p50', $ok->json('data.forecast.0'));
        $this->assertNull($ok->json('data.forecast.0.p50'), 'P50 solo se publica si el motor la evaluó');

        $bad = $api->getJson('/api/v1/forecasts/series?horizon=60')->assertOk();
        $bad->assertJsonPath('data.meta.horizon_support.status', 'insufficient_data')
            ->assertJsonPath('data.meta.horizon_support.reasons.0.code', 'backtest_horizon_lt_h')
            ->assertJsonPath('data.envelope.quality_status', 'insufficient_data')
            ->assertJsonPath('data.envelope.horizon_support.status', 'insufficient_data');
        $api->getJson('/api/v1/forecasts/products?horizon=90')->assertOk()
            ->assertJsonPath('horizon_support.status', 'insufficient_data');
        $api->getJson('/api/v1/forecasts/categories?horizon=90')->assertOk()->assertJsonPath('horizon_support.status', 'insufficient_data');
        $api->getJson('/api/v1/dashboard/summary?horizon=60')->assertOk()->assertJsonPath('data.horizon_support.status', 'insufficient_data');
    }

    public function test_legacy_runs_and_horizons_beyond_the_run_are_reported_honestly(): void
    {
        [$tenant, $user, $cat] = $this->tenantWithCatalog();
        $this->seedSales($tenant, $cat, self::CUTOFF, 30);
        $this->seedForecastRun($tenant, $cat, self::CUTOFF, ['horizon' => 30]);
        $api = $this->actingAsTenantUser($user, $tenant);

        $api->getJson('/api/v1/forecasts/series?horizon=30')->assertOk()
            ->assertJsonPath('data.meta.horizon_support.status', 'unknown')
            ->assertJsonPath('data.meta.horizon_support.reasons.0.code', 'legacy_run');
        $api->getJson('/api/v1/forecasts/series?horizon=60')->assertOk()
            ->assertJsonPath('data.meta.horizon_support.status', 'not_computed')
            ->assertJsonPath('data.meta.horizon_support.reasons.0.code', 'beyond_run_horizon')
            ->assertJsonPath('data.envelope.quality_status', 'insufficient_data');
    }

    public function test_series_without_a_forecast_has_no_envelope(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/forecasts/series')->assertOk()
            ->assertJsonPath('data.envelope', null)->assertJsonPath('data.meta.horizon_support.status', 'not_computed');
    }

    public function test_special_case_labels_and_quality_travel_with_the_forecast_points(): void
    {
        [$tenant, $user] = $this->runWithStage2Summary();
        $productId = (int) DB::table('products')->where('tenant_id', $tenant->id)->orderBy('id')->value('id');
        $branchId = (int) DB::table('branches')->where('tenant_id', $tenant->id)->orderBy('id')->value('id');
        DB::table('forecast_values')->where('tenant_id', $tenant->id)->where('node_key', "series:{$branchId}:{$productId}")
            ->where('granularity', 'day')->where('horizon_step', '<=', 5)
            ->update(['case_label' => 'new_product_category_median', 'quality_status' => 'insufficient_data', 'p50' => 3.5]);
        $api = $this->actingAsTenantUser($user, $tenant);
        $r = $api->getJson("/api/v1/forecasts/series?product_id={$productId}&branch_id={$branchId}&horizon=7")->assertOk();
        $this->assertSame('new_product_category_median', $r->json('data.forecast.0.case_label'));
        $this->assertSame('insufficient_data', $r->json('data.forecast.0.quality_status'));
        $this->assertEquals(3.5, $r->json('data.forecast.0.p50'));
        $this->assertNull($r->json('data.forecast.6.case_label'));
        $list = $api->getJson('/api/v1/forecasts/products?horizon=7&branch_id='.$branchId)->assertOk();
        $labels = collect($list->json('data'))->pluck('case_label', 'product.id');
        $this->assertSame('new_product_category_median', $labels[$productId]);
    }

    // ───────────────────────── precisión: manifiesto y métricas nuevas ─────────────────────────

    public function test_accuracy_summary_exposes_the_manifest_new_metrics_and_horizon_totals(): void
    {
        [$tenant, $user, $cat] = $this->tenantWithCatalog();
        $this->seedSales($tenant, $cat, self::CUTOFF, 30);
        $run = $this->seedForecastRun($tenant, $cat, self::CUTOFF, ['horizon' => 30]);
        $metrics = ['metrics_version' => 'demand-metrics-v1', 'mase' => 0.84, 'rmsse' => 0.83, 'pinball_p10' => 0.7, 'pinball_p50' => null,
            'pinball_p90' => 1.3, 'interval_width_80' => 16.9, 'interval_width_95' => 27.0,
            'money' => ['currency' => 'USD', 'wape' => 0.5, 'mae' => 17.5, 'bias' => -0.02, 'actual_total' => 1000, 'forecast_total' => 980, 'n_obs' => 3000],
            'sample' => ['n_obs' => 3000, 'n_series' => 36, 'n_windows' => 1, 'exclusions' => ['scale_undefined' => 0, 'stockout_days' => 91]]];
        DB::table('model_evaluations')->where('forecast_run_id', $run->id)->where('level', 'overall')->where('is_selected', true)
            ->update(['metrics' => json_encode($metrics)]);
        foreach ([[7, 'selection', 0.2, true], [7, 'seasonal_naive', 0.26, false], [30, 'selection', 0.12, true], [30, 'seasonal_naive', 0.2, false]] as [$h, $family, $wape, $selected]) {
            DB::table('model_evaluations')->insert([
                'tenant_id' => $tenant->id, 'forecast_run_id' => $run->id, 'model_family' => $family, 'evaluation_type' => 'backtest',
                'level' => 'horizon_total', 'level_key' => "H{$h}", 'horizon_days' => $h, 'is_baseline' => ! $selected, 'is_selected' => $selected,
                'wape' => $wape, 'mae' => 4.0, 'rmse' => 5.0, 'bias' => 0.01, 'n_obs' => 36, 'n_windows' => 1, 'created_at' => now(),
                'metrics' => json_encode(['money' => ['currency' => 'USD', 'wape' => $wape + 0.05]]),
            ]);
        }
        DB::table('forecast_runs')->where('id', $run->id)->update([
            'evaluation_manifest' => json_encode(['manifest_version' => 'demand-protocol-v1', 'holdout' => ['present' => true, 'independent' => true]]),
            'summary' => json_encode(['horizon_support' => ['7' => ['status' => 'ok'], '30' => ['status' => 'ok']], 'p50' => ['published' => false]]),
        ]);

        $r = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/accuracy/summary')->assertOk();
        $r->assertJsonPath('data.evaluation_manifest.holdout.independent', true)
            ->assertJsonPath('data.horizon_support.7.status', 'ok')
            ->assertJsonPath('data.horizon_support.60.status', 'not_computed')
            ->assertJsonPath('data.p50.published', false)
            ->assertJsonPath('data.backtest.selected.mase', 0.84)
            ->assertJsonPath('data.backtest.selected.rmsse', 0.83)
            ->assertJsonPath('data.backtest.selected.pinball.p10', 0.7)
            ->assertJsonPath('data.backtest.selected.pinball.p50', null)
            ->assertJsonPath('data.backtest.selected.interval_width.95', 27)
            ->assertJsonPath('data.backtest.selected.money.currency', 'USD')
            ->assertJsonPath('data.backtest.selected.sample.exclusions.stockout_days', 91)
            ->assertJsonPath('data.backtest.selected.wape', 0.23)
            ->assertJsonPath('data.backtest.baseline.wape', 0.31);
        $totals = collect($r->json('data.backtest.by_horizon_total'))->keyBy('horizon_days');
        $this->assertSame([7, 30], $totals->keys()->all());
        $this->assertEquals(0.12, $totals[30]['wape']);
        $this->assertEquals(0.2, $totals[30]['baseline_wape']);
        $this->assertEqualsWithDelta(0.17, $totals[30]['money']['wape'], 1e-9);
        // Los tramos diarios de la etapa 1 siguen intactos.
        $this->assertCount(3, $r->json('data.backtest.by_horizon'));
        $this->actingAsTenantUser($user, $tenant)->getJson("/api/v1/model-evaluations?run_id={$run->id}&level=horizon_total")->assertOk()
            ->assertJsonPath('data.0.metrics.money.currency', 'USD');
    }

    // ───────────────────────── registro de modelos ─────────────────────────

    private function model(int $tenantId, ?int $runId, string $family, string $status, string $role = 'primary', string $core = 'demand', array $extra = []): int
    {
        return (int) DB::table('model_registry')->insertGetId(array_merge([
            'tenant_id' => $tenantId, 'version' => "t{$tenantId}-{$family}-".uniqid(), 'model_family' => $family, 'strategy_level' => 'A',
            'status' => $status, 'role' => $role, 'core' => $core, 'forecast_run_id' => $runId, 'series_count' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    public function test_models_listing_accepts_new_and_legacy_states_and_exposes_role_and_core(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        [$other] = $this->createTenantWithUser();
        $prod = $this->model($tenant->id, null, 'lightgbm_global', 'production');
        $fallback = $this->model($tenant->id, null, 'window_average', 'production', 'fallback');
        $retired = $this->model($tenant->id, null, 'auto_ets', 'retired');
        $validated = $this->model($tenant->id, null, 'tsb', 'validated');
        $failed = $this->model($tenant->id, null, 'adida', 'failed');
        $candidate = $this->model($tenant->id, null, 'naive', 'candidate');
        $customer = $this->model($tenant->id, null, 'logistic', 'production', 'primary', 'customer');
        $foreign = $this->model($other->id, null, 'lightgbm_global', 'production');
        $api = $this->actingAsTenantUser($user, $tenant);
        $ids = fn (string $query) => collect($api->getJson("/api/v1/models{$query}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$prod, $fallback, $retired, $validated, $failed, $candidate, $customer])->sort()->values()->all(), $ids(''));
        $this->assertNotContains($foreign, $ids(''), 'aislamiento entre empresas');
        $this->assertSame([$prod, $customer], $ids('?status=production&role=primary'));
        $this->assertSame([$fallback], $ids('?status=fallback'), 'legado: fallback = production + role fallback');
        $this->assertSame([$retired], $ids('?status=archived'));
        $this->assertSame([$validated], $ids('?status=rejected'));
        $this->assertSame([$failed], $ids('?status=failed'));
        $this->assertSame([$candidate], $ids('?status=candidate'));
        $this->assertSame([$customer], $ids('?core=customer'));
        $this->assertSame([$prod, $fallback, $retired, $validated, $failed, $candidate], $ids('?core=demand'));
        $api->getJson('/api/v1/models?status=inventado')->assertStatus(422)->assertJsonValidationErrors('status');
        $row = collect($api->getJson('/api/v1/models?status=fallback')->json('data'))->first();
        $this->assertSame('production', $row['status']);
        $this->assertSame('fallback', $row['role']);
        $this->assertSame('fallback', $row['legacy_status']);
        $this->assertSame('demand', $row['core']);
    }

    // ───────────────────────── reversión (E2-24) ─────────────────────────

    /**
     * Tres ejecuciones de producción: r1 y r2 retiradas, r3 vigente.
     *
     * @return array{0: Tenant, 1: User, 2: list<ForecastRun>, 3: list<int>}
     */
    private function threeRuns(TenantRole $role = TenantRole::Owner): array
    {
        [$tenant, $user, $cat] = $this->tenantWithCatalog($role);
        $runs = [];
        $models = [];
        foreach ([1, 2, 3] as $i) {
            $runs[$i] = $this->seedForecastRun($tenant, $cat, self::CUTOFF, [
                'version' => $i, 'is_current' => $i === 3, 'horizon' => 7, 'with_evaluations' => false, 'with_recommendations' => false,
            ]);
            DB::table('model_registry')->where('forecast_run_id', $runs[$i]->id)->update(['status' => $i === 3 ? 'production' : 'retired']);
            $models[$i] = (int) DB::table('model_registry')->where('forecast_run_id', $runs[$i]->id)->value('id');
        }

        return [$tenant, $user, array_values($runs), array_values($models)];
    }

    public function test_rollback_repoints_the_current_run_and_swaps_the_production_models(): void
    {
        [$tenant, $user, $runs, $models] = $this->threeRuns();
        $api = $this->actingAsTenantUser($user, $tenant);

        $r = $api->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[1]->id])->assertOk();
        $r->assertJsonPath('data.from_run_id', $runs[2]->id)->assertJsonPath('data.to_run_id', $runs[1]->id)
            ->assertJsonPath('data.to_version', 2)->assertJsonPath('data.restored_model_ids', [$models[1]])
            ->assertJsonPath('data.retired_model_ids', [$models[2]])->assertJsonPath('data.artifacts_verified', null);
        $this->assertSame([$runs[1]->id], DB::table('forecast_runs')->where('tenant_id', $tenant->id)->where('is_current', true)->pluck('id')->all());
        $this->assertSame('production', DB::table('model_registry')->where('id', $models[1])->value('status'));
        $this->assertSame('retired', DB::table('model_registry')->where('id', $models[2])->value('status'));
        $this->assertNotNull(DB::table('model_registry')->where('id', $models[2])->value('retired_at'));
        $this->assertNull(DB::table('model_registry')->where('id', $models[1])->value('retired_at'));
        $this->assertSame(1, DB::table('model_registry')->where('tenant_id', $tenant->id)->where('status', 'production')->count());
        // Los pronósticos de ambas ejecuciones se conservan; las vistas pasan a leer la restaurada.
        $this->assertGreaterThan(0, DB::table('forecast_values')->where('forecast_run_id', $runs[2]->id)->count());
        $api->getJson('/api/v1/dashboard/summary?horizon=7')->assertOk()->assertJsonPath('data.run.id', $runs[1]->id);
        $audit = DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('action', 'models.demand_rolled_back')->first();
        $this->assertNotNull($audit);
        $this->assertSame($user->id, (int) $audit->user_id);
        $meta = json_decode((string) $audit->metadata, true);
        $this->assertSame($runs[2]->id, $meta['from_run_id']);
        $this->assertSame($runs[1]->id, $meta['to_run_id']);

        // Se puede seguir retrocediendo; no avanzar con este mismo recurso.
        $api->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[0]->id])->assertOk()->assertJsonPath('data.to_version', 1);
        $api->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[2]->id])->assertStatus(409)
            ->assertJsonPath('code', 'rollback_target_not_previous');
    }

    public function test_rollback_refuses_invalid_targets_without_changing_anything(): void
    {
        [$tenant, $user, $runs, $models] = $this->threeRuns();
        $api = $this->actingAsTenantUser($user, $tenant);
        $snapshot = fn () => [
            DB::table('forecast_runs')->where('tenant_id', $tenant->id)->orderBy('id')->pluck('is_current', 'id')->all(),
            DB::table('model_registry')->where('tenant_id', $tenant->id)->orderBy('id')->pluck('status', 'id')->all(),
        ];
        $before = $snapshot();

        $api->postJson('/api/v1/models/demand/rollback', [])->assertStatus(422)->assertJsonValidationErrors('run_id');
        $api->postJson('/api/v1/models/demand/rollback', ['run_id' => 'x'])->assertStatus(422);
        $api->postJson('/api/v1/models/demand/rollback', ['run_id' => 999999])->assertStatus(404);
        $api->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[2]->id])->assertStatus(409)->assertJsonPath('code', 'rollback_already_current');

        DB::table('forecast_runs')->where('id', $runs[0]->id)->update(['run_type' => 'historical']);
        $api->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[0]->id])->assertStatus(409)->assertJsonPath('code', 'rollback_target_not_production');
        DB::table('forecast_runs')->where('id', $runs[0]->id)->update(['run_type' => 'production', 'status' => 'failed']);
        $api->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[0]->id])->assertStatus(409)->assertJsonPath('code', 'rollback_target_not_production');
        DB::table('forecast_runs')->where('id', $runs[0]->id)->update(['status' => 'completed']);
        DB::table('model_registry')->where('id', $models[0])->update(['status' => 'failed']);
        $api->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[0]->id])->assertStatus(409)->assertJsonPath('code', 'rollback_target_without_models');
        DB::table('model_registry')->where('id', $models[0])->update(['status' => 'retired']);
        DB::table('forecast_values')->where('forecast_run_id', $runs[0]->id)->delete();
        $api->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[0]->id])->assertStatus(409)->assertJsonPath('code', 'rollback_target_without_forecasts');

        DB::table('forecast_runs')->where('id', $runs[0]->id)->update(['status' => 'completed']);
        $this->assertSame($before[0], $snapshot()[0]);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'models.demand_rolled_back')->count());
    }

    public function test_rollback_without_a_current_run_explains_it(): void
    {
        [$tenant, $user, $runs] = $this->threeRuns();
        DB::table('forecast_runs')->where('tenant_id', $tenant->id)->update(['is_current' => false]);
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[0]->id])
            ->assertStatus(409)->assertJsonPath('code', 'no_current_run');
    }

    public function test_rollback_requires_the_forecast_run_ability(): void
    {
        [$tenant, $viewer, $runs] = $this->threeRuns(TenantRole::Viewer);
        $this->actingAsTenantUser($viewer, $tenant)->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[1]->id])
            ->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $analyst = $this->addUserToTenant($tenant, TenantRole::Analyst);
        $this->actingAsTenantUser($analyst, $tenant)->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[1]->id])->assertOk();
    }

    public function test_rollback_is_isolated_between_companies(): void
    {
        [$a, $userA, $runsA, $modelsA] = $this->threeRuns();
        [$b, $userB, $runsB, $modelsB] = $this->threeRuns();
        $beforeA = DB::table('model_registry')->where('tenant_id', $a->id)->orderBy('id')->pluck('status', 'id')->all();

        // B no puede apuntar a una ejecución de A: 404 (no revela su existencia) y nada cambia.
        $this->actingAsTenantUser($userB, $b)->postJson('/api/v1/models/demand/rollback', ['run_id' => $runsA[0]->id])->assertStatus(404);
        $this->assertSame($beforeA, DB::table('model_registry')->where('tenant_id', $a->id)->orderBy('id')->pluck('status', 'id')->all());
        // Revertir en B no toca a A.
        $this->actingAsTenantUser($userB, $b)->postJson('/api/v1/models/demand/rollback', ['run_id' => $runsB[1]->id])->assertOk();
        $this->assertSame($beforeA, DB::table('model_registry')->where('tenant_id', $a->id)->orderBy('id')->pluck('status', 'id')->all());
        $this->assertTrue((bool) DB::table('forecast_runs')->where('id', $runsA[2]->id)->value('is_current'));
        $this->assertSame(0, DB::table('audit_logs')->where('tenant_id', $a->id)->where('action', 'models.demand_rolled_back')->count());
        // Un usuario de A tampoco puede operar sobre B con su sesión de A.
        $this->actingAsTenantUser($userA, $a)->postJson('/api/v1/models/demand/rollback', ['run_id' => $runsB[1]->id])->assertStatus(404);
    }

    public function test_rollback_verifies_visible_artifacts_and_refuses_a_corrupted_one(): void
    {
        [$tenant, $user, $runs, $models] = $this->threeRuns();
        $file = tempnam(sys_get_temp_dir(), 'artifact');
        file_put_contents($file, 'modelo-serializado');
        DB::table('model_registry')->where('id', $models[1])->update(['artifact_path' => $file, 'artifact_sha256' => hash('sha256', 'otro-contenido')]);
        $api = $this->actingAsTenantUser($user, $tenant);

        $api->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[1]->id])->assertStatus(409)
            ->assertJsonPath('code', 'rollback_artifact_mismatch')->assertJsonPath('context.model_id', $models[1]);
        $this->assertTrue((bool) DB::table('forecast_runs')->where('id', $runs[2]->id)->value('is_current'), 'la transacción no dejó nada a medias');
        $this->assertSame('production', DB::table('model_registry')->where('id', $models[2])->value('status'));

        DB::table('model_registry')->where('id', $models[1])->update(['artifact_sha256' => hash('sha256', 'modelo-serializado')]);
        $api->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[1]->id])->assertOk()->assertJsonPath('data.artifacts_verified', true);
        @unlink($file);
    }

    /**
     * El motor guarda `artifact_path` RELATIVO a ENGINE_ARTIFACTS_DIR. El backend lo resuelve con `engine.artifacts_dir` (mismo
     * volumen): verifica el SHA-256 cuando ve el archivo y lo informa explícitamente cuando no (antes: `artifacts_verified = null`
     * sin explicación porque buscaba la ruta relativa contra su propio directorio de trabajo).
     */
    public function test_rollback_resolves_the_engines_relative_artifact_path_and_reports_when_it_cannot_verify(): void
    {
        $dir = sys_get_temp_dir().'/artifacts-'.bin2hex(random_bytes(5));
        mkdir($dir.'/7/run-3', 0777, true);
        file_put_contents($dir.'/7/run-3/lightgbm_global.joblib', 'modelo-serializado');
        $stored = '7/run-3/lightgbm_global.joblib';
        $good = hash('sha256', 'modelo-serializado');
        [$tenant, $user, $runs, $models] = $this->threeRuns();
        $api = $this->actingAsTenantUser($user, $tenant);
        $verification = fn ($r) => $r->json('data.artifacts_verification');

        // 1) Backend sin ENGINE_ARTIFACTS_DIR: no verifica y lo dice (con el motivo), la reversión sigue.
        config(['engine.artifacts_dir' => null]);
        DB::table('model_registry')->where('id', $models[1])->update(['artifact_path' => $stored, 'artifact_sha256' => $good]);
        $r = $api->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[1]->id])->assertOk()->assertJsonPath('data.artifacts_verified', null);
        $this->assertSame(['not_verifiable', 'artifacts_dir_not_configured', 1, 0], [$verification($r)['status'], $verification($r)['reason'], $verification($r)['registered'], $verification($r)['checked']]);
        $this->assertStringContainsString('ENGINE_ARTIFACTS_DIR', $verification($r)['message']);

        // 2) Configurado pero el archivo no está en ese volumen: tampoco verifica, motivo distinto.
        DB::table('model_registry')->where('id', $models[1])->update(['status' => 'retired']);
        DB::table('model_registry')->where('id', $models[2])->update(['status' => 'production']);
        DB::table('forecast_runs')->where('id', $runs[1]->id)->update(['is_current' => false]);
        DB::table('forecast_runs')->where('id', $runs[2]->id)->update(['is_current' => true]);
        config(['engine.artifacts_dir' => $dir.'/otro-volumen']);
        $r = $api->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[1]->id])->assertOk();
        $this->assertSame('artifacts_dir_not_accessible', $verification($r)['reason']);

        // 3) Mismo volumen: SHA-256 verificado contra la ruta relativa.
        DB::table('model_registry')->where('id', $models[1])->update(['status' => 'retired']);
        DB::table('model_registry')->where('id', $models[2])->update(['status' => 'production']);
        DB::table('forecast_runs')->where('id', $runs[1]->id)->update(['is_current' => false]);
        DB::table('forecast_runs')->where('id', $runs[2]->id)->update(['is_current' => true]);
        config(['engine.artifacts_dir' => $dir]);
        $r = $api->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[1]->id])->assertOk()->assertJsonPath('data.artifacts_verified', true);
        $this->assertSame(['verified', null, 1, 1], [$verification($r)['status'], $verification($r)['reason'], $verification($r)['registered'], $verification($r)['checked']]);

        // 4) Mismo volumen y el archivo cambió: se rechaza (la transacción no deja nada a medias).
        DB::table('model_registry')->where('id', $models[1])->update(['status' => 'retired', 'artifact_sha256' => hash('sha256', 'otro')]);
        DB::table('model_registry')->where('id', $models[2])->update(['status' => 'production']);
        DB::table('forecast_runs')->where('id', $runs[1]->id)->update(['is_current' => false]);
        DB::table('forecast_runs')->where('id', $runs[2]->id)->update(['is_current' => true]);
        $api->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[1]->id])->assertStatus(409)->assertJsonPath('code', 'rollback_artifact_mismatch');
        $this->assertTrue((bool) DB::table('forecast_runs')->where('id', $runs[2]->id)->value('is_current'));

        // 5) Una ruta que intenta salirse del directorio nunca se lee.
        file_put_contents(dirname($dir).'/fuera-de-artifacts.joblib', 'modelo-serializado');
        DB::table('model_registry')->where('id', $models[1])->update(['artifact_path' => '../fuera-de-artifacts.joblib', 'artifact_sha256' => $good]);
        $r = $api->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[1]->id])->assertOk()->assertJsonPath('data.artifacts_verified', null);
        $this->assertSame('not_verifiable', $verification($r)['status']);
        @unlink(dirname($dir).'/fuera-de-artifacts.joblib');
    }

    /** Filas anteriores a la regla: `storage/artifacts/<empresa>/…` (relativa al cwd del motor, con el directorio incluido) también se verifica. */
    public function test_rollback_still_verifies_artifacts_registered_with_the_previous_cwd_relative_path(): void
    {
        $dir = sys_get_temp_dir().'/engine-'.bin2hex(random_bytes(5)).'/storage/artifacts';
        mkdir($dir.'/7/run-3', 0777, true);
        file_put_contents($dir.'/7/run-3/lightgbm_global.joblib', 'modelo-serializado');
        config(['engine.artifacts_dir' => $dir]);
        [$tenant, $user, $runs, $models] = $this->threeRuns();
        DB::table('model_registry')->where('id', $models[1])->update(['artifact_path' => 'storage/artifacts/7/run-3/lightgbm_global.joblib', 'artifact_sha256' => hash('sha256', 'modelo-serializado')]);
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/models/demand/rollback', ['run_id' => $runs[1]->id])
            ->assertOk()->assertJsonPath('data.artifacts_verified', true)->assertJsonPath('data.artifacts_verification.status', 'verified');
    }
}
