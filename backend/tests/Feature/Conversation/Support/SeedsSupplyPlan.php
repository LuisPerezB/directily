<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** Plan de abastecimiento FICTICIO insertado directamente (como lo dejaría el motor) para probar la herramienta supply_plan. */
trait SeedsSupplyPlan
{
    /**
     * @param  array{branches: array<string, int>, products: array<string, int>}  $cat
     * @return array{run: int, plan: int, supplier: int}
     */
    protected function seedSupplyPlan(Tenant $tenant, array $cat, string $asOf = '2026-10-20', string $supplierName = 'Proveedor Ficticio S.A.'): array
    {
        $tid = $tenant->id;
        $now = now();
        $supplier = (int) DB::table('suppliers')->insertGetId([
            'tenant_id' => $tid, 'code' => 'PRV-1', 'name' => $supplierName, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $run = (int) DB::table('core_runs')->insertGetId([
            'tenant_id' => $tid, 'core' => 'supply', 'operation' => 'plan', 'version' => 1, 'run_type' => 'production', 'status' => 'completed',
            'result_type' => 'optimized_plan', 'quality_status' => 'ok', 'uncertainty_source' => 'none', 'as_of' => $asOf,
            'horizons' => json_encode([84]), 'horizon_start' => '2026-10-21', 'horizon_end' => '2027-01-12', 'target_definition' => json_encode(['objective' => 'min_cost']),
            'options' => '{}', 'config_version' => str_repeat('d', 64), 'assumptions' => json_encode(['Supuesto ficticio']), 'limitations' => '[]',
            'is_current' => true, 'triggered_by' => 'user', 'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $plan = (int) DB::table('supply_plans')->insertGetId([
            'tenant_id' => $tid, 'core_run_id' => $run, 'objective' => 'min_cost', 'coverage_mode' => 'soft', 'solver_status' => 'optimal', 'mip_gap' => 0,
            'objective_value' => 4321.5, 'currency' => 'USD', 'total_purchase_cost' => 4000.25, 'total_holding_cost' => 200.5, 'total_shortage_penalty' => 120.75,
            'unmet_units_total' => 35, 'demand_units_total' => 900, 'details' => json_encode(['constraints' => [['name' => 'budget', 'used' => 4000.25, 'limit' => 5000]]]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('supply_plan_lines')->insert([
            [
                'tenant_id' => $tid, 'supply_plan_id' => $plan, 'line_type' => 'purchase', 'branch_id' => $cat['branches']['b1'], 'product_id' => $cat['products']['p1'],
                'supplier_id' => $supplier, 'quantity' => 120, 'unit' => 'unidad', 'order_date' => $asOf, 'expected_receipt_date' => '2026-10-27',
                'unit_cost' => 6.25, 'line_cost' => 750, 'currency' => 'USD', 'actionable_now' => true, 'binding_constraints' => '[]',
                'justification' => 'Cubre el faltante previsto antes de la recepción.', 'assumptions' => '{}', 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'tenant_id' => $tid, 'supply_plan_id' => $plan, 'line_type' => 'purchase', 'branch_id' => $cat['branches']['b2'], 'product_id' => $cat['products']['p2'],
                'supplier_id' => $supplier, 'quantity' => 40, 'unit' => 'unidad', 'order_date' => '2026-11-03', 'expected_receipt_date' => '2026-11-10',
                'unit_cost' => 18.5, 'line_cost' => 740, 'currency' => 'USD', 'actionable_now' => false, 'binding_constraints' => '[]',
                'justification' => null, 'assumptions' => '{}', 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
        DB::table('supply_plan_shortfalls')->insert([
            'tenant_id' => $tid, 'supply_plan_id' => $plan, 'branch_id' => $cat['branches']['b1'], 'product_id' => $cat['products']['p1'],
            'period_start' => '2026-10-21', 'period_end' => '2026-10-27', 'demand' => 90, 'unmet' => 35, 'reason' => 'lead_time',
        ]);

        return ['run' => $run, 'plan' => $plan, 'supplier' => $supplier];
    }
}
