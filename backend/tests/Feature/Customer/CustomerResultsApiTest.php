<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Enums\TenantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Customer\Support\CustomerFixtures;
use Tests\TestCase;

/** GET /customer/predictions, /customers/{id}/profile, /evaluation y /reconciliation con envoltura, plan e aislamiento. */
class CustomerResultsApiTest extends TestCase
{
    use CustomerFixtures;
    use RefreshDatabase;

    private function basicPlanId(): int
    {
        return (int) DB::table('plans')->insertGetId([
            'code' => 'basico', 'name' => 'Básico', 'limits' => json_encode(config('predictily.plans.basico.limits')), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── predicciones ──
    public function test_predictions_are_filtered_ordered_paginated_and_carry_the_envelope(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCustomerCatalog($tenant);
        $run = $this->seedPredictionRun($tenant, $cat);
        $api = $this->actingAsTenantUser($user, $tenant);

        $res = $api->getJson('/api/v1/customer/predictions?horizon=30')->assertOk();
        $this->assertCount(4, $res->json('data'));
        $probs = array_column($res->json('data'), 'purchase_probability');
        $sorted = $probs;
        rsort($sorted);
        $this->assertSame($sorted, $probs);                                          // por probabilidad descendente
        $this->assertSame(0.65, $probs[0]);                                           // c1 × p1 a 30 días
        $row = $res->json('data.0');
        $this->assertEqualsWithDelta($row['purchase_probability'] * $row['quantity_if_purchase'], $row['expected_quantity'], 1e-6);
        $this->assertStringContainsString('no es una venta confirmada', $row['expected_amount']['basis']);
        $this->assertSame('CLI-0001', $row['customer']['code']);
        $this->assertSame('CEM-01', $row['product']['sku']);
        $env = $res->json('envelope');
        $this->assertSame($run, $env['run_id']);
        $this->assertSame('customer', $env['core']);
        $this->assertSame('forecast', $env['result_type']);
        $this->assertSame('2026-10-01', $env['as_of']);
        $this->assertSame('2026-10-02', $env['horizon_start']);
        $this->assertSame('2026-10-31', $env['horizon_end']);
        $this->assertSame(['customer_id', 'product_id'], $env['entity_keys']);
        $this->assertSame('ok', $env['quality_status']);
        $this->assertSame('empirical_holdout', $env['uncertainty_source']);
        $this->assertSame(str_repeat('c', 64), $env['config_version']);
        $this->assertArrayHasKey('expected_amount', $res->json('definitions'));
        $this->assertSame('model', $res->json('ranking_source'));
        $api->getJson('/api/v1/customer/predictions?horizon=7')->assertOk()->assertJsonPath('ranking_source', 'popularity');

        $api->getJson('/api/v1/customer/predictions?horizon=30&order_by=probability&direction=asc')->assertOk()->assertJsonPath('data.0.purchase_probability', 0.15);
        $api->getJson('/api/v1/customer/predictions?horizon=30&customer_id='.$cat['customers']['c2'])->assertOk()->assertJsonCount(2, 'data');
        $api->getJson('/api/v1/customer/predictions?horizon=30&product_id='.$cat['products']['p2'].'&min_probability=0.3')->assertOk()->assertJsonCount(1, 'data');
        $page = $api->getJson('/api/v1/customer/predictions?horizon=7&per_page=3&page=2')->assertOk();
        $this->assertCount(1, $page->json('data'));
        $this->assertSame(4, $page->json('meta.total'));
    }

    public function test_predictions_respect_the_plan_horizons_and_the_run_horizons(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner, ['plan_id' => $this->basicPlanId()]);
        $cat = $this->seedCustomerCatalog($tenant);
        $this->seedPredictionRun($tenant, $cat, [7, 30]);
        $api = $this->actingAsTenantUser($user, $tenant);
        $api->getJson('/api/v1/customer/predictions?horizon=90')->assertStatus(402)->assertJsonPath('code', 'plan_limit_exceeded');
        $api->getJson('/api/v1/customer/predictions')->assertOk()->assertJsonPath('envelope.horizon_end', '2026-10-31');   // por defecto 30 días
        [$t2, $u2] = $this->createTenantWithUser();
        $c2 = $this->seedCustomerCatalog($t2);
        $this->seedPredictionRun($t2, $c2, [7, 30]);                                  // plan completo, pero la corrida se abstuvo de 90
        $this->actingAsTenantUser($u2, $t2)->getJson('/api/v1/customer/predictions?horizon=90')->assertStatus(422)->assertJsonPath('code', 'horizon_not_available');
    }

    public function test_predictions_without_a_run_are_an_explicit_abstention(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $this->seedCustomerCatalog($tenant);
        $res = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/customer/predictions')->assertOk();
        $this->assertSame([], $res->json('data'));
        $this->assertSame('insufficient_data', $res->json('envelope.quality_status'));
        $this->assertNull($res->json('envelope.run_id'));
        $this->assertNotEmpty($res->json('envelope.limitations'));
    }

    public function test_predictions_are_isolated_between_companies(): void
    {
        [$a, $ua] = $this->createTenantWithUser();
        [$b, $ub] = $this->createTenantWithUser();
        $catA = $this->seedCustomerCatalog($a);
        $catB = $this->seedCustomerCatalog($b);
        $this->seedPredictionRun($a, $catA);
        $runB = $this->seedPredictionRun($b, $catB);
        $api = $this->actingAsTenantUser($ua, $a);
        $ids = array_map(fn ($r) => $r['customer']['id'], $api->getJson('/api/v1/customer/predictions?horizon=30&per_page=100')->json('data'));
        $this->assertEmpty(array_intersect($ids, array_values($catB['customers'])));
        $api->getJson('/api/v1/customer/predictions?horizon=30&customer_id='.$catB['customers']['c1'])->assertStatus(422)->assertJsonValidationErrors('customer_id');
        $api->getJson('/api/v1/customer/predictions?horizon=30&product_id='.$catB['products']['p1'])->assertStatus(422)->assertJsonValidationErrors('product_id');
        $api->getJson('/api/v1/customer/predictions?horizon=30&run_id='.$runB)->assertStatus(404);
        $this->actingAsTenantUser($ub, $b)->getJson('/api/v1/customer/predictions?horizon=30')->assertOk()->assertJsonPath('envelope.run_id', $runB);
    }

    // ── perfil ──
    public function test_profile_shows_frequency_ranking_inactivity_and_behavior_change(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCustomerCatalog($tenant);
        $this->seedPredictionRun($tenant, $cat);
        $api = $this->actingAsTenantUser($user, $tenant);

        $p = $api->getJson('/api/v1/customer/customers/'.$cat['customers']['c2'].'/profile')->assertOk();
        $this->assertSame('predicted', $p->json('data.status'));
        $this->assertSame(2.5, $p->json('data.activity.purchase_frequency_per_30d'));
        $this->assertSame('inactive_by_pattern', $p->json('data.activity.inactivity.status'));
        $this->assertStringContainsString('No es abandono', $p->json('data.activity.inactivity.definition'));
        $this->assertSame('decrease', $p->json('data.activity.behavior_change.status'));
        $this->assertSame([7, 30, 90], array_map('intval', array_keys($p->json('data.rankings'))));
        $this->assertSame(2, count($p->json('data.rankings.30')));
        $this->assertSame(1, $p->json('data.rankings.30.0.rank'));
        $this->assertSame(['7' => 'popularity', '30' => 'model', '90' => 'model'], $p->json('data.ranking_sources'));   // fuente decidida en el holdout (cust-ranking-v1)
        $this->assertSame('popularity', $p->json('data.rankings.7.0.ranking_source'));
        $this->assertSame('model', $p->json('data.rankings.30.0.ranking_source'));
        $this->assertStringContainsString('cust-ranking-v1', $p->json('data.ranking_source_note'));
        $this->assertSame('customer', $p->json('envelope.core'));
        $api->getJson('/api/v1/customer/customers/'.$cat['customers']['c2'].'/profile?horizon=7')->assertOk()->assertJsonCount(1, 'data.rankings');
    }

    public function test_generic_buyers_have_no_individual_profile_and_the_fallback_is_identified(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCustomerCatalog($tenant);
        $this->seedPredictionRun($tenant, $cat);
        $p = $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/customer/customers/'.$cat['customers']['c3'].'/profile')->assertOk();
        $this->assertSame('no_individual_prediction', $p->json('data.status'));
        $this->assertTrue($p->json('data.customer.is_generic'));
        $this->assertNull($p->json('data.activity'));
        $this->assertStringContainsString('residual', $p->json('data.fallback'));
    }

    public function test_profile_of_a_buyer_from_another_company_is_404(): void
    {
        [$a, $ua] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();
        $this->seedCustomerCatalog($a);
        $catB = $this->seedCustomerCatalog($b);
        $this->actingAsTenantUser($ua, $a)->getJson('/api/v1/customer/customers/'.$catB['customers']['c1'].'/profile')->assertStatus(404);
    }

    // ── evaluación ──
    public function test_evaluation_returns_manifest_metrics_and_curves_with_sample_sizes(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $run = $this->seedTrainingRun($tenant);
        $api = $this->actingAsTenantUser($user, $tenant);
        $res = $api->getJson('/api/v1/customer/evaluation')->assertOk();
        $this->assertSame($run, $res->json('data.run_id'));
        $this->assertSame('evaluation', $res->json('envelope.result_type'));
        $this->assertArrayHasKey('holdout', $res->json('data.manifest.horizons.30.blocks'));
        $this->assertSame('2026-09-30', $res->json('data.manifest.labels_mature_through'));
        $rows = $res->json('data.metrics')['h30:probability'];
        $this->assertCount(2, $rows);
        $this->assertTrue($rows[0]['is_baseline']);
        $this->assertSame(2000, $rows[1]['n_obs']);
        $this->assertSame(1000, $rows[1]['metrics']['calibration_curve'][0]['n']);
        $this->assertArrayHasKey('coherence', $res->json('data.metrics'));
        $only = $api->getJson('/api/v1/customer/evaluation?horizon=7')->assertOk();
        $this->assertSame(['h7:probability'], array_keys($only->json('data.metrics')));
        $this->assertSame([7], array_map('intval', array_keys($only->json('data.manifest.horizons'))));
    }

    public function test_evaluation_hides_horizons_outside_the_plan_and_abstains_without_training(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner, ['plan_id' => $this->basicPlanId()]);
        $api = $this->actingAsTenantUser($user, $tenant);
        $this->assertSame('insufficient_data', $api->getJson('/api/v1/customer/evaluation')->assertOk()->json('envelope.quality_status'));
        $this->seedTrainingRun($tenant);
        $res = $api->getJson('/api/v1/customer/evaluation')->assertOk();
        $this->assertNotContains('h90:probability', array_keys($res->json('data.metrics')));
        $this->assertSame([7, 30], array_map('intval', array_keys($res->json('data.manifest.horizons'))));
        $api->getJson('/api/v1/customer/evaluation?horizon=90')->assertStatus(402);
    }

    // ── reconciliación ──
    public function test_reconciliation_reports_components_status_and_policy(): void
    {
        [$tenant, $user] = $this->createTenantWithUser();
        $cat = $this->seedCustomerCatalog($tenant);
        $this->seedPredictionRun($tenant, $cat);
        $api = $this->actingAsTenantUser($user, $tenant);
        $res = $api->getJson('/api/v1/customer/reconciliation?horizon=30')->assertOk();
        $this->assertSame('inconsistent', $res->json('data.0.status'));                  // las inconsistencias primero
        $this->assertEquals(15.0, $res->json('data.0.excess_quantity'));
        $this->assertEquals(0.0, $res->json('data.0.residual_quantity'));
        $ok = $res->json('data.1');
        $this->assertSame('ok', $ok['status']);
        $this->assertEqualsWithDelta($ok['aggregate_quantity'], $ok['buyers_total_quantity'] + $ok['committed_outside_model'] + $ok['residual_quantity'], 1e-6);
        $this->assertSame('saco', $ok['product']['unit']);
        $this->assertSame(['inconsistent' => 1, 'ok' => 1], $res->json('summary.status_counts'));
        $this->assertStringContainsString('no se suma', $res->json('policy'));
        $this->assertSame(['product_id'], $res->json('envelope.entity_keys'));
        $api->getJson('/api/v1/customer/reconciliation?horizon=30&status=ok')->assertOk()->assertJsonCount(1, 'data');
        $api->getJson('/api/v1/customer/reconciliation?horizon=30&status=nada')->assertStatus(422);
    }

    public function test_reconciliation_is_isolated_and_requires_view_ability(): void
    {
        [$a, $ua] = $this->createTenantWithUser();
        [$b, $ub] = $this->createTenantWithUser();
        $this->seedPredictionRun($a, $this->seedCustomerCatalog($a));
        $catB = $this->seedCustomerCatalog($b);
        $this->actingAsTenantUser($ub, $b)->getJson('/api/v1/customer/reconciliation?horizon=30')->assertOk()->assertJsonPath('envelope.quality_status', 'insufficient_data');
        $this->actingAsTenantUser($ua, $a)->getJson('/api/v1/customer/reconciliation?horizon=30&product_id='.$catB['products']['p1'])->assertStatus(422);
    }
}
