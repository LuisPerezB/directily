<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Enums\TenantRole;
use App\Jobs\Customer\RunCustomerPrediction;
use App\Jobs\Customer\RunCustomerTraining;
use App\Jobs\Middleware\SetTenantContext;
use App\Models\CoreRun;
use App\Models\Tenant;
use App\Models\TrainingJob;
use App\Services\Customer\CustomerEngineClient;
use App\Services\Customer\CustomerRunService;
use App\Services\Forecasting\Exceptions\EngineServerException;
use App\Services\Forecasting\Exceptions\InsufficientDataException;
use App\Services\Forecasting\TrainingJobRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Customer\Support\CustomerFixtures;
use Tests\TestCase;

/** Jobs RunCustomerTraining / RunCustomerPrediction y los comandos programados (predicción diaria, entrenamiento semanal). */
class CustomerJobsAndSchedulingTest extends TestCase
{
    use CustomerFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['engine.url' => 'http://engine.test', 'engine.token' => 'secret-engine-token', 'engine.retry_sleep_ms' => 0]);
    }

    private function basicPlanId(): int
    {
        return (int) DB::table('plans')->insertGetId([
            'code' => 'basico', 'name' => 'Básico', 'limits' => json_encode(config('predictily.plans.basico.limits')), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0: Tenant, 1: CoreRun, 2: TrainingJob} */
    private function queued(string $operation = 'predict'): array
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser();
        $run = app(CustomerRunService::class)->request($tenant, $user, $operation);
        $job = $this->withinTenant($tenant, fn () => TrainingJob::query()->where('core_run_id', $run->id)->firstOrFail());

        return [$tenant, $run, $job];
    }

    private function runJob(Tenant $tenant, CoreRun $run, TrainingJob $job, string $class = RunCustomerPrediction::class): object
    {
        $queued = new $class($tenant->id, $run->id, $job->id);
        $queued->withFakeQueueInteractions();
        app(SetTenantContext::class)->handle($queued, fn ($j) => $j->handle(app(CustomerEngineClient::class), app(TrainingJobRecorder::class)));

        return $queued;
    }

    public function test_the_client_calls_the_customer_router_with_the_bearer_token(): void
    {
        Http::fake(['engine.test/*' => Http::response(['run_id' => 9, 'status' => 'completed'])]);
        $out = app(CustomerEngineClient::class)->execute(9, 3, 41);
        $this->assertSame('completed', $out['status']);
        /** @var Request $request */
        $request = Http::recorded()[0][0];
        $this->assertSame('http://engine.test/v1/customer/runs/9/execute', $request->url());
        $this->assertSame(['Bearer secret-engine-token'], $request->header('Authorization'));
        $this->assertSame(['tenant_id' => 3, 'training_job_id' => 41], $request->data());
    }

    public function test_the_job_executes_the_run_in_the_engine_and_closes_the_training_job(): void
    {
        [$tenant, $run, $job] = $this->queued('train');
        Http::fake(['engine.test/*' => Http::response(['run_id' => $run->id, 'status' => 'completed', 'operation' => 'train'])]);
        $this->runJob($tenant, $run, $job, RunCustomerTraining::class);
        Http::assertSentCount(1);
        $job->refresh();
        $this->assertSame(TrainingJob::STATUS_SUCCEEDED, $job->status);
        $this->assertSame(1, $job->attempts);
        // el motor no cerró la corrida: la defensa la completa
        $this->assertSame('completed', $this->withinTenant($tenant, fn () => CoreRun::query()->find($run->id)->status));
    }

    public function test_a_completed_run_is_not_executed_again(): void
    {
        [$tenant, $run, $job] = $this->queued();
        DB::table('core_runs')->where('id', $run->id)->update(['status' => 'completed']);
        Http::fake();
        $this->runJob($tenant, $run, $job);
        Http::assertNothingSent();
        $this->assertSame(TrainingJob::STATUS_SUCCEEDED, $job->fresh()->status);
    }

    public function test_a_job_whose_training_job_does_not_belong_to_the_run_does_nothing(): void
    {
        [$tenant, $run] = $this->queued();
        [$tenant2, $run2, $job2] = $this->queued();
        Http::fake();
        $queued = new RunCustomerPrediction($tenant->id, $run->id, $job2->id);   // trabajo de otra empresa/corrida
        $queued->withFakeQueueInteractions();
        app(SetTenantContext::class)->handle($queued, fn ($j) => $j->handle(app(CustomerEngineClient::class), app(TrainingJobRecorder::class)));
        Http::assertNothingSent();
        $this->assertSame('queued', DB::table('core_runs')->where('id', $run->id)->value('status'));
    }

    public function test_insufficient_data_is_not_retried_and_marks_the_run_failed_with_an_alert(): void
    {
        [$tenant, $run, $job] = $this->queued();
        Http::fake(['engine.test/*' => Http::response(['code' => 'insufficient_data', 'message' => 'Datos insuficientes'], 422)]);
        $queued = $this->runJob($tenant, $run, $job);
        $queued->failed(new InsufficientDataException('Datos insuficientes', 422));
        $this->assertSame('failed', DB::table('core_runs')->where('id', $run->id)->value('status'));
        $this->assertSame('failed', $job->fresh()->status);
        $this->assertDatabaseHas('alerts', ['tenant_id' => $tenant->id, 'type' => 'customer_run_failed']);
    }

    public function test_transient_engine_errors_are_retried(): void
    {
        [$tenant, $run, $job] = $this->queued();
        Http::fake(['engine.test/*' => Http::response(['message' => 'boom'], 500)]);
        $this->expectException(EngineServerException::class);
        $this->runJob($tenant, $run, $job);
    }

    // ── predicción diaria ──
    public function test_daily_prediction_runs_only_for_companies_with_a_production_model(): void
    {
        Queue::fake();
        [$with] = $this->createTenantWithUser();
        [$without] = $this->createTenantWithUser();
        $this->withinTenant($with, fn () => $this->registerProductionModel($with));

        $this->artisan('predictily:customer-predict', ['--dry-run' => true])->expectsOutputToContain('se programaría')
            ->expectsOutputToContain('sin modelo de comportamiento en producción')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->artisan('predictily:customer-predict')->expectsOutputToContain('Predicciones programadas: 1')->assertSuccessful();
        Queue::assertPushed(RunCustomerPrediction::class, 1);
        $this->assertDatabaseHas('core_runs', ['tenant_id' => $with->id, 'operation' => 'predict', 'triggered_by' => 'schedule', 'status' => 'queued']);
        $this->assertDatabaseMissing('core_runs', ['tenant_id' => $without->id]);
        // una segunda ejecución el mismo día no duplica
        $this->artisan('predictily:customer-predict')->expectsOutputToContain('ya hay una predicción activa')->expectsOutputToContain('Predicciones programadas: 0');
        DB::table('core_runs')->where('tenant_id', $with->id)->update(['status' => 'completed']);
        $this->artisan('predictily:customer-predict')->expectsOutputToContain('ya existe la predicción de hoy')->assertSuccessful();
        Queue::assertPushed(RunCustomerPrediction::class, 1);
    }

    public function test_daily_prediction_does_not_wait_for_new_sales_and_respects_the_plan_and_manual_mode(): void
    {
        Queue::fake();
        [$basic] = $this->createTenantWithUser(TenantRole::Owner, ['plan_id' => $this->basicPlanId()]);
        [$manual] = $this->createTenantWithUser(TenantRole::Owner, ['settings' => ['customer_schedule' => 'manual']]);
        [$nohorizon] = $this->createTenantWithUser(TenantRole::Owner, ['plan_overrides' => ['horizons' => []]]);
        foreach ([$basic, $manual, $nohorizon] as $t) {
            $this->withinTenant($t, fn () => $this->registerProductionModel($t));    // ninguna tiene ventas nuevas
        }
        $this->artisan('predictily:customer-predict')->expectsOutputToContain('programación manual')
            ->expectsOutputToContain('el plan no incluye horizontes')->expectsOutputToContain('Predicciones programadas: 1')->assertSuccessful();
        $run = CoreRun::withoutTenantScope()->where('tenant_id', $basic->id)->firstOrFail();
        $this->assertSame([7, 30], $run->horizons);        // plan Básico: 7/30; la recencia cambia aunque no haya ventas nuevas
    }

    // ── entrenamiento semanal ──
    public function test_weekly_training_requires_sales_and_skips_recent_or_unchanged_training(): void
    {
        Queue::fake();
        [$tenant] = $this->createTenantWithUser();
        $cat = $this->seedCustomerCatalog($tenant);
        $this->artisan('predictily:customer-train')->expectsOutputToContain('sin ventas')->expectsOutputToContain('Entrenamientos programados: 0')->assertSuccessful();

        $this->seedOneSale($tenant, $cat, '2026-09-30');
        $this->artisan('predictily:customer-train')->expectsOutputToContain('Entrenamientos programados: 1')->assertSuccessful();
        Queue::assertPushed(RunCustomerTraining::class, 1);
        $this->assertDatabaseHas('core_runs', ['tenant_id' => $tenant->id, 'operation' => 'train', 'triggered_by' => 'schedule']);

        $this->artisan('predictily:customer-train')->expectsOutputToContain('ya hay un entrenamiento activo')->assertSuccessful();
        DB::table('core_runs')->where('tenant_id', $tenant->id)->update(['status' => 'completed', 'as_of' => '2026-09-30', 'completed_at' => now()->subDays(10)]);
        $this->artisan('predictily:customer-train')->expectsOutputToContain('sin ventas nuevas desde el último entrenamiento')->assertSuccessful();
        $this->seedOneSale($tenant, $cat, '2026-10-02');
        DB::table('core_runs')->where('tenant_id', $tenant->id)->update(['completed_at' => now()->subDays(2)]);
        $this->artisan('predictily:customer-train')->expectsOutputToContain('hace menos de 7 días')->assertSuccessful();
        DB::table('core_runs')->where('tenant_id', $tenant->id)->update(['completed_at' => now()->subDays(8)]);
        $this->artisan('predictily:customer-train')->expectsOutputToContain('Entrenamientos programados: 1')->assertSuccessful();
        Queue::assertPushed(RunCustomerTraining::class, 2);
    }

    public function test_the_schedule_registers_both_commands(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('predictily:customer-train')->expectsOutputToContain('predictily:customer-predict')->assertSuccessful();
    }
}
