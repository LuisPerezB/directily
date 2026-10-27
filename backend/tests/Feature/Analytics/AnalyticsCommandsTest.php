<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\TenantRole;
use App\Jobs\Forecasting\EvaluateActuals;
use App\Jobs\Forecasting\RunForecastPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\TestCase;

class AnalyticsCommandsTest extends TestCase
{
    use AnalyticsFixtures;
    use RefreshDatabase;

    public function test_schedule_forecasts_respects_schedule_and_avoids_unnecessary_training(): void
    {
        Queue::fake();
        // Debe programarse: semanal, con ventas y sin pronóstico previo.
        [$due] = $this->createTenantWithUser();
        $this->seedSales($due, $this->seedCatalog($due), '2026-09-30', 10);
        // Manual: nunca se programa.
        [$manual] = $this->createTenantWithUser(TenantRole::Owner, ['settings' => ['forecast_schedule' => 'manual']]);
        $this->seedSales($manual, $this->seedCatalog($manual), '2026-09-30', 10);
        // Sin ventas nuevas desde el último corte.
        [$upToDate] = $this->createTenantWithUser();
        $cat = $this->seedCatalog($upToDate);
        $this->seedSales($upToDate, $cat, '2026-09-30', 10);
        $this->seedForecastRun($upToDate, $cat, '2026-09-30', ['with_evaluations' => false, 'with_recommendations' => false, 'horizon' => 7]);
        // Sin ventas.
        $this->createTenantWithUser();

        $this->artisan('predictily:schedule-forecasts')
            ->expectsOutputToContain('programación manual')
            ->expectsOutputToContain('sin ventas nuevas desde el último pronóstico')
            ->expectsOutputToContain('sin ventas')
            ->expectsOutputToContain('Ejecuciones programadas: 1')
            ->assertSuccessful();

        Queue::assertPushed(RunForecastPipeline::class, 1);
        Queue::assertPushed(RunForecastPipeline::class, fn ($job) => $job->tenantId === $due->id);
        $this->assertDatabaseHas('forecast_runs', ['tenant_id' => $due->id, 'triggered_by' => 'schedule', 'horizon_days' => 90]);
    }

    public function test_evaluate_actuals_command_dispatches_only_tenants_with_completed_runs(): void
    {
        Queue::fake();
        [$withRun] = $this->createTenantWithUser();
        $this->seedForecastRun($withRun, $this->seedCatalog($withRun), '2026-09-30', ['with_evaluations' => false, 'with_recommendations' => false, 'horizon' => 7]);
        $this->createTenantWithUser();

        $this->artisan('predictily:evaluate-actuals')->expectsOutputToContain('Evaluaciones encoladas: 1')->assertSuccessful();

        Queue::assertPushed(EvaluateActuals::class, fn (EvaluateActuals $job) => $job->tenantId === $withRun->id);
    }

    public function test_forecast_command_sync_runs_pipeline_against_engine(): void
    {
        config(['engine.url' => 'http://engine.test', 'engine.token' => 't']);
        [$tenant] = $this->createTenantWithUser();
        Http::fake(['engine.test/*' => function () {
            DB::table('forecast_runs')->update(['status' => 'completed', 'completed_at' => now(), 'cutoff_date' => '2026-09-30']);

            return Http::response(['status' => 'completed', 'series_count' => 6]);
        }]);

        $this->artisan('predictily:forecast', ['tenant' => $tenant->uuid, '--horizon' => 30, '--sync' => true])
            ->expectsOutputToContain('Ejecución v1')
            ->assertSuccessful();

        $this->assertDatabaseHas('training_jobs', ['tenant_id' => $tenant->id, 'status' => 'succeeded']);
        $this->artisan('predictily:forecast', ['tenant' => 'no-existe'])->assertFailed();
    }
}
