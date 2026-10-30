<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Exceptions\PlanLimitExceededException;
use App\Models\Plan;
use App\Services\Plans\PlanLimitService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

final class PlanLimitTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_plan_lists_replace_defaults_instead_of_merging(): void
    {
        $this->seed(PlanSeeder::class);
        [$tenant] = $this->createTenantWithUser();
        $tenant->forceFill(['plan_id' => Plan::query()->where('code', 'basico')->value('id')])->save();
        $service = app(PlanLimitService::class);

        $this->assertSame([7, 14, 30], $service->limits($tenant)['horizons']);
        $this->assertTrue($service->allowsHorizon($tenant, 30));
        $this->assertFalse($service->allowsHorizon($tenant, 90));
        $this->assertFalse($service->allows($tenant, 'scenarios'));

        $this->expectException(PlanLimitExceededException::class);
        $service->assertHorizon($tenant, 90);
    }

    public function test_overrides_replace_lists_and_merge_feature_maps(): void
    {
        $this->seed(PlanSeeder::class);
        [$tenant] = $this->createTenantWithUser(tenantAttributes: ['plan_overrides' => ['horizons' => [7], 'features' => ['external_indicators' => true]]]);
        $limits = app(PlanLimitService::class)->limits($tenant);

        $this->assertSame([7], $limits['horizons']);
        $this->assertTrue($limits['features']['external_indicators']);
        $this->assertTrue($limits['features']['scenarios']); // conservado del plan
    }

    public function test_usage_is_recorded_per_month_and_enforced(): void
    {
        [$tenant] = $this->createTenantWithUser(tenantAttributes: ['plan_overrides' => ['max_import_rows_per_month' => 100]]);
        $service = app(PlanLimitService::class);
        $service->recordUsage($tenant, 'import_rows', 60);
        $service->recordUsage($tenant, 'import_rows', 30);
        $this->assertSame(90, $service->usage($tenant, 'import_rows'));
        $service->assertCan($tenant, 'max_import_rows_per_month', 10);

        try {
            $service->assertCan($tenant, 'max_import_rows_per_month', 11);
            $this->fail('Debió exceder el límite');
        } catch (PlanLimitExceededException $e) {
            $this->assertSame(90, $e->current);
            $this->assertSame(100, $e->max);
        }
    }
}
