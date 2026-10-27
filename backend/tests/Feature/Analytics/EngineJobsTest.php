<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Jobs\Forecasting\EvaluateActuals;
use App\Jobs\Forecasting\RefreshReadiness;
use App\Jobs\Forecasting\RunForecastPipeline;
use App\Models\Alert;
use App\Models\ForecastRun;
use App\Models\Tenant;
use App\Models\TrainingJob;
use App\Services\Forecasting\EngineClient;
use App\Services\Forecasting\Exceptions\EngineAuthenticationException;
use App\Services\Forecasting\Exceptions\EngineServerException;
use App\Services\Forecasting\Exceptions\EngineUnavailableException;
use App\Services\Forecasting\Exceptions\InsufficientDataException;
use App\Services\Forecasting\ForecastRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Analytics\Support\AnalyticsFixtures;
use Tests\TestCase;

class EngineJobsTest extends TestCase
{
    use AnalyticsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'engine.url' => 'http://engine.test',
            'engine.token' => 'secret-engine-token',
            'engine.retry_sleep_ms' => 0,
        ]);
    }

    /** @return array{0: Tenant, 1: ForecastRun, 2: TrainingJob} */
    private function queuedRun(?string $cutoff = null): array
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser();
        $run = app(ForecastRunService::class)->request($tenant, $user, 90, $cutoff);
        $job = $this->withinTenant($tenant, fn () => TrainingJob::query()->where('forecast_run_id', $run->id)->firstOrFail());

        return [$tenant, $run, $job];
    }

    private function runJob(Tenant $tenant, ForecastRun $run, TrainingJob $job): RunForecastPipeline
    {
        $pipeline = new RunForecastPipeline($tenant->id, $run->id, $job->id);
        $pipeline->withFakeQueueInteractions();
        app(\App\Jobs\Middleware\SetTenantContext::class)->handle(
            $pipeline,
            fn ($j) => $j->handle(app(EngineClient::class), app(\App\Services\Forecasting\TrainingJobRecorder::class)),
        );

        return $pipeline;
    }

    public function test_engine_client_sends_bearer_token_and_contract_payload(): void
    {
        Http::fake(['engine.test/*' => Http::response(['run_id' => 5, 'status' => 'completed'])]);

        $result = app(EngineClient::class)->executeRun(5, 3, 41, [7, 30, 90], '2026-01-31', ['seed' => 42]);

        $this->assertSame('completed', $result['status']);
        $recorded = Http::recorded();
        $this->assertCount(1, $recorded);
        /** @var Request $request */
        $request = $recorded[0][0];
        $this->assertSame('http://engine.test/v1/forecast-runs/5/execute', $request->url());
        $this->assertSame(['Bearer secret-engine-token'], $request->header('Authorization'));
        $this->assertSame([
            'tenant_id' => 3, 'training_job_id' => 41, 'horizons' => [7, 30, 90],
            'cutoff_date' => '2026-01-31', 'options' => ['seed' => 42],
        ], json_decode($request->body(), true));
    }

    public function test_engine_client_maps_errors_to_typed_exceptions(): void
    {
        Http::fakeSequence('engine.test/*')
            ->push(['detail' => ['code' => 'insufficient_data', 'message' => 'Solo hay 5 días de ventas']], 422)
            ->push(['detail' => 'boom'], 500)
            ->push(['detail' => 'Unauthorized'], 401);
        $client = app(EngineClient::class);

        try {
            $client->readiness(1);
            $this->fail('Se esperaba InsufficientDataException');
        } catch (InsufficientDataException $e) {
            $this->assertSame('insufficient_data', $e->errorCode);
            $this->assertSame('Solo hay 5 días de ventas', $e->getMessage());
            $this->assertFalse($e->isRetryable());
        }
        try {
            $client->readiness(1);
            $this->fail('Se esperaba EngineServerException');
        } catch (EngineServerException $e) {
            $this->assertTrue($e->isRetryable());
        }
        $this->expectException(EngineAuthenticationException::class);
        $client->readiness(1);
    }

    public function test_engine_client_retries_only_connection_errors(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response(['status' => 'ok']);
        });
        config(['engine.connection_retries' => 2]);

        $this->assertSame(['status' => 'ok'], app(EngineClient::class)->health());
        $this->assertSame(3, $attempts);

    }

    public function test_engine_client_raises_unavailable_after_exhausting_connection_retries(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Connection refused');
        });
        config(['engine.connection_retries' => 2]);

        try {
            app(EngineClient::class)->health();
            $this->fail('Se esperaba EngineUnavailableException');
        } catch (EngineUnavailableException $e) {
            $this->assertSame(3, $attempts);
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_pipeline_success_marks_job_succeeded_and_run_completed(): void
    {
        [$tenant, $run, $job] = $this->queuedRun();
        Http::fake(['engine.test/*' => Http::response(['run_id' => $run->id, 'status' => 'completed', 'series_count' => 6])]);

        $this->runJob($tenant, $run, $job);

        $this->withinTenant($tenant, function () use ($run, $job) {
            $job->refresh();
            $this->assertSame('succeeded', $job->status);
            $this->assertSame(100, (int) $job->progress);
            $this->assertSame(1, (int) $job->attempts);
            $this->assertSame(6, $job->result['series_count']);
            $this->assertSame('completed', $run->refresh()->status);
        });
        Http::assertSentCount(1);
    }

    public function test_pipeline_does_not_call_engine_for_completed_runs(): void
    {
        [$tenant, $run, $job] = $this->queuedRun();
        $this->withinTenant($tenant, fn () => $run->forceFill(['status' => 'completed'])->save());
        Http::fake();

        $this->runJob($tenant, $run, $job);

        Http::assertNothingSent();
    }

    public function test_insufficient_data_fails_without_retry_and_raises_alert(): void
    {
        [$tenant, $run, $job] = $this->queuedRun();
        Http::fake(['engine.test/*' => Http::response(['detail' => ['code' => 'insufficient_data', 'message' => 'Se requieren al menos 28 días de ventas.']], 422)]);

        $pipeline = $this->runJob($tenant, $run, $job);
        $pipeline->assertFailed();
        // El worker invoca failed() tras $this->fail(); lo simulamos.
        $pipeline->failed(new InsufficientDataException('Se requieren al menos 28 días de ventas.', 422));

        $this->withinTenant($tenant, function () use ($run, $job) {
            $this->assertSame('failed', $run->refresh()->status);
            $this->assertSame('Se requieren al menos 28 días de ventas.', $run->error_message);
            $this->assertSame('failed', $job->refresh()->status);
            $this->assertSame(1, Alert::query()->where('type', 'run_failed')->count());
        });
    }

    public function test_server_errors_are_rethrown_for_retry_and_failed_hook_closes_run(): void
    {
        [$tenant, $run, $job] = $this->queuedRun();
        Http::fake(['engine.test/*' => Http::response(['detail' => 'Error inesperado'], 500)]);

        try {
            $this->runJob($tenant, $run, $job);
            $this->fail('Se esperaba EngineServerException');
        } catch (EngineServerException) {
            $this->withinTenant($tenant, fn () => $this->assertSame('queued', $run->refresh()->status));
        }

        (new RunForecastPipeline($tenant->id, $run->id, $job->id))->failed(new EngineServerException('Error inesperado', 500));
        (new RunForecastPipeline($tenant->id, $run->id, $job->id))->failed(new EngineServerException('Error inesperado', 500));

        $this->withinTenant($tenant, function () use ($run) {
            $this->assertSame('failed', $run->refresh()->status);
            // Alerta deduplicada aunque failed() se invoque dos veces.
            $this->assertSame(1, Alert::query()->where('dedup_key', "run_failed:{$run->id}")->count());
        });
    }

    public function test_connection_errors_are_retryable(): void
    {
        [$tenant, $run, $job] = $this->queuedRun();
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->expectException(EngineUnavailableException::class);
        $this->runJob($tenant, $run, $job);
    }

    public function test_pipeline_job_configuration(): void
    {
        config(['engine.timeout' => 900]);
        $job = new RunForecastPipeline(1, 2, 3);

        $this->assertSame(2, $job->tries);
        $this->assertSame(960, $job->timeout);
        $this->assertSame('forecasting', $job->queue);
        $this->assertSame([60, 180], $job->backoff());
    }

    public function test_readiness_job_records_training_job(): void
    {
        [$tenant] = $this->createTenantWithUser();
        Http::fake(['engine.test/*' => Http::response(['tenant_id' => $tenant->id, 'score' => 78.5])]);

        $job = new RefreshReadiness($tenant->id);
        app(\App\Jobs\Middleware\SetTenantContext::class)->handle($job, fn ($j) => $j->handle(app(EngineClient::class), app(\App\Services\Forecasting\TrainingJobRecorder::class)));

        $this->withinTenant($tenant, function () {
            $training = TrainingJob::query()->where('job_type', 'readiness')->firstOrFail();
            $this->assertSame('succeeded', $training->status);
            $this->assertSame(78.5, $training->result['score']);
        });
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), "/v1/tenants/{$tenant->id}/readiness"));
    }

    public function test_evaluate_actuals_job_marks_failure_on_engine_error(): void
    {
        [$tenant] = $this->createTenantWithUser();
        Http::fake(['engine.test/*' => Http::response(['detail' => 'boom'], 500)]);

        $job = new EvaluateActuals($tenant->id);
        try {
            app(\App\Jobs\Middleware\SetTenantContext::class)->handle($job, fn ($j) => $j->handle(app(EngineClient::class), app(\App\Services\Forecasting\TrainingJobRecorder::class)));
            $this->fail('Se esperaba EngineServerException');
        } catch (EngineServerException) {
        }

        $this->withinTenant($tenant, function () {
            $training = TrainingJob::query()->where('job_type', 'evaluate_actuals')->firstOrFail();
            $this->assertSame('failed', $training->status);
        });
    }

    public function test_jobs_carry_tenant_and_use_tenant_middleware(): void
    {
        foreach ([new RunForecastPipeline(7, 1, 1), new RefreshReadiness(7), new EvaluateActuals(7)] as $job) {
            $this->assertSame(7, $job->tenantId);
            $this->assertInstanceOf(\App\Jobs\Middleware\SetTenantContext::class, $job->middleware()[0]);
        }
    }
}
