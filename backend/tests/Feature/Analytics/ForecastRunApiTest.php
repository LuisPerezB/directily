<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\TenantRole;
use App\Jobs\Forecasting\RunForecastPipeline;
use App\Models\ForecastRun;
use App\Models\TrainingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\TestCase;

class ForecastRunApiTest extends TestCase
{
    use AnalyticsFixtures;
    use RefreshDatabase;

    public function test_request_creates_queued_run_and_job_and_dispatches_pipeline(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);

        $response = $this->actingAsTenantUser($user, $tenant)
            ->postJson('/api/v1/forecast-runs', ['horizon_days' => 30]);

        $response->assertStatus(202)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.run_type', 'production')
            ->assertJsonPath('data.horizon_days', 30)
            ->assertJsonPath('data.options.horizons', [7, 14, 30])
            ->assertJsonPath('data.training_job.status', 'queued')
            ->assertJsonPath('data.training_job.job_type', 'forecast_pipeline');

        $runId = $response->json('data.id');
        Queue::assertPushedOn('forecasting', RunForecastPipeline::class, fn (RunForecastPipeline $job) => $job->tenantId === $tenant->id && $job->forecastRunId === $runId);
        $this->assertDatabaseHas('training_jobs', ['tenant_id' => $tenant->id, 'forecast_run_id' => $runId, 'status' => 'queued']);
    }

    public function test_versions_increment_per_tenant_and_cutoff_creates_historical_run(): void
    {
        Queue::fake();
        [$tenantA, $userA] = $this->createTenantWithUser();
        [$tenantB, $userB] = $this->createTenantWithUser();

        $first = $this->actingAsTenantUser($userA, $tenantA)->postJson('/api/v1/forecast-runs', ['cutoff_date' => now()->subDays(40)->toDateString()]);
        $first->assertStatus(202)->assertJsonPath('data.run_type', 'historical')->assertJsonPath('data.version', 1);
        $this->closeRuns();

        $this->actingAsTenantUser($userA, $tenantA)->postJson('/api/v1/forecast-runs')->assertJsonPath('data.version', 2);
        $this->actingAsTenantUser($userB, $tenantB)->postJson('/api/v1/forecast-runs')->assertJsonPath('data.version', 1);
    }

    public function test_concurrent_request_returns_409_while_a_run_is_active(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser();
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/forecast-runs')->assertStatus(202);

        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/forecast-runs')
            ->assertStatus(409)
            ->assertJsonPath('code', 'forecast_run_in_progress')
            ->assertJsonPath('active_run.version', 1);
    }

    public function test_stale_active_run_does_not_block_new_requests(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser();
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/forecast-runs')->assertStatus(202);
        DB::table('forecast_runs')->update(['created_at' => now()->subHours(5)]);

        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/forecast-runs')->assertStatus(202)->assertJsonPath('data.version', 2);
    }

    public function test_validation_and_permissions(): void
    {
        Queue::fake();
        [$tenant] = $this->createTenantWithUser();
        $viewer = $this->addUserToTenant($tenant, TenantRole::Viewer);
        $analyst = $this->addUserToTenant($tenant, TenantRole::Analyst);

        $this->actingAsTenantUser($viewer, $tenant)->postJson('/api/v1/forecast-runs')->assertStatus(403);
        $this->actingAsTenantUser($analyst, $tenant)->postJson('/api/v1/forecast-runs', ['horizon_days' => 45])->assertStatus(422)->assertJsonValidationErrors('horizon_days');
        $this->actingAsTenantUser($analyst, $tenant)->postJson('/api/v1/forecast-runs', ['cutoff_date' => now()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('cutoff_date');
        $this->actingAsTenantUser($viewer, $tenant)->getJson('/api/v1/forecast-runs')->assertOk();
        Queue::assertNothingPushed();
    }

    public function test_plan_horizons_are_enforced(): void
    {
        Queue::fake();
        $planId = DB::table('plans')->insertGetId([
            // Nota: PlanLimitService (core) fusiona listas por índice; ver docs/00 [backend-analytics].
            'code' => 'basico-test', 'name' => 'Básico', 'limits' => json_encode(['horizons' => [30, 90]]), 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner, ['plan_id' => $planId]);

        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/forecast-runs', ['horizon_days' => 7])
            ->assertStatus(402)->assertJsonPath('code', 'plan_limit_exceeded')->assertJsonPath('limit', 'horizons');
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/forecast-runs', ['horizon_days' => 30])->assertStatus(202);
        $this->assertDatabaseHas('tenant_usage', ['tenant_id' => $tenant->id, 'metric' => 'forecast_runs', 'value' => 1]);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'forecast.requested']);
    }

    public function test_daily_run_limit_of_plan_returns_402(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner, ['plan_overrides' => ['max_forecast_runs_per_day' => 1]]);
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/forecast-runs')->assertStatus(202);
        DB::table('forecast_runs')->update(['status' => 'completed']);

        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/forecast-runs')
            ->assertStatus(402)->assertJsonPath('limit', 'max_forecast_runs_per_day');
    }

    public function test_list_show_and_job_status(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser();
        $created = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/forecast-runs')->json('data');

        $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/forecast-runs')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
        $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/forecast-runs/'.$created['id'])
            ->assertOk()->assertJsonPath('data.id', $created['id']);
        $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/jobs/'.$created['training_job']['id'])
            ->assertOk()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.progress', 0);
    }

    public function test_runs_and_jobs_of_other_tenant_are_not_found(): void
    {
        Queue::fake();
        [$tenantA, $userA] = $this->createTenantWithUser();
        [$tenantB, $userB] = $this->createTenantWithUser();
        $created = $this->actingAsTenantUser($userA, $tenantA)->postJson('/api/v1/forecast-runs')->json('data');

        $this->actingAsTenantUser($userB, $tenantB)->getJson('/api/v1/forecast-runs/'.$created['id'])->assertNotFound();
        $this->actingAsTenantUser($userB, $tenantB)->getJson('/api/v1/jobs/'.$created['training_job']['id'])->assertNotFound();
        $this->actingAsTenantUser($userB, $tenantB)->getJson('/api/v1/forecast-runs')->assertOk()->assertJsonCount(0, 'data');
        // Un usuario de B no puede usar el header de A.
        $this->actingAsTenantUser($userB, $tenantA)->getJson('/api/v1/forecast-runs')->assertNotFound();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson('/api/v1/forecast-runs')->assertUnauthorized();
        $this->getJson('/api/v1/dashboard/summary')->assertUnauthorized();
    }

    private function closeRuns(): void
    {
        DB::table('forecast_runs')->update(['status' => ForecastRun::STATUS_COMPLETED]);
        DB::table('training_jobs')->update(['status' => TrainingJob::STATUS_SUCCEEDED]);
    }
}
