<?php

declare(strict_types=1);

namespace Tests\Unit\Ops;

use App\Support\Ops\OpsAlert;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

/** ADR-V26-01 · F5: una tarea programada o un trabajo de cola que falla genera una alerta operativa con código estable. */
final class OpsAlertTest extends TestCase
{
    public function test_a_failed_scheduled_task_is_logged_as_critical_with_a_stable_code(): void
    {
        Log::spy();

        app(OpsAlert::class)->scheduledTaskFailed("'/usr/local/bin/php' 'artisan' predictily:outcomes-measure", 1);

        Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message, array $context): bool => $context === [
            'ops_alert' => OpsAlert::SCHEDULED_TASK_FAILED,
            'task' => 'predictily:outcomes-measure',
            'exit_code' => 1,
        ]);
    }

    public function test_every_scheduled_task_raises_an_alert_when_it_exits_with_an_error(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $events = $this->app->make(Schedule::class)->events();
        $this->assertNotEmpty($events, 'No hay tareas programadas cargadas: la prueba dejó de vigilar algo.');
        Log::spy();

        foreach ($events as $event) {
            $event->finish($this->app, 1);
        }

        Log::shouldHaveReceived('critical')->times(count($events));
    }

    public function test_a_scheduled_task_that_succeeds_raises_nothing(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $events = $this->app->make(Schedule::class)->events();
        Log::spy();

        foreach ($events as $event) {
            $event->finish($this->app, 0);
        }

        Log::shouldNotHaveReceived('critical');
    }

    public function test_a_failed_queue_job_raises_an_alert_without_the_exception_text(): void
    {
        Log::spy();
        $job = Mockery::mock(Job::class)->shouldIgnoreMissing();
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\Imports\\CommitImport');
        $job->shouldReceive('getQueue')->andReturn('imports');
        $job->shouldReceive('getRawBody')->andReturn(json_encode([
            'data' => ['command' => 'O:29:"App\\Jobs\\Imports\\CommitImport":2:{s:8:"tenantId";i:42;s:8:"importId";i:7;}'],
        ]));

        event(new JobFailed('redis-long', $job, new RuntimeException('fila con la cédula 0102030405')));

        Log::shouldHaveReceived('critical')->once()->withArgs(function (string $message, array $context): bool {
            return $context === [
                'ops_alert' => OpsAlert::QUEUE_JOB_FAILED,
                'job' => 'App\\Jobs\\Imports\\CommitImport',
                'queue' => 'imports',
                'connection' => 'redis-long',
                'tenant_id' => 42,
                'exception' => RuntimeException::class,
            ] && ! str_contains($message, '0102030405');
        });
    }

    public function test_the_extra_channel_receives_the_same_alert_only_once_within_the_throttle_window(): void
    {
        config(['ops.alerts.log_channel' => 'ops-test', 'ops.alerts.throttle_seconds' => 900]);
        $channel = Mockery::spy(LoggerInterface::class);
        Log::spy()->shouldReceive('channel')->with('ops-test')->andReturn($channel);
        $alert = app(OpsAlert::class);

        $alert->jobFailed('App\\Jobs\\Forecasting\\RunForecastPipeline', 'forecasting', 'redis', 1, RuntimeException::class);
        $alert->jobFailed('App\\Jobs\\Forecasting\\RunForecastPipeline', 'forecasting', 'redis', 2, RuntimeException::class);
        $alert->jobFailed('App\\Jobs\\Imports\\CommitImport', 'imports', 'redis-long', 1, RuntimeException::class);

        Log::shouldHaveReceived('critical')->times(3);        // el registro de la aplicación las recibe todas
        $channel->shouldHaveReceived('critical')->times(2);   // el canal adicional, una por origen
    }

    public function test_a_broken_extra_channel_never_breaks_the_caller(): void
    {
        config(['ops.alerts.log_channel' => 'ops-test']);
        Log::spy()->shouldReceive('channel')->with('ops-test')->andThrow(new RuntimeException('webhook caído'));

        app(OpsAlert::class)->scheduledTaskFailed('predictily:purge', 1);

        Log::shouldHaveReceived('critical')->once();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_tenant_id_is_read_from_the_payload_without_unserializing_it(): void
    {
        $payload = fn (string $command): string => (string) json_encode(['data' => ['command' => $command]]);

        $this->assertSame(42, OpsAlert::tenantIdFromPayload($payload('O:8:"Some\\Job":1:{s:8:"tenantId";i:42;}')));
        $this->assertNull(OpsAlert::tenantIdFromPayload($payload('O:8:"Some\\Job":0:{}')));
        $this->assertNull(OpsAlert::tenantIdFromPayload('no es json'));
    }
}
