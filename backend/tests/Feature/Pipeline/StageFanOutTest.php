<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Jobs\Pipeline\RunDecisionsStage;
use App\Jobs\Pipeline\RunOutcomesStage;
use App\Jobs\Pipeline\RunRadarStage;
use App\Models\PipelineStage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * ADR-V26-01 · F4: radar, decisiones y medición de resultados se ejecutan como un trabajo por empresa. El planificador solo encola
 * (`--queue`); sin esa opción los comandos siguen ejecutando en línea, como los usa quien opera.
 */
class StageFanOutTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    /** @return array<string, array{string, class-string}> */
    public static function stages(): array
    {
        return [
            'radar' => ['predictily:radar-materialize', RunRadarStage::class],
            'decisiones' => ['predictily:decisions-generate', RunDecisionsStage::class],
            'resultados' => ['predictily:outcomes-measure', RunOutcomesStage::class],
        ];
    }

    /** @param class-string $job */
    #[DataProvider('stages')]
    public function test_with_queue_the_command_enqueues_one_job_per_company_and_runs_nothing_itself(string $command, string $job): void
    {
        Queue::fake();
        config(['ops.pipeline.queue' => 'batch']);
        [$a] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();

        $this->artisan($command, ['--queue' => true])->assertExitCode(0)->expectsOutputToContain('Empresas encoladas');

        Queue::assertPushedOn('batch', $job, fn ($queued) => $queued->tenantId === $a->id);
        Queue::assertPushedOn('batch', $job, fn ($queued) => $queued->tenantId === $b->id);
        $this->assertSame(0, DB::table('pipeline_stages')->count(), 'encolar no ejecuta la etapa');
    }

    #[DataProvider('stages')]
    public function test_with_queue_and_a_single_company_only_that_company_is_enqueued(string $command, string $job): void
    {
        Queue::fake();
        [$a] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();

        $this->artisan($command, ['--queue' => true, '--tenant' => $a->id])->assertExitCode(0);

        Queue::assertPushed($job, fn ($queued) => $queued->tenantId === $a->id);
        Queue::assertNotPushed($job, fn ($queued) => $queued->tenantId === $b->id);
    }

    #[DataProvider('stages')]
    public function test_queue_cannot_be_combined_with_dry_run(string $command, string $job): void
    {
        Queue::fake();
        $this->createTenantWithUser();

        $this->artisan($command, ['--queue' => true, '--dry-run' => true])->assertExitCode(2);

        Queue::assertNotPushed($job);
    }

    public function test_without_a_dedicated_queue_the_jobs_go_to_the_default_queue_that_every_deployment_has(): void
    {
        config(['ops.pipeline.queue' => null]);
        $this->assertSame('default', (new RunDecisionsStage(1))->queue);

        config(['ops.pipeline.queue' => 'batch']);
        $this->assertSame('batch', (new RunRadarStage(1))->queue);
        $this->assertSame('batch', (new RunOutcomesStage(1))->queue);
    }

    public function test_the_scheduler_enqueues_instead_of_running_the_three_stages_inline(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => (string) $event->command);

        foreach (['predictily:radar-materialize', 'predictily:decisions-generate', 'predictily:outcomes-measure'] as $command) {
            $this->assertTrue($commands->contains(fn (string $line) => str_contains($line, $command.' --queue')), "{$command} se programa con --queue");
        }
    }

    public function test_the_decisions_and_outcomes_jobs_run_the_stage_for_their_company_and_leave_a_record(): void
    {
        [$tenant] = $this->createTenantWithUser();
        [$other] = $this->createTenantWithUser();

        (new RunDecisionsStage($tenant->id))->handle();
        (new RunOutcomesStage($tenant->id))->handle();

        $stages = DB::table('pipeline_stages')->where('tenant_id', $tenant->id)->pluck('status', 'stage')->all();
        $this->assertSame([PipelineStage::DECISIONS => PipelineStage::SUCCEEDED, PipelineStage::OUTCOMES => PipelineStage::SUCCEEDED], $stages);
        $this->assertSame(0, DB::table('pipeline_stages')->where('tenant_id', $other->id)->count(), 'el trabajo de una empresa no toca a otra');
    }

    public function test_a_job_for_a_company_that_is_no_longer_active_does_nothing(): void
    {
        (new RunDecisionsStage(999_999))->handle();

        $this->assertSame(0, DB::table('pipeline_stages')->count());
    }
}
