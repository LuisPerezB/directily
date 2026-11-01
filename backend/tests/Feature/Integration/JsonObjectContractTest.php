<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Enums\TenantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsJsonShapes;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\Feature\Conversation\Support\SeedsSupplyPlan;
use Tests\Feature\Customer\Support\CustomerFixtures;
use Tests\TestCase;

/**
 * Contrato de forma JSON de la etapa 2 (ADR-V2-03): los campos que son MAPAS salen siempre como objeto `{}` —también vacíos o con claves
 * numéricas (horizontes «7», «30», «90»; ids de producto)— y los que son listas (`assumptions`, `limitations`, `horizons`) siguen siendo `[]`.
 *
 * Defecto de origen: `JsonResource` renumera todo arreglo con claves numéricas y PHP serializa `[]` como lista. Se comprueba el JSON CRUDO
 * de la respuesta (decodificado como objetos), no `$response->json()`, que no distingue `{}` de `[]`.
 */
class JsonObjectContractTest extends TestCase
{
    use AnalyticsFixtures;
    use AssertsJsonShapes;
    use CustomerFixtures;
    use RefreshDatabase;
    use SeedsSupplyPlan;

    // ───────────────────────────── Comportamiento de compra ─────────────────────────────

    public function test_customer_run_maps_keep_their_horizon_keys_and_empty_maps_are_objects(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCustomerCatalog($tenant);
        $runId = $this->seedPredictionRun($tenant, $cat);
        $api = $this->actingAsTenantUser($user, $tenant);

        $show = $api->getJson("/api/v1/customer/runs/{$runId}")->assertOk();
        $this->assertJsonObjectAt($show, 'data.model_versions', [7, 30, 90]);
        $this->assertJsonObjectAt($show, 'data.envelope.model_version', [7, 30, 90]);
        $this->assertJsonObjectAt($show, 'data.envelope.target_definition', ['event']);
        $this->assertJsonObjectAt($show, 'data.summary', ['rows']);
        $this->assertSame('popularity', $this->rawJsonAt($show, 'data.model_versions.7.ranking_source'));
        // Las listas siguen siendo listas.
        foreach (['data.horizons', 'data.assumptions', 'data.limitations', 'data.envelope.assumptions', 'data.envelope.limitations'] as $list) {
            $this->assertJsonListAt($show, $list);
        }
        $this->assertSame([7, 30, 90], $this->rawJsonAt($show, 'data.horizons'));
        $show->assertJsonPath('data.model_versions.30.probability', 'v1');   // y $response->json() sigue leyéndolo

        $index = $api->getJson('/api/v1/customer/runs')->assertOk();
        $this->assertJsonObjectAt($index, 'data.0.model_versions', [7, 30, 90]);

        // Vacíos: `{}` y no `[]`.
        DB::table('core_runs')->where('id', $runId)->update(['model_versions' => '{}', 'summary' => '{}', 'target_definition' => '{}']);
        $empty = $api->getJson("/api/v1/customer/runs/{$runId}")->assertOk();
        $this->assertJsonObjectAt($empty, 'data.model_versions', []);
        $this->assertJsonObjectAt($empty, 'data.summary', []);
        $this->assertJsonObjectAt($empty, 'data.envelope.model_version', []);
        $this->assertJsonObjectAt($empty, 'data.envelope.target_definition', []);
        $this->assertStringContainsString('"model_versions":{}', (string) $empty->getContent());

        // Sin valor registrado, el mapa es null (ausente), no un objeto inventado.
        DB::table('core_runs')->where('id', $runId)->update(['model_versions' => null, 'summary' => null]);
        $nulls = $api->getJson("/api/v1/customer/runs/{$runId}")->assertOk();
        $this->assertNull($this->rawJsonAt($nulls, 'data.model_versions'));
        $this->assertNull($this->rawJsonAt($nulls, 'data.envelope.model_version'));
    }

