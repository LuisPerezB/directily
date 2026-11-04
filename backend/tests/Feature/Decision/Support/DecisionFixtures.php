<?php

declare(strict_types=1);

namespace Tests\Feature\Decision\Support;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\Support\ModelQualityFixtures;
use Tests\Feature\Customer\Support\CustomerFixtures;

/**
 * Mundo determinista FICTICIO del Decision Engine (V2.5 · W5-B): una empresa con propietario, administrador, analista y consulta; catálogo del Customer Core
 * (1 sucursal, p1 y p2, c1/c2 y el consumidor final genérico c3); predicciones de comportamiento con S05; ventas con costo confiable para p1 y sin costo
 * para p2; inventario; una corrida de pronóstico con recomendaciones de reposición y un plan de abastecimiento.
 */
trait DecisionFixtures
{
    use CustomerFixtures;
    use ModelQualityFixtures;

    private int $documentCounter = 0;

    /** Serie aleatoria por instancia + contador: único dentro de la prueba (antes contador + azar podía repetirse) y distinto entre instancias que confirman datos. */
    private ?int $documentSeries = null;

    private function nextDocumentNumber(): string
    {
        $this->documentSeries ??= random_int(1, 9999);

        return sprintf('%09d', $this->documentSeries * 100000 + $this->documentCounter);
    }

    /**
     * @return array{tenant: Tenant, owner: User, admin: User, analyst: User, analyst2: User, viewer: User, cat: array, run: int, asOf: string}
     */
    protected function decisionWorld(?string $asOf = null, bool $s05 = true, array $tenantAttributes = []): array
    {
        [$tenant, $owner] = $this->createTenantWithUser(TenantRole::Owner, $tenantAttributes);
        $admin = $this->addUserToTenant($tenant, TenantRole::Admin);
        $analyst = $this->addUserToTenant($tenant, TenantRole::Analyst);
        $analyst2 = $this->addUserToTenant($tenant, TenantRole::Analyst);
        $viewer = $this->addUserToTenant($tenant, TenantRole::Viewer);
        $asOf ??= CarbonImmutable::now('America/Guayaquil')->toDateString();
        $cat = $this->seedCustomerCatalog($tenant);
        $run = $this->seedPredictionRun($tenant, $cat, [7, 30, 90], $asOf);
        if ($s05) {
            $this->seedCustomerS05($tenant->id, $run, $cat);
        }
        $this->withinTenant($tenant, function () use ($tenant, $cat, $asOf): void {
            // p1: 4 líneas con costo 6 (precio neto 8) → costo confiable. p2: 4 líneas SIN costo → costo no confiable.
            foreach ([1, 5, 9, 13] as $daysBefore) {
                $date = CarbonImmutable::parse($asOf)->subDays($daysBefore)->toDateString();
                $this->saleLine($tenant, $cat['branch'], $cat['products']['p1'], $date, 2, 8.0, 6.0);
                $this->saleLine($tenant, $cat['branch'], $cat['products']['p2'], $date, 2, 8.0, null);
            }
        });

        return compact('tenant', 'owner', 'admin', 'analyst', 'analyst2', 'viewer', 'cat', 'run', 'asOf');
    }

