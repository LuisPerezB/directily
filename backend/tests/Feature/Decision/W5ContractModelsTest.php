<?php

declare(strict_types=1);

namespace Tests\Feature\Decision;

use App\Contracts\Decision\RecommendationSink;
use App\Models\DecisionEvent;
use App\Models\DecisionOutcome;
use App\Models\DecisionRecommendation;
use App\Models\ExperimentAssignment;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Contrato de W5 (activación): los modelos de las tablas nuevas guardan con su empresa y la consulta de otra empresa no los ve. */
final class W5ContractModelsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_the_w5_models_persist_within_their_company_and_are_invisible_to_another(): void
    {
        [$a] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();
        $branch = $this->createBranch($a);

        $this->withinTenant($a, function () use ($branch): void {
            $product = Product::query()->create(['sku' => 'P-1', 'name' => 'Producto', 'unit' => 'unidad', 'is_perishable' => true, 'shelf_life_days' => 30]);
            $rec = DecisionRecommendation::query()->create([
                'kind' => 'contact', 'as_of' => '2026-10-26', 'horizon_days' => 30, 'entity_type' => 'product_branch',
                'product_id' => $product->id, 'branch_id' => $branch->id, 'score_formula_version' => str_repeat('a', 64),
                'idempotency_key' => str_repeat('b', 64), 'assumptions' => ['sin costo confiable'],
            ]);
            DecisionEvent::query()->create(['recommendation_id' => $rec->id, 'event' => 'transition', 'to_status' => 'suggested',
                'actor_kind' => 'system', 'idempotency_key' => str_repeat('c', 64)]);
            ExperimentAssignment::query()->create(['experiment_key' => 'contacto-2026-11', 'unit_type' => 'customer', 'unit_key' => 'customer:1', 'group' => 'control']);
            DecisionOutcome::query()->create(['recommendation_id' => $rec->id, 'window_from' => '2026-10-27', 'window_to' => '2026-11-25',
                'observed_event' => 'no_purchase', 'classification' => 'observed']);
            InventoryLot::query()->create(['branch_id' => $branch->id, 'product_id' => $product->id, 'lot_code' => 'L-1',
                'snapshot_date' => '2026-10-26', 'expiry_date' => '2026-11-20', 'quantity' => 12]);

            $fresh = DecisionRecommendation::query()->findOrFail($rec->id);
            $this->assertSame('suggested', $fresh->status);
            $this->assertSame('potential_unproven', $fresh->attribution_label);
            $this->assertNull($fresh->expected_contribution);
            $this->assertSame(['sin costo confiable'], $fresh->assumptions);
            $this->assertTrue((bool) Product::query()->findOrFail($product->id)->is_perishable);
        });

        $counts = fn (Tenant $t): array => $this->withinTenant($t, fn (): array => [
            DecisionRecommendation::query()->count(), DecisionEvent::query()->count(), ExperimentAssignment::query()->count(),
            DecisionOutcome::query()->count(), InventoryLot::query()->count(),
        ]);
        $this->assertSame([1, 1, 1, 1, 1], $counts($a));
        $this->assertSame([0, 0, 0, 0, 0], $counts($b));
    }

    public function test_the_recommendation_sink_is_an_interface_for_w5_b_to_implement(): void
    {
        $this->assertTrue(interface_exists(RecommendationSink::class));
        $this->assertTrue((new \ReflectionClass(RecommendationSink::class))->hasMethod('propose'));
    }
}
