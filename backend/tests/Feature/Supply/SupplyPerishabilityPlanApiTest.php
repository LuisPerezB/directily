<?php

declare(strict_types=1);

namespace Tests\Feature\Supply;

use App\Enums\TenantRole;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * V2.5 · W5-D: el detalle del plan entrega, tal como los guardó el motor, la posición de inventario (disponible, comprometido sin doble conteo,
 * validez del stock), la perecibilidad con la merma FEFO proyectada, el escenario «sin compra» y la política de reposición; los detalles por serie
 * solo con `?include=`, y una empresa no ve el plan de otra.
 */
final class SupplyPerishabilityPlanApiTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private function seedPlan(Tenant $tenant, string $marker): int
    {
        $now = now();
        $run = (int) DB::table('core_runs')->insertGetId([
            'tenant_id' => $tenant->id, 'core' => 'supply', 'operation' => 'plan', 'version' => 1, 'run_type' => 'production', 'status' => 'completed',
            'result_type' => 'optimized_plan', 'quality_status' => 'degraded', 'uncertainty_source' => 'none', 'as_of' => '2026-10-26',
            'horizon_start' => '2026-10-27', 'horizon_end' => '2027-01-18', 'options' => '{}', 'is_current' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $details = [
            'report' => ['availability' => [
                'rule' => 'disponible = físico − reservado no respaldado', 'commitments_mode' => 'reconcile',
                'validity' => ['over_reserved' => 1, 'over_reserved_qty' => 50.0], 'commitments' => ['series' => 1, 'committed_qty' => 40.0, 'double_count_avoided_qty' => 40.0],
                'series' => [['branch_id' => 1, 'product_id' => 2, 'available' => 100.0, 'marker' => $marker]], 'series_total' => 1,
            ]],
            'perishability' => [
                'enabled' => true, 'series_with_effect' => 1, 'expected_expiry_loss_units' => 10.0, 'low_demand_expiry_loss_units' => 15.0,
                'series' => [['branch_id' => 1, 'product_id' => 2, 'status' => 'lots']],
                'audit' => ['expected_expiry_loss_units' => 10.0, 'series' => [['branch_id' => 1, 'product_id' => 2, 'expected_expiry_loss_units' => 10.0]]],
            ],
            'no_purchase_baseline' => ['unmet_units' => 740.0, 'series_with_shortfall' => 1, 'lost_margin' => 2220.0, 'series' => []],
            'replenishment_policy' => [['branch_id' => 1, 'product_id' => 2, 'service_level' => 0.95, 'z' => 1.6449, 'formula' => 'SS = z(NS) · σ_L']],
        ];
        DB::table('supply_plans')->insert([
            'tenant_id' => $tenant->id, 'core_run_id' => $run, 'objective' => 'min_cost', 'coverage_mode' => 'soft', 'solver_status' => 'optimal', 'mip_gap' => 0,
            'objective_value' => 10, 'currency' => 'USD', 'total_purchase_cost' => 9, 'unmet_units_total' => 0, 'demand_units_total' => 100,
            'details' => json_encode($details), 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $run;
    }

    public function test_plan_detail_returns_position_perishability_no_purchase_scenario_and_policy_with_details_only_on_request(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Viewer);
        $run = $this->withinTenant($tenant, fn () => $this->seedPlan($tenant, 'A'));
        $api = $this->actingAsTenantUser($user, $tenant);

        $base = $api->getJson("/api/v1/supply/plans/{$run}")->assertOk();
        $base->assertJsonPath('data.availability.validity.over_reserved_qty', 50)
            ->assertJsonPath('data.availability.commitments.double_count_avoided_qty', 40)
            ->assertJsonPath('data.perishability.expected_expiry_loss_units', 10)
            ->assertJsonPath('data.perishability.low_demand_expiry_loss_units', 15)
            ->assertJsonPath('data.no_purchase_baseline.unmet_units', 740)
            ->assertJsonPath('data.replenishment_policy', null);
        $this->assertArrayNotHasKey('series', $base->json('data.availability'));
        $this->assertArrayNotHasKey('series', $base->json('data.perishability'));
        $this->assertArrayNotHasKey('series', $base->json('data.perishability.audit'));

        $full = $api->getJson("/api/v1/supply/plans/{$run}?include=inventory_position,perishability_detail,replenishment_policy")->assertOk();
        $full->assertJsonPath('data.availability.series.0.marker', 'A')
            ->assertJsonPath('data.perishability.series.0.status', 'lots')
            ->assertJsonPath('data.perishability.audit.series.0.expected_expiry_loss_units', 10)
            ->assertJsonPath('data.replenishment_policy.0.formula', 'SS = z(NS) · σ_L');
        $api->getJson("/api/v1/supply/plans/{$run}?include=otro")->assertStatus(422);
    }

    public function test_a_company_cannot_read_the_plan_of_another(): void
    {
        [$tenantA] = $this->createTenantWithUser(TenantRole::Viewer);
        [$tenantB, $userB] = $this->createTenantWithUser(TenantRole::Viewer);
        $runA = $this->withinTenant($tenantA, fn () => $this->seedPlan($tenantA, 'SOLO-A'));
        $this->actingAsTenantUser($userB, $tenantB)->getJson("/api/v1/supply/plans/{$runA}?include=inventory_position")->assertNotFound();
    }
}