    /** Línea de venta ficticia (documento completado) tal como la dejaría el escritor versionado. */
    protected function saleLine(Tenant $tenant, int $branch, int $product, string $date, float $qty, float $price, ?float $cost, ?string $knownFrom = null, string $status = 'completed'): void
    {
        $this->documentCounter++;
        $saleId = (int) DB::table('sales')->insertGetId([
            'tenant_id' => $tenant->id, 'branch_id' => $branch, 'document_type' => 'invoice', 'document_number' => '001-001-'.$this->nextDocumentNumber(),
            'status' => $status, 'issued_at' => $date.' 12:00:00', 'sale_date' => $date, 'currency' => 'USD', 'source_hash' => str_repeat('a', 64),
            'version_known_from' => $knownFrom, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sale_items')->insert([
            'tenant_id' => $tenant->id, 'sale_id' => $saleId, 'line_number' => 1, 'product_id' => $product, 'branch_id' => $branch, 'sale_date' => $date,
            'quantity' => $qty, 'unit_price' => $price, 'discount_amount' => 0, 'net_amount' => $qty * $price, 'tax_amount' => 0, 'unit_cost' => $cost,
            'is_return' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function inventory(Tenant $tenant, array $cat, string $productKey, string $date, float $onHand, float $reserved = 0, float $inTransit = 0): void
    {
        DB::table('inventory_snapshots')->insert([
            'tenant_id' => $tenant->id, 'branch_id' => $cat['branch'], 'product_id' => $cat['products'][$productKey], 'snapshot_date' => $date,
            'on_hand' => $onHand, 'reserved' => $reserved, 'in_transit' => $inTransit, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Corrida de pronóstico completada con una recomendación de reposición por producto (la de p1 en riesgo crítico). */
    protected function forecastWithRecommendations(Tenant $tenant, array $cat, string $cutoff, bool $current = true): int
    {
        $tid = $tenant->id;
        if ($current) {
            DB::table('forecast_runs')->where('tenant_id', $tid)->update(['is_current' => false]);
        }
        $version = (int) DB::table('forecast_runs')->where('tenant_id', $tid)->max('version') + 1;
        $runId = (int) DB::table('forecast_runs')->insertGetId([
            'tenant_id' => $tid, 'version' => $version, 'run_type' => 'production', 'status' => 'completed', 'triggered_by' => 'user', 'horizon_days' => 30, 'frequency' => 'D',
            'cutoff_date' => $cutoff, 'data_from' => CarbonImmutable::parse($cutoff)->subDays(119)->toDateString(), 'data_to' => $cutoff, 'feature_set_version' => 'fs-test-v1',
            'strategy' => 'auto', 'options' => '{}', 'selection' => json_encode(['smooth' => ['model_family' => 'lightgbm_global']]), 'summary' => '{}', 'is_current' => $current,
            'started_at' => now()->subMinutes(5), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $rows = [
            // p1: quiebre crítico (demanda en plazo 100, disponible 20, costo conocido 6)
            ['p1', 'stockout_risk', 20, 100, 116.45, 6.0, 'critical', 0.99],
            // p2: reposición media sin costo conocido
            ['p2', 'reorder', 60, 100, 66.45, null, 'medium', 0.4],
        ];
        foreach ($rows as [$p, $type, $available, $demand, $qty, $cost, $risk, $prob]) {
            DB::table('recommendations')->insert([
                'tenant_id' => $tid, 'forecast_run_id' => $runId, 'branch_id' => $cat['branch'], 'product_id' => $cat['products'][$p], 'type' => $type,
                'inventory_snapshot_date' => $cutoff, 'on_hand' => $available, 'reserved' => 0, 'in_transit' => 0, 'available' => $available, 'lead_time_days' => 7,
                'lead_time_source' => 'tenant_default', 'review_period_days' => 7, 'service_level' => 0.95, 'demand_lead_time' => $demand, 'demand_lead_time_std' => 10,
                'safety_stock' => 16.45, 'reorder_point' => 116.45, 'suggested_qty' => $qty, 'unit_cost' => $cost, 'estimated_investment' => $cost === null ? null : $qty * $cost,
                'days_of_cover' => 3.5, 'stockout_probability' => $prob, 'stockout_date_estimate' => $cutoff, 'risk_level' => $risk, 'status' => 'open',
                'assumptions' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $runId;
    }

    /**
     * Plan de abastecimiento completado sobre una corrida de pronóstico, con una compra de p1 y un faltante por presupuesto de p1.
     *
     * @return array{run: int, plan: int}
     */
    protected function supplyPlan(Tenant $tenant, array $cat, string $asOf, ?int $forecastRunId, float $budgetUnmet = 40.0): array
    {
        $tid = $tenant->id;
        $version = (int) DB::table('core_runs')->where('tenant_id', $tid)->where('core', 'supply')->max('version') + 1;
        DB::table('core_runs')->where('tenant_id', $tid)->where('core', 'supply')->update(['is_current' => false]);
        $run = (int) DB::table('core_runs')->insertGetId([
            'tenant_id' => $tid, 'core' => 'supply', 'operation' => 'plan', 'version' => $version, 'run_type' => 'production', 'status' => 'completed', 'result_type' => 'optimized_plan',
            'quality_status' => 'ok', 'uncertainty_source' => 'none', 'as_of' => $asOf, 'horizon_start' => $asOf, 'horizon_end' => CarbonImmutable::parse($asOf)->addDays(84)->toDateString(),
            'options' => '{}', 'model_versions' => json_encode(['planner' => 'supply-test']), 'is_current' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $plan = (int) DB::table('supply_plans')->insertGetId([
            'tenant_id' => $tid, 'core_run_id' => $run, 'forecast_run_id' => $forecastRunId, 'objective' => 'min_cost', 'coverage_mode' => 'soft', 'solver_status' => 'optimal',
            'currency' => 'USD', 'total_purchase_cost' => 600, 'unmet_units_total' => $budgetUnmet, 'demand_units_total' => 200, 'details' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $supplier = (int) DB::table('suppliers')->insertGetId(['tenant_id' => $tid, 'code' => 'PRV-'.$plan, 'name' => 'Proveedor Uno', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('supply_plan_lines')->insert([
            'tenant_id' => $tid, 'supply_plan_id' => $plan, 'line_type' => 'purchase', 'branch_id' => $cat['branch'], 'product_id' => $cat['products']['p1'], 'supplier_id' => $supplier,
            'quantity' => 100, 'unit' => 'unidad', 'order_date' => $asOf, 'expected_receipt_date' => CarbonImmutable::parse($asOf)->addDays(7)->toDateString(), 'unit_cost' => 6, 'line_cost' => 600,
            'currency' => 'USD', 'actionable_now' => true, 'binding_constraints' => json_encode([['code' => 'moq', 'value' => 50]]), 'assumptions' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($budgetUnmet > 0) {
            DB::table('supply_plan_shortfalls')->insert([
                'tenant_id' => $tid, 'supply_plan_id' => $plan, 'branch_id' => $cat['branch'], 'product_id' => $cat['products']['p1'], 'period_start' => CarbonImmutable::parse($asOf)->addDays(8)->toDateString(),
                'period_end' => CarbonImmutable::parse($asOf)->addDays(14)->toDateString(), 'demand' => 100, 'unmet' => $budgetUnmet, 'reason' => 'budget',
            ]);
        }

        return ['run' => $run, 'plan' => $plan];
    }

    /** Propuesta mínima válida al Decision Engine (cliente c1 × producto p1). @return array<string, mixed> */
    protected function contactProposal(array $w, array $override = []): array
    {
        return $override + [
            'kind' => 'contact', 'as_of' => $w['asOf'], 'horizon_days' => 30, 'entity_type' => 'customer_product',
            'customer_id' => $w['cat']['customers']['c1'], 'product_id' => $w['cat']['products']['p1'], 'source_core_run_id' => $w['run'],
            'probability' => 0.65, 'expected_units' => 4.32, 'unit_price' => 8.0,
        ];
    }
}
