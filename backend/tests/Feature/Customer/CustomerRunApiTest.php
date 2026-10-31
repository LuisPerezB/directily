<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Enums\TenantRole;
use App\Jobs\Customer\RunCustomerPrediction;
use App\Jobs\Customer\RunCustomerTraining;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Customer\Support\CustomerFixtures;
use Tests\TestCase;

/** POST/GET /api/v1/customer/runs: entrenar y predecir comportamiento de compra (asíncrono, con plan y concurrencia). */
class CustomerRunApiTest extends TestCase
{
    use CustomerFixtures;
    use RefreshDatabase;

    private function basicPlanId(): int
    {
        $limits = config('predictily.plans.basico.limits');

        return (int) DB::table('plans')->insertGetId([
            'code' => 'basico', 'name' => 'Básico', 'limits' => json_encode($limits), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_train_creates_core_run_and_training_job_and_queues_the_job(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser();
        $api = $this->actingAsTenantUser($user, $tenant);

        $res = $api->postJson('/api/v1/customer/runs', ['operation' => 'train'])->assertStatus(202)
            ->assertJsonPath('data.operation', 'train')->assertJsonPath('data.status', 'queued')->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.result_type', 'evaluation')->assertJsonPath('data.envelope.core', 'customer')
            ->assertJsonPath('data.training_job.status', 'queued');
        $this->assertSame([7, 30, 90], $res->json('data.horizons'));
        $this->assertDatabaseHas('core_runs', ['tenant_id' => $tenant->id, 'core' => 'customer', 'operation' => 'train', 'status' => 'queued', 'run_type' => 'production']);
        $this->assertDatabaseHas('training_jobs', ['tenant_id' => $tenant->id, 'job_type' => 'customer_train', 'core_run_id' => $res->json('data.id')]);
        Queue::assertPushedOn('forecasting', RunCustomerTraining::class);
        Queue::assertNotPushed(RunCustomerPrediction::class);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'customer.run_requested']);
    }

    public function test_predict_queues_the_prediction_job_and_a_past_as_of_is_historical(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser();
        $api = $this->actingAsTenantUser($user, $tenant);

        $api->postJson('/api/v1/customer/runs', ['operation' => 'predict', 'horizons' => [7, 30]])->assertStatus(202)
            ->assertJsonPath('data.horizons', [7, 30])->assertJsonPath('data.result_type', 'forecast');
        Queue::assertPushed(RunCustomerPrediction::class);
        DB::table('core_runs')->where('tenant_id', $tenant->id)->update(['status' => 'completed']);

        $api->postJson('/api/v1/customer/runs', ['operation' => 'predict', 'as_of' => '2026-01-15'])->assertStatus(202)
            ->assertJsonPath('data.run_type', 'historical')->assertJsonPath('data.as_of', '2026-01-15');
    }

    public function test_basic_plan_only_allows_7_and_30(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner, ['plan_id' => $this->basicPlanId()]);
        $api = $this->actingAsTenantUser($user, $tenant);

        $api->postJson('/api/v1/customer/runs', ['operation' => 'train'])->assertStatus(202)->assertJsonPath('data.horizons', [7, 30]);
        DB::table('core_runs')->where('tenant_id', $tenant->id)->update(['status' => 'completed']);
        $api->postJson('/api/v1/customer/runs', ['operation' => 'predict', 'horizons' => [7, 90]])
            ->assertStatus(402)->assertJsonPath('code', 'plan_limit_exceeded');
        $this->assertDatabaseCount('core_runs', 1);
    }

    public function test_a_plan_without_horizons_cannot_run_the_customer_core(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Owner, ['plan_overrides' => ['horizons' => []]]);
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/customer/runs', ['operation' => 'predict'])
            ->assertStatus(402)->assertJsonPath('code', 'plan_limit_exceeded');
        Queue::assertNothingPushed();
    }

    public function test_validation_errors(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser();
        $api = $this->actingAsTenantUser($user, $tenant);
        $api->postJson('/api/v1/customer/runs', [])->assertStatus(422)->assertJsonValidationErrors('operation');
        $api->postJson('/api/v1/customer/runs', ['operation' => 'plan'])->assertStatus(422)->assertJsonValidationErrors('operation');
        $api->postJson('/api/v1/customer/runs', ['operation' => 'train', 'horizons' => [14]])->assertStatus(422)->assertJsonValidationErrors('horizons.0');
        $api->postJson('/api/v1/customer/runs', ['operation' => 'predict', 'as_of' => '2999-01-01'])->assertStatus(422)->assertJsonValidationErrors('as_of');
        $api->postJson('/api/v1/customer/runs', ['operation' => 'train', 'as_of' => now()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('as_of');
        Queue::assertNothingPushed();
    }

    public function test_only_one_active_run_per_operation_but_train_and_predict_can_overlap(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser();
        $api = $this->actingAsTenantUser($user, $tenant);
        $api->postJson('/api/v1/customer/runs', ['operation' => 'train'])->assertStatus(202);
        $api->postJson('/api/v1/customer/runs', ['operation' => 'train'])->assertStatus(409)->assertJsonPath('code', 'customer_run_in_progress');
        $api->postJson('/api/v1/customer/runs', ['operation' => 'predict'])->assertStatus(202);
        $this->assertDatabaseCount('core_runs', 2);
    }

    public function test_permissions_viewer_reads_but_cannot_run(): void
    {
        Queue::fake();
        [$tenant, $owner] = $this->createTenantWithUser();
        $viewer = $this->addUserToTenant($tenant, TenantRole::Viewer);
        $this->actingAsTenantUser($viewer, $tenant)->postJson('/api/v1/customer/runs', ['operation' => 'train'])->assertStatus(403);
        $this->actingAsTenantUser($viewer, $tenant)->getJson('/api/v1/customer/runs')->assertOk();
        $this->actingAsTenantUser($owner, $tenant)->postJson('/api/v1/customer/runs', ['operation' => 'train'])->assertStatus(202);
        $this->app['auth']->forgetGuards();
        $this->withHeaders([])->getJson('/api/v1/customer/runs')->assertStatus(401);
    }

    public function test_listing_filters_and_company_isolation(): void
    {
        Queue::fake();
        [$a, $ua] = $this->createTenantWithUser();
        [$b, $ub] = $this->createTenantWithUser();
        $this->actingAsTenantUser($ua, $a)->postJson('/api/v1/customer/runs', ['operation' => 'train'])->assertStatus(202);
        $runB = $this->actingAsTenantUser($ub, $b)->postJson('/api/v1/customer/runs', ['operation' => 'predict'])->assertStatus(202)->json('data.id');

        $api = $this->actingAsTenantUser($ua, $a);
        $this->assertCount(1, $api->getJson('/api/v1/customer/runs')->assertOk()->json('data'));
        $this->assertCount(0, $api->getJson('/api/v1/customer/runs?operation=predict')->json('data'));
        $api->getJson('/api/v1/customer/runs/'.$runB)->assertStatus(404);                 // la corrida de B no existe para A
        $id = $api->getJson('/api/v1/customer/runs')->json('data.0.id');
        $api->getJson('/api/v1/customer/runs/'.$id)->assertOk()->assertJsonPath('data.envelope.tenant_id', $a->id)
            ->assertJsonPath('data.envelope.core', 'customer');
    }
}
