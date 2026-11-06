<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Jobs\Forecasting\EvaluateActuals;
use App\Jobs\Forecasting\RunForecastPipeline;
use App\Jobs\Middleware\WaitForEngine;
use App\Services\Forecasting\EngineClient;
use App\Services\Forecasting\Exceptions\EngineServerException;
use App\Services\Forecasting\Exceptions\EngineUnavailableException;
use App\Services\Forecasting\Support\EngineCircuit;
use App\Support\Ops\OpsAlert;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ADR-V26-01 · F6: con el motor caído las llamadas fallan al instante y los trabajos se aplazan sin gastar intentos; cuando el motor
 * vuelve, todo se reanuda solo.
 */
final class EngineCircuitTest extends TestCase
{
    private const REFUSED = 'cURL error 7: Failed to connect to engine port 8100: Connection refused';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'engine.url' => 'http://engine.test', 'engine.connection_retries' => 0, 'engine.retry_sleep_ms' => 0,
            'engine.circuit.enabled' => true, 'engine.circuit.failure_threshold' => 2, 'engine.circuit.open_seconds' => 60, 'engine.circuit.max_deferrals' => 3,
        ]);
    }

    private function circuit(): EngineCircuit
    {
        return app(EngineCircuit::class);
    }

    /** Abre el cortacircuitos como lo haría el motor caído: llamadas seguidas que no logran conectar. */
    private function open(): void
    {
        $this->circuit()->recordFailure(new ConnectionException(self::REFUSED));
        $this->circuit()->recordFailure(new ConnectionException(self::REFUSED));
        $this->assertTrue($this->circuit()->isOpen());
    }

    public function test_it_opens_after_consecutive_connection_failures_then_fails_fast_without_touching_the_network_and_alerts_once(): void
    {
        Log::spy();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException(self::REFUSED);
        });
        $client = app(EngineClient::class);

        foreach ([1, 2] as $ignored) {
            try {
                $client->readiness(1);
                $this->fail('Se esperaba EngineUnavailableException');
            } catch (EngineUnavailableException $e) {
                $this->assertNull($e->errorCode, 'fallo real de conexión, no del cortacircuitos');
            }
        }
        $this->assertSame(2, $calls);
        $this->assertTrue($this->circuit()->isOpen());

        try {
            $client->readiness(1);
            $this->fail('Se esperaba EngineUnavailableException');
        } catch (EngineUnavailableException $e) {
            $this->assertSame(EngineCircuit::OPEN_CODE, $e->errorCode);
            $this->assertTrue($e->isRetryable());
        }
        $this->assertSame(2, $calls, 'abierto: no se intenta conectar');
        Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message, array $context): bool => $context['ops_alert'] === OpsAlert::ENGINE_UNAVAILABLE);
    }

    public function test_the_health_check_passes_while_open_and_a_response_from_the_engine_closes_it(): void
    {
        $down = true;
        Http::fake(fn () => $down ? throw new ConnectionException(self::REFUSED) : Http::response(['status' => 'ok']));
        $client = app(EngineClient::class);
        $this->open();

        $down = false;
        $this->assertSame(['status' => 'ok'], $client->health(), '/health no se bloquea: es la comprobación');
        $this->assertFalse($this->circuit()->isOpen());
        $this->assertSame(['status' => 'ok'], $client->readiness(1));
    }

    public function test_a_read_timeout_or_an_http_error_does_not_open_it_because_the_engine_is_there(): void
    {
        $timeout = new ConnectionException('cURL error 28: Operation timed out after 900001 milliseconds with 0 bytes received');
        foreach ([1, 2, 3, 4] as $ignored) {
            $this->circuit()->recordFailure($timeout);
        }
        $this->assertFalse($this->circuit()->isOpen(), 'un entrenamiento largo no es un motor caído');

        Http::fake(fn () => Http::response(['detail' => 'boom'], 500));
        foreach ([1, 2, 3] as $ignored) {
            try {
                app(EngineClient::class)->readiness(1);
                $this->fail('Se esperaba EngineServerException');
            } catch (EngineServerException) {
                // el motor respondió
            }
        }
        $this->assertFalse($this->circuit()->isOpen());
    }

    public function test_disabled_it_never_opens(): void
    {
        config(['engine.circuit.enabled' => false]);
        foreach ([1, 2, 3, 4] as $ignored) {
            $this->circuit()->recordFailure(new ConnectionException(self::REFUSED));
        }

        $this->assertFalse($this->circuit()->isOpen());
    }

    public function test_while_open_an_engine_job_is_deferred_as_a_fresh_copy_instead_of_running_or_spending_attempts(): void
    {
        Queue::fake();
        $this->open();
        $ran = false;

        (new WaitForEngine)->handle(new RunForecastPipeline(7, 8, 9), function () use (&$ran): void {
            $ran = true;
        });

        $this->assertFalse($ran, 'no se ejecuta contra un motor caído');
        Queue::assertPushed(RunForecastPipeline::class, fn (RunForecastPipeline $copy): bool => $copy->engineDeferrals === 1
            && [$copy->tenantId, $copy->forecastRunId, $copy->trainingJobId] === [7, 8, 9]
            && $copy->delay === 60 && $copy->tries === 2);
    }

    public function test_after_the_maximum_deferrals_the_job_runs_and_fails_the_normal_way(): void
    {
        Queue::fake();
        $this->open();
        $job = new RunForecastPipeline(7, 8, 9);
        $job->engineDeferrals = 3;
        $ran = false;

        (new WaitForEngine)->handle($job, function () use (&$ran): void {
            $ran = true;
        });

        $this->assertTrue($ran);
        Queue::assertNothingPushed();
    }

    public function test_while_open_a_unique_job_goes_back_to_the_queue_with_a_delay(): void
    {
        Queue::fake();
        $this->open();
        $job = (new EvaluateActuals(7))->withFakeQueueInteractions();
        $ran = false;

        (new WaitForEngine)->handle($job, function () use (&$ran): void {
            $ran = true;
        });

        $this->assertFalse($ran);
        $job->assertReleased(60);
        Queue::assertNothingPushed();
    }

    public function test_while_closed_jobs_run_normally(): void
    {
        Queue::fake();
        $ran = false;

        (new WaitForEngine)->handle(new RunForecastPipeline(7, 8, 9), function () use (&$ran): void {
            $ran = true;
        });

        $this->assertTrue($ran);
        Queue::assertNothingPushed();
    }
}
