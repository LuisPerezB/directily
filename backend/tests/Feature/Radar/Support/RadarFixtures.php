<?php

declare(strict_types=1);

namespace Tests\Feature\Radar\Support;

use App\Contracts\Decision\RecommendationSink;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Radar\CommercialOpportunityService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\Support\ModelQualityFixtures;
use Tests\Feature\Customer\Support\CustomerFixtures;

/**
 * Escenario determinista FICTICIO del Revenue Radar sobre los fixtures del Customer Core: 2 compradores modelados (c1 activo; c2 inactivo respecto a su patrón) y el
 * consumidor final (genérico) × 2 productos, corrida de predicción a 7/30/90 días con cantidades de S05 y la relación c2 × p1 con la ventana ATRASADA (overdue).
 *
 * Qué propone el Radar con la política por defecto (min_probability 0,20; recuperación = ventana atrasada con ≥ 3 compras):
 * - contacto: c1 × p1, c1 × p2 y c2 × p2 en cada horizonte (c2 × p1 tiene 0,10–0,20 y además es recuperación) → 9.
 * - recuperación: c2 × p1 en cada horizonte → 3.
 * Total: 12. El Decision Engine de W5-B genera además, a nivel de cliente, la recuperación y el riesgo (que el Radar solo lista): addCustomerLevel() los agrega con el
 * doble del motor, y materializeAll() deja las 14.
 */
trait RadarFixtures
{
    use CustomerFixtures;
    use ModelQualityFixtures;

    /** Fecha de corte de la predicción de los fixtures. */
    protected string $asOf = '2026-10-01';

    /** @return array{tenant: Tenant, user: User, cat: array, run: int} */
    protected function radarCompany(TenantRole $role = TenantRole::Analyst, bool $s05 = true): array
    {
        [$tenant, $user] = $this->createTenantWithUser($role);
        $cat = $this->seedCustomerCatalog($tenant);
        $run = $this->seedPredictionRun($tenant, $cat, [7, 30, 90], $this->asOf);
        if ($s05) {
            $this->seedCustomerS05($tenant->id, $run, $cat);
            // La relación c2 × p1 está atrasada respecto a su patrón: «overdue» sí publica cuantiles (anchos).
            DB::table('customer_predictions')->where('core_run_id', $run)->where('customer_id', $cat['customers']['c2'])->where('product_id', $cat['products']['p1'])
                ->update(['window_status' => 'overdue', 'window_p10_days' => 5, 'window_p50_days' => 9, 'window_p90_days' => 14]);
        }

        return compact('tenant', 'user', 'cat', 'run');
    }

    protected function fakeSink(): FakeRecommendationSink
    {
        $sink = new FakeRecommendationSink;
        $this->app->instance(RecommendationSink::class, $sink);

        return $sink;
    }

    /** Propone las oportunidades del Radar (12) con el doble del Decision Engine; devuelve el doble. */
    protected function materialize(Tenant $tenant, ?FakeRecommendationSink $sink = null): FakeRecommendationSink
    {
        $sink ??= $this->fakeSink();
        $this->withinTenant($tenant, fn () => app(CommercialOpportunityService::class)->materialize($sink));

        return $sink;
    }

    /**
     * Lo que genera el Decision Engine a nivel de cliente (W5-B, `predictily:decisions-generate`) y el Radar solo lista: recuperación de c2 y riesgo de c1, horizonte 30.
     *
     * @param  array{tenant: Tenant, cat: array, run: int}  $c
     */
    protected function addCustomerLevel(array $c, FakeRecommendationSink $sink): void
    {
        $this->withinTenant($c['tenant'], function () use ($c, $sink): void {
            foreach ([['recovery', 'c2', 25.0], ['customer_risk', 'c1', null]] as [$kind, $customer, $value]) {
                $sink->propose([
                    'kind' => $kind, 'as_of' => $this->asOf, 'horizon_days' => 30, 'entity_type' => 'customer', 'customer_id' => $c['cat']['customers'][$customer],
                    'source_core_run_id' => $c['run'], 'expected_gross_value' => $value, 'probability' => null, 'policy' => 'decision-engine',
                    'assumptions' => ['Ausencia respecto al patrón propio del cliente: no implica abandono definitivo.'],
                    'explanation' => [['code' => 'inactivity_status', 'label' => 'Estado de actividad respecto al patrón propio', 'value' => 'inactive_by_pattern', 'source' => 'customer_activity']],
                ]);
            }
        });
    }

    /** Las 12 del Radar + las 2 de nivel cliente del Decision Engine = 14 oportunidades. */
    protected function materializeAll(array $c): FakeRecommendationSink
    {
        $sink = $this->materialize($c['tenant']);
        $this->addCustomerLevel($c, $sink);

        return $sink;
    }

    /** Fotografía de inventario de un producto en la sucursal de los fixtures. */
    protected function seedStock(Tenant $tenant, array $cat, string $productKey, string $date, float $onHand, float $reserved = 0, float $inTransit = 0): void
    {
        DB::table('inventory_snapshots')->insert([
            'tenant_id' => $tenant->id, 'branch_id' => $cat['branch'], 'product_id' => $cat['products'][$productKey], 'snapshot_date' => $date,
            'on_hand' => $onHand, 'reserved' => $reserved, 'in_transit' => $inTransit, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Ventas válidas de un comprador con un producto: una factura por fecha, con el costo histórico indicado (null = el origen no entregó costo).
     *
     * @param  list<string>  $dates
     */
    protected function seedCustomerSales(Tenant $tenant, array $cat, string $customerKey, string $productKey, array $dates, float $quantity = 2.0, ?float $unitCost = null, float $unitPrice = 8.0): void
    {
        foreach ($dates as $date) {
            $saleId = (int) DB::table('sales')->insertGetId([
                'tenant_id' => $tenant->id, 'branch_id' => $cat['branch'], 'customer_id' => $cat['customers'][$customerKey], 'document_type' => 'invoice',
                'document_number' => '001-001-'.str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT), 'status' => 'completed', 'issued_at' => $date.' 12:00:00',
                'sale_date' => $date, 'currency' => 'USD', 'total' => $quantity * $unitPrice, 'source_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('sale_items')->insert([
                'tenant_id' => $tenant->id, 'sale_id' => $saleId, 'line_number' => 1, 'product_id' => $cat['products'][$productKey], 'branch_id' => $cat['branch'],
                'sale_date' => $date, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'discount_amount' => 0, 'net_amount' => $quantity * $unitPrice, 'tax_amount' => 0,
                'unit_cost' => $unitCost, 'is_return' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