    public function test_customer_predictions_evaluation_reconciliation_and_profile_serialize_their_maps_as_objects(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCustomerCatalog($tenant);
        $this->seedPredictionRun($tenant, $cat);
        $training = $this->seedTrainingRun($tenant);
        $api = $this->actingAsTenantUser($user, $tenant);

        $predictions = $api->getJson('/api/v1/customer/predictions?horizon=30')->assertOk();
        $this->assertJsonObjectAt($predictions, 'envelope.model_version', [7, 30, 90]);
        $this->assertJsonObjectAt($predictions, 'envelope.target_definition');
        $this->assertJsonObjectAt($predictions, 'definitions');

        $evaluation = $api->getJson('/api/v1/customer/evaluation')->assertOk();
        $this->assertJsonObjectAt($evaluation, 'data.models', [7]);
        $this->assertJsonObjectAt($evaluation, 'data.manifest.horizons', [7, 30, 90]);
        $this->assertJsonObjectAt($evaluation, 'data.metrics');
        $this->assertJsonObjectAt($evaluation, 'envelope.model_version', [7]);
        $this->assertJsonObjectAt($api->getJson('/api/v1/customer/evaluation?horizon=30')->assertOk(), 'data.manifest.horizons', [30]);

        $reconciliation = $api->getJson('/api/v1/customer/reconciliation?horizon=30')->assertOk();
        $this->assertJsonObjectAt($reconciliation, 'summary.status_counts', ['inconsistent', 'ok']);
        $none = $api->getJson('/api/v1/customer/reconciliation?horizon=30&status=aggregate_unavailable')->assertOk();
        $this->assertJsonObjectAt($none, 'summary.status_counts', []);
        $this->assertJsonListAt($none, 'data');

        $profile = $api->getJson("/api/v1/customer/customers/{$cat['customers']['c1']}/profile")->assertOk();
        $this->assertJsonObjectAt($profile, 'data.rankings', [7, 30, 90]);
        $this->assertJsonObjectAt($profile, 'data.ranking_sources', [7, 30, 90]);
        $this->assertJsonListAt($profile, 'data.rankings.30');
        $this->assertJsonObjectAt($api->getJson("/api/v1/customer/customers/{$cat['customers']['c1']}/profile?horizon=30")->assertOk(), 'data.rankings', [30]);

        // Sin evaluaciones: métricas y modelos vacíos son objetos.
        DB::table('model_evaluations')->where('core_run_id', $training)->delete();
        DB::table('core_runs')->where('id', $training)->update(['model_versions' => '{}']);
        $bare = $api->getJson('/api/v1/customer/evaluation')->assertOk();
        $this->assertJsonObjectAt($bare, 'data.metrics', []);
        $this->assertJsonObjectAt($bare, 'data.models', []);
    }

    public function test_customer_endpoints_without_any_run_return_empty_objects(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $api = $this->actingAsTenantUser($user, $tenant);

        $evaluation = $api->getJson('/api/v1/customer/evaluation')->assertOk();
        $this->assertJsonObjectAt($evaluation, 'data.metrics', []);
        $this->assertJsonObjectAt($evaluation, 'data.models', []);
        $this->assertJsonObjectAt($api->getJson('/api/v1/customer/reconciliation?horizon=30')->assertOk(), 'summary', []);
        $this->assertJsonListAt($api->getJson('/api/v1/customer/predictions?horizon=30')->assertOk(), 'data');
    }

    // ───────────────────────────── Abastecimiento ─────────────────────────────

    public function test_supply_request_options_keep_numeric_product_keys_and_empty_options_are_an_object(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);
        $api = $this->actingAsTenantUser($user, $tenant);

