<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\TenantRole;
use App\Jobs\Forecasting\RunForecastPipeline;
use App\Models\Tenant;
use App\Services\Forecasting\ForecastRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\TestCase;

/**
 * Validación del 28-oct-2026, hallazgo F7: la programación (y toda solicitud sin horizonte
 * explícito) pedía siempre 90 días, que el plan Básico no incluye; el plan rechazaba la
 * ejecución y la empresa se quedaba sin pronóstico programado.
 */
class PlanHorizonSchedulingTest extends TestCase
{
    use AnalyticsFixtures;
    use RefreshDatabase;

    /** Plan con los límites reales del plan Básico de config/predictily.php (horizontes 7 y 30). */
    private function basicPlanId(): int
    {
        $limits = config('predictily.plans.basico.limits');
        $this->assertSame([7, 30], $limits['horizons']);

        return (int) DB::table('plans')->insertGetId([
            'code' => 'basico', 'name' => 'Básico', 'limits' => json_encode($limits), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_scheduler_uses_the_largest_horizon_allowed_by_the_basic_plan(): void
    {
        Queue::fake();
        [$basic] = $this->createTenantWithUser(TenantRole::Owner, ['plan_id' => $this->basicPlanId()]);
        $this->seedSales($basic, $this->seedCatalog($basic), '2026-09-30', 10);
        [$trial] = $this->createTenantWithUser();
        $this->seedSales($trial, $this->seedCatalog($trial), '2026-09-30', 10);

        $this->artisan('predictily:schedule-forecasts')
            ->expectsOutputToContain('(30 días) encolada')
            ->expectsOutputToContain('Ejecuciones programadas: 2')
            ->assertSuccessful();

        Queue::assertPushed(RunForecastPipeline::class, 2);
        $this->assertDatabaseHas('forecast_runs', ['tenant_id' => $basic->id, 'triggered_by' => 'schedule', 'horizon_days' => 30]);
        $this->assertDatabaseHas('forecast_runs', ['tenant_id' => $trial->id, 'triggered_by' => 'schedule', 'horizon_days' => 90]);
        $this->assertDatabaseMissing('forecast_runs', ['tenant_id' => $basic->id, 'horizon_days' => 90]);
    }

    public function test_request_without_horizon_uses_plan_maximum_and_explicit_90_is_still_rejected(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner, ['plan_id' => $this->basicPlanId()]);
        $api = $this->actingAsTenantUser($user, $tenant);

        $api->postJson('/api/v1/forecast-runs')->assertStatus(202)->assertJsonPath('data.horizon_days', 30);
        DB::table('forecast_runs')->where('tenant_id', $tenant->id)->update(['status' => 'completed']);
        $api->postJson('/api/v1/forecast-runs', ['horizon_days' => 90])
            ->assertStatus(402)->assertJsonPath('code', 'plan_limit_exceeded');
    }

    public function test_tenant_whose_plan_has_no_horizons_is_skipped_with_a_reason(): void
    {
        Queue::fake();
        [$tenant] = $this->createTenantWithUser(TenantRole::Owner, ['plan_overrides' => ['horizons' => []]]);
        $this->seedSales($tenant, $this->seedCatalog($tenant), '2026-09-30', 10);

        $this->assertNull(app(ForecastRunService::class)->maxAllowedHorizon(Tenant::query()->findOrFail($tenant->id)));
        $this->artisan('predictily:schedule-forecasts')
            ->expectsOutputToContain('el plan no incluye horizontes de pronóstico')
            ->expectsOutputToContain('Ejecuciones programadas: 0')
            ->assertSuccessful();
        Queue::assertNothingPushed();
    }
}
