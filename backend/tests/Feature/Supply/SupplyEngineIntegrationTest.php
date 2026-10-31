<?php

declare(strict_types=1);

namespace Tests\Feature\Supply;

use App\Enums\TenantRole;
use App\Jobs\Middleware\SetTenantContext;
use App\Jobs\Supply\RunSupplyPlan;
use App\Models\CoreRun;
use App\Models\TrainingJob;
use App\Services\Forecasting\Exceptions\EngineServerException;
use App\Services\Supply\SupplyRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\TestCase;

/** Jobs y llamadas al motor (HTTP simulado): reintentos, no reintento ante datos insuficientes, idempotencia, escenarios y causalidad. */
class SupplyEngineIntegrationTest extends TestCase
{
    use AnalyticsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['engine.url' => 'http://engine.test', 'engine.token' => 'secret-engine-token', 'engine.retry_sleep_ms' => 0]);
    }

    private function queuedPlan(): array
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);
        $run = app(SupplyRunService::class)->request($tenant, $user, 'plan', ['objective' => 'min_cost']);
        $job = $this->withinTenant($tenant, fn () => TrainingJob::query()->where('core_run_id', $run->id)->firstOrFail());

        return [$tenant, $run, $job];
    }

    private function runJob($tenant, $run, $job): RunSupplyPlan
    {
        $pipeline = new RunSupplyPlan($tenant->id, $run->id, $job->id);
        $pipeline->withFakeQueueInteractions();
        app(SetTenantContext::class)->handle($pipeline, fn () => app()->call([$pipeline, 'handle']));

        return $pipeline;
    }

    public function test_job_calls_the_engine_with_tenant_run_and_token_and_marks_success(): void
    {
        [$tenant, $run, $job] = $this->queuedPlan();
        Http::fake(['engine.test/v1/supply/runs/*' => function ($request) use ($run, $tenant) {
            $this->withinTenant($tenant, fn () => CoreRun::query()->whereKey($run->id)->update(['status' => 'completed']));

            return Http::response(['run_id' => $run->id, 'status' => 'completed', 'quality_status' => 'ok'], 200);
        }]);
        $this->runJob($tenant, $run, $job);
        Http::assertSent(fn ($r) => $r->url() === "http://engine.test/v1/supply/runs/{$run->id}/execute"
            && $r->hasHeader('Authorization', 'Bearer secret-engine-token')
            && $r['tenant_id'] === $tenant->id && $r['training_job_id'] === $job->id);
        $this->withinTenant($tenant, function () use ($job): void {
            $j = TrainingJob::query()->findOrFail($job->id);
            $this->assertSame('succeeded', $j->status);
            $this->assertSame(1, $j->attempts);
        });
    }

    public function test_insufficient_data_is_not_retried_and_marks_the_run_failed(): void
    {
        [$tenant, $run, $job] = $this->queuedPlan();
        Http::fake(['engine.test/*' => Http::response(['detail' => ['code' => 'insufficient_data', 'message' => 'Sin pronóstico']], 422)]);
        $pipeline = $this->runJob($tenant, $run, $job);
        $pipeline->failed(new \RuntimeException('Sin pronóstico'));
        $this->withinTenant($tenant, function () use ($run, $job): void {
            $this->assertSame('failed', CoreRun::query()->findOrFail($run->id)->status);
            $this->assertSame('failed', TrainingJob::query()->findOrFail($job->id)->status);
        });
        Http::assertSentCount(1);
    }

    public function test_server_errors_are_retried_by_rethrowing(): void
    {
        [$tenant, $run, $job] = $this->queuedPlan();
        Http::fake(['engine.test/*' => Http::response(['message' => 'boom'], 500)]);
        $this->expectException(EngineServerException::class);
        $this->runJob($tenant, $run, $job);
    }

    public function test_completed_run_is_not_executed_again(): void
    {
        [$tenant, $run, $job] = $this->queuedPlan();
        $this->withinTenant($tenant, fn () => CoreRun::query()->whereKey($run->id)->update(['status' => 'completed']));
        Http::fake();
        $this->runJob($tenant, $run, $job);
        Http::assertNothingSent();
        $this->withinTenant($tenant, fn () => $this->assertSame('succeeded', TrainingJob::query()->findOrFail($job->id)->status));
    }

    public function test_scenario_evaluation_validates_inputs_and_returns_the_engine_result(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);
        $cat = $this->seedCatalog($tenant);
        $post = fn (array $b) => $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/supply/scenarios/evaluate', $b);
        $post([])->assertStatus(422);
        $post(['strategies' => [['key' => 'a b']]])->assertStatus(422);
        $post(['strategies' => [['key' => 'a'], ['key' => 'a']]])->assertStatus(422);
        $post(['strategies' => [['key' => 'a', 'price_change_pct' => 'mucho']]])->assertStatus(422);
        $post(['strategies' => [['key' => 'a', 'plan_run_id' => 999]]])->assertStatus(422);
        $post(['strategies' => [['key' => 'a', 'supply_fill_rate' => 1.5]]])->assertStatus(422);

        Http::fake(function ($request) use ($tenant) {
            $runId = (int) preg_replace('~.*/runs/(\d+)/execute~', '$1', $request->url());
            $this->withinTenant($tenant, function () use ($runId, $tenant): void {
                DB::table('scenario_evaluations')->insert([
                    'tenant_id' => $tenant->id, 'core_run_id' => $runId, 'horizon_days' => 90, 'request' => '{}', 'created_at' => now(), 'updated_at' => now(),
                    'results' => json_encode(['strategies' => [['key' => 'base']], 'assumed_inputs' => ['price_change']])]);
                CoreRun::query()->whereKey($runId)->update(['status' => 'completed', 'result_type' => 'conditional_simulation', 'quality_status' => 'ok']);
            });

            return Http::response(['run_id' => $runId, 'status' => 'completed'], 200);
        });
        $r = $post(['horizon_days' => 90, 'strategies' => [['key' => 'base', 'name' => 'Base'], ['key' => 'promo', 'price_change_pct' => [-0.1, -0.1, 0], 'demand_change_pct' => 0.1]],
            'filters' => ['product_id' => $cat['products']['p1']]])->assertStatus(201);
        $r->assertJsonPath('envelope.result_type', 'conditional_simulation')->assertJsonPath('data.results.assumed_inputs.0', 'price_change')
            ->assertJsonPath('envelope.core', 'scenario');
        $this->actingAsTenantUser($user, $tenant)->getJson('/api/v1/supply/scenarios/evaluations/'.$r->json('data.run_id'))->assertOk();
    }

    public function test_scenario_with_insufficient_data_is_a_422_not_a_fake_result(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);
        Http::fake(['engine.test/*' => Http::response(['detail' => ['code' => 'insufficient_data', 'message' => 'Sin pronóstico']], 422)]);
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/supply/scenarios/evaluate', ['strategies' => [['key' => 'base']]])->assertStatus(422);
        $this->withinTenant($tenant, fn () => $this->assertSame('failed', CoreRun::query()->where('core', 'scenario')->firstOrFail()->status));
    }

    public function test_causal_check_passes_through_insufficient_evidence_and_never_an_effect(): void
    {
        [$tenant, $user] = $this->createTenantWithUser(TenantRole::Analyst);
        $cat = $this->seedCatalog($tenant);
        Http::fake(['engine.test/v1/supply/causal/check' => Http::response([
            'tenant_id' => $tenant->id, 'core' => 'scenario', 'result_type' => 'causal_estimate', 'quality_status' => 'insufficient_evidence',
            'uncertainty_source' => 'none', 'run_id' => null, 'assumptions' => ['No hay estimador causal habilitado'], 'limitations' => ['price_variation:not_met'],
            'data' => ['verdict' => 'insufficient_evidence', 'effect' => null, 'estimator_enabled' => false, 'conditional_simulation' => ['available' => true]]], 200)]);
        $r = $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/supply/causal/check', ['product_id' => $cat['products']['p1'], 'treatment' => 'net_price'])->assertOk();
        $r->assertJsonPath('envelope.result_type', 'causal_estimate')->assertJsonPath('envelope.quality_status', 'insufficient_evidence')
            ->assertJsonPath('data.effect', null)->assertJsonPath('data.estimator_enabled', false)->assertJsonPath('data.conditional_simulation.available', true);
        Http::assertSent(fn ($req) => $req['tenant_id'] === $tenant->id);
        // un id de producto de otra empresa se rechaza antes de llamar al motor
        [$other] = $this->createTenantWithUser(TenantRole::Analyst);
        $foreign = $this->seedCatalog($other);
        $this->actingAsTenantUser($user, $tenant)->postJson('/api/v1/supply/causal/check', ['product_id' => $foreign['products']['p1']])->assertStatus(422);
    }
}