        // `priorities` es un mapa product_id → prioridad: todas sus claves son numéricas.
        $created = $api->postJson('/api/v1/supply/plans', ['priorities' => [10 => 2.0, 25 => 1.5], 'config' => ['holding_cost_rate_annual' => 0.25]])->assertStatus(202);
        $this->assertJsonObjectAt($created, 'data.options', ['priorities', 'config']);
        $this->assertJsonObjectAt($created, 'data.options.priorities', [10, 25]);
        $this->assertEquals(2, $this->rawJsonAt($created, 'data.options.priorities.10'));
        $created->assertJsonPath('data.options.priorities.25', 1.5);

        // Sin opciones: `options` es `{}` (antes salía `[]`).
        DB::table('core_runs')->update(['status' => 'failed']);   // libera la concurrencia para pedir otra corrida de costos
        $cost = $api->postJson('/api/v1/supply/cost-forecasts', [])->assertStatus(202);
        $this->assertJsonObjectAt($cost, 'data.options', []);
        $this->assertStringContainsString('"options":{}', (string) $cost->getContent());
        foreach (['data.assumptions', 'data.limitations'] as $list) {
            $this->assertJsonListAt($cost, $list);
        }

        $list = $api->getJson('/api/v1/supply/cost-forecasts/runs')->assertOk();
        $this->assertJsonObjectAt($list, 'data.0.options');
    }

    public function test_supply_plan_detail_serializes_every_map_as_an_object(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Viewer);
        $cat = $this->seedCatalog($tenant);
        $plan = $this->seedSupplyPlan($tenant, ['branches' => $cat['branches'], 'products' => $cat['products']]);
        DB::table('core_runs')->where('id', $plan['run'])->update(['model_versions' => json_encode(['84' => ['solver' => 'highs']]), 'summary' => '{}']);
        DB::table('supply_plans')->where('id', $plan['plan'])->update(['details' => json_encode([
            'constraints' => [['name' => 'budget']], 'solver' => [], 'diagnosis' => [], 'infeasibility' => null, 'alternative_plan' => null,
            'report' => ['comparison_coverage' => [], 'receipts_summary' => [], 'production' => []],
        ])]);
        $api = $this->actingAsTenantUser($user, $tenant);

        $r = $api->getJson("/api/v1/supply/plans/{$plan['run']}")->assertOk();
        $this->assertJsonObjectAt($r, 'envelope.model_version', [84]);
        $this->assertJsonObjectAt($r, 'envelope.target_definition', ['objective']);
        $this->assertJsonObjectAt($r, 'data.run.options', []);
        $this->assertJsonObjectAt($r, 'data.run.summary', []);
        $this->assertJsonObjectAt($r, 'data.lines.0.assumptions', []);
        foreach (['data.solver', 'data.diagnosis', 'data.comparison_coverage', 'data.receipts_summary', 'data.production'] as $map) {
            $this->assertJsonObjectAt($r, $map, []);
        }
        // null sigue siendo null (el bloque no aplica) y las listas siguen siendo listas.
        $this->assertNull($this->rawJsonAt($r, 'data.infeasibility'));
        $this->assertNull($this->rawJsonAt($r, 'data.alternative_plan'));
        foreach (['data.lines', 'data.lines.0.binding_constraints', 'data.shortfalls', 'data.constraints', 'data.offers', 'data.periods',
            'envelope.assumptions', 'envelope.limitations', 'data.run.assumptions', 'data.run.limitations'] as $list) {
            $this->assertJsonListAt($r, $list);
        }
        // `balance` es opt-in: null = no solicitado; con `?include=balance` es una lista (vacía si el motor no la devolvió).
        $this->assertNull($this->rawJsonAt($r, 'data.balance'));
        $this->assertJsonListAt($api->getJson("/api/v1/supply/plans/{$plan['run']}?include=balance")->assertOk(), 'data.balance');
    }

    public function test_supply_scenario_cost_forecast_and_simulation_results_serialize_their_maps_as_objects(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Viewer);
        $cat = $this->seedCatalog($tenant);
        $now = now();
        $scenarioRun = (int) DB::table('core_runs')->insertGetId([
            'tenant_id' => $tenant->id, 'core' => 'scenario', 'operation' => 'evaluate', 'version' => 1, 'run_type' => 'production', 'status' => 'completed',
            'result_type' => 'conditional_simulation', 'target_definition' => '{}', 'options' => '{}', 'assumptions' => '[]', 'limitations' => '[]', 'triggered_by' => 'user', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('scenario_evaluations')->insert(['tenant_id' => $tenant->id, 'core_run_id' => $scenarioRun, 'name' => 'Escenario', 'horizon_days' => 30, 'request' => '{}', 'results' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        $simulationRun = (int) DB::table('core_runs')->insertGetId([
            'tenant_id' => $tenant->id, 'core' => 'supply', 'operation' => 'simulate', 'version' => 1, 'run_type' => 'historical', 'status' => 'completed',
            'result_type' => 'evaluation', 'options' => '{}', 'summary' => '{}', 'assumptions' => '[]', 'limitations' => '[]', 'triggered_by' => 'user', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $costRun = (int) DB::table('core_runs')->insertGetId([
            'tenant_id' => $tenant->id, 'core' => 'cost', 'operation' => 'cost_forecast', 'version' => 1, 'run_type' => 'production', 'status' => 'completed',
            'result_type' => 'forecast', 'options' => '{}', 'assumptions' => '[]', 'limitations' => '[]', 'triggered_by' => 'user', 'is_current' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('cost_forecasts')->insert([
            'tenant_id' => $tenant->id, 'core_run_id' => $costRun, 'product_id' => $cat['products']['p1'], 'cost_source' => 'purchase_history', 'currency' => 'USD',
            'method' => 'last_value', 'quality_status' => 'insufficient_data', 'n_obs' => 1, 'metrics' => '{}', 'availability' => '{}',
        ]);
        $api = $this->actingAsTenantUser($user, $tenant);

        $scenario = $api->getJson("/api/v1/supply/scenarios/evaluations/{$scenarioRun}")->assertOk();
        $this->assertJsonObjectAt($scenario, 'data.request', []);
        $this->assertJsonObjectAt($scenario, 'data.results', []);
        $this->assertJsonObjectAt($scenario, 'envelope.target_definition', []);

        $simulation = $api->getJson("/api/v1/supply/simulations/{$simulationRun}")->assertOk();
        $this->assertJsonObjectAt($simulation, 'data.results', []);
        $this->assertJsonObjectAt($simulation, 'data.run.options', []);

        $cost = $api->getJson('/api/v1/supply/cost-forecasts')->assertOk();
        $this->assertJsonObjectAt($cost, 'data.0.metrics', []);
        $this->assertJsonObjectAt($cost, 'data.0.availability', []);
        $this->assertJsonListAt($cost, 'data.0.points');
        $this->assertJsonListAt($cost, 'data.0.limitations');
    }

    // ───────────────────────────── Demanda v2 ─────────────────────────────

    public function test_demand_run_maps_keep_horizon_keys_and_empty_maps_are_objects(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($tenant);
        $run = $this->seedForecastRun($tenant, $cat, '2026-09-30', ['horizon' => 30, 'with_recommendations' => false]);
        DB::table('forecast_runs')->where('id', $run->id)->update([
            'options' => '{}', 'effective_config' => '{}', 'evaluation_manifest' => '{}',
            'selection' => json_encode(['7' => ['model_family' => 'lightgbm_global'], '30' => ['model_family' => 'seasonal_naive']]),
            'summary' => json_encode(['horizon_support' => ['7' => ['status' => 'ok'], '30' => ['status' => 'degraded']], 'assumptions' => ['Ingresos = unidades × precio'], 'warnings' => []]),
        ]);
        $api = $this->actingAsTenantUser($user, $tenant);

        $show = $api->getJson("/api/v1/forecast-runs/{$run->id}")->assertOk();
        $this->assertJsonObjectAt($show, 'data.options', []);
        $this->assertJsonObjectAt($show, 'data.effective_config', []);
        $this->assertJsonObjectAt($show, 'data.evaluation_manifest', []);
        $this->assertJsonObjectAt($show, 'data.selection', [7, 30]);
        $this->assertJsonObjectAt($show, 'data.summary.horizon_support', [7, 30]);
        $this->assertJsonObjectAt($show, 'data.envelope.model_version', [7, 30]);
        $this->assertJsonObjectAt($show, 'data.envelope.target_definition');
        $this->assertJsonObjectAt($show, 'data.envelope.horizon_support', [7, 14, 30, 60, 90]);
        $this->assertJsonListAt($show, 'data.envelope.assumptions');
        $this->assertJsonListAt($show, 'data.envelope.limitations');
        $this->assertSame(['Ingresos = unidades × precio'], $this->rawJsonAt($show, 'data.envelope.assumptions'));
        $show->assertJsonPath('data.selection.30.model_family', 'seasonal_naive');

        $index = $api->getJson('/api/v1/forecast-runs')->assertOk();
        $this->assertJsonObjectAt($index, 'data.0.options', []);
        $this->assertJsonObjectAt($index, 'data.0.selection', [7, 30]);
        $this->assertJsonObjectAt($index, 'data.0.envelope.model_version', [7, 30]);

        // La envoltura de las series y de la precisión también.
        $series = $api->getJson('/api/v1/forecasts/series?horizon=30')->assertOk();
        $this->assertJsonObjectAt($series, 'data.envelope.model_version', [7, 30]);
        $this->assertJsonObjectAt($series, 'data.meta.models');
        $accuracy = $api->getJson('/api/v1/accuracy/summary')->assertOk();
        $this->assertJsonObjectAt($accuracy, 'data.horizon_support', [7, 14, 30, 60, 90]);
        $this->assertJsonObjectAt($accuracy, 'data.evaluation_manifest', []);
    }

    // ───────────────────────────── Core de Datos ─────────────────────────────

    public function test_data_core_maps_are_objects_even_when_empty(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $now = now();
        $source = (int) DB::table('data_sources')->insertGetId([
            'tenant_id' => $tenant->id, 'type' => 'file_upload', 'connector_key' => 'generic_csv', 'name' => 'Archivo', 'status' => 'active', 'config' => '{}',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $mapping = (int) DB::table('source_mappings')->insertGetId([
            'tenant_id' => $tenant->id, 'data_source_id' => $source, 'entity' => 'sales', 'query' => 'SELECT 1 AS sku', 'field_map' => json_encode(['product_sku' => 'sku']),
            'options' => '{}', 'version' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $sync = (int) DB::table('source_syncs')->insertGetId([
            'tenant_id' => $tenant->id, 'data_source_id' => $source, 'source_mapping_id' => $mapping, 'entity' => 'sales', 'mode' => 'full', 'status' => 'completed',
            'rows_extracted' => 0, 'stats' => '{}', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $api = $this->actingAsTenantUser($user, $tenant);

        $this->assertJsonObjectAt($api->getJson('/api/v1/data-sources')->assertOk(), 'data.0.config', []);
        $mappings = $api->getJson("/api/v1/data-sources/{$source}/mappings")->assertOk();
        $this->assertJsonObjectAt($mappings, 'data.0.options', []);
        $this->assertJsonObjectAt($mappings, 'data.0.field_map', ['product_sku']);
        $this->assertJsonObjectAt($api->getJson("/api/v1/data-sources/{$source}/mappings/{$mapping}")->assertOk(), 'data.options', []);
        $this->assertJsonObjectAt($api->getJson("/api/v1/syncs/{$sync}")->assertOk(), 'data.stats', []);
        $this->assertJsonObjectAt($api->getJson("/api/v1/data-sources/{$source}/syncs")->assertOk(), 'data.0.stats', []);
    }
}
