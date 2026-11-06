<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Jobs\Pipeline\RunRadarStage;
use App\Models\CoreRun;
use App\Models\PipelineStage;
use App\Services\Pipeline\DailyPipeline;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Radar\Support\RadarFixtures;
use Tests\TestCase;

/**
 * ADR-V26-01 · F1: el radar depende del ESTADO de la predicción de clientes de hoy, no de la hora. El escenario de partida es el que antes pasaba en silencio:
 * la predicción de AYER está completada y la de HOY todavía no (sigue en cola, en curso o falló).
 */
class RadarPipelineGateTest extends TestCase
{
    use RadarFixtures;
    use RefreshDatabase;

    /** Empresa con la predicción de ayer completada y la de hoy en el estado indicado. @return array{tenant: \App\Models\Tenant, yesterday: int, today: int} */
    private function companyWithTodaysPrediction(string $status): array
    {
        $c = $this->radarCompany();
        DB::table('core_runs')->where('id', $c['run'])->update(['created_at' => now()->subDay()]);
        $today = $this->seedPredictionRun($c['tenant'], $c['cat'], [7, 30, 90], '2026-10-02', false);
        DB::table('core_runs')->where('id', $today)->update(['status' => $status, 'completed_at' => $status === 'completed' ? now() : null]);
        if ($status === 'completed') {
            // Como hace el motor al cerrar una predicción: la nueva pasa a ser la vigente (solo puede haber una).
            DB::table('core_runs')->where('id', $c['run'])->update(['is_current' => false]);
            DB::table('core_runs')->where('id', $today)->update(['is_current' => true]);
        }

        return ['tenant' => $c['tenant'], 'yesterday' => $c['run'], 'today' => $today];
    }

    private function recommendations(int $tenantId): int
    {
        return DB::table('decision_recommendations')->where('tenant_id', $tenantId)->count();
    }

    private function stage(int $tenantId, string $stage): ?object
    {
        return DB::table('pipeline_stages')->where('tenant_id', $tenantId)->where('stage', $stage)->first();
    }

    public function test_the_radar_waits_while_todays_prediction_is_running_instead_of_using_yesterdays(): void
    {
        $c = $this->companyWithTodaysPrediction('running');
        $this->fakeSink();

        $this->artisan('predictily:radar-materialize')->assertExitCode(0)->expectsOutputToContain('la predicción de hoy sigue en curso');

        $this->assertSame(0, $this->recommendations($c['tenant']->id), 'no se propone con la predicción de ayer');
        $stage = $this->stage($c['tenant']->id, PipelineStage::RADAR);
        $this->assertSame([PipelineStage::WAITING, PipelineStage::CUSTOMER_PREDICT, 'prediction_in_progress'], [$stage->status, $stage->blocked_by, $stage->reason]);
    }

    public function test_the_radar_is_blocked_with_a_visible_reason_when_todays_prediction_failed_and_other_companies_go_on(): void
    {
        $broken = $this->companyWithTodaysPrediction('failed');
        $healthy = $this->radarCompany();
        $this->fakeSink();

        $this->artisan('predictily:radar-materialize')->assertExitCode(1)->expectsOutputToContain('bloqueado, la predicción de hoy falló');

        $this->assertSame(0, $this->recommendations($broken['tenant']->id));
        $stage = $this->stage($broken['tenant']->id, PipelineStage::RADAR);
        $this->assertSame([PipelineStage::BLOCKED, PipelineStage::CUSTOMER_PREDICT, 'prediction_failed'], [$stage->status, $stage->blocked_by, $stage->reason]);

        // El fallo de una empresa no detiene a la otra.
        $this->assertSame(12, $this->recommendations($healthy['tenant']->id));
        $this->assertSame(PipelineStage::SUCCEEDED, $this->stage($healthy['tenant']->id, PipelineStage::RADAR)->status);
    }

    public function test_force_proposes_with_the_current_prediction_even_if_todays_is_not_ready(): void
    {
        $c = $this->companyWithTodaysPrediction('running');
        $this->fakeSink();

        $this->artisan('predictily:radar-materialize', ['--force' => true])->assertExitCode(0);

        $this->assertSame(12, $this->recommendations($c['tenant']->id));
        $stage = $this->stage($c['tenant']->id, PipelineStage::RADAR);
        $this->assertSame([PipelineStage::SUCCEEDED, 'no_prediction_today', 'core_run:'.$c['yesterday']], [$stage->status, $stage->reason, $stage->source_ref]);
    }

    public function test_when_todays_prediction_finishes_the_radar_of_that_company_is_launched_and_uses_it(): void
    {
        Queue::fake();
        $c = $this->companyWithTodaysPrediction('completed');
        $this->fakeSink();

        $this->app->make(TenantContext::class)->runAs($c['tenant'], fn () => app(DailyPipeline::class)->customerPredictionFinished(CoreRun::query()->findOrFail($c['today'])));

        Queue::assertPushed(RunRadarStage::class, fn (RunRadarStage $job) => $job->tenantId === $c['tenant']->id);
        $this->assertSame(PipelineStage::SUCCEEDED, $this->stage($c['tenant']->id, PipelineStage::CUSTOMER_PREDICT)->status);

        (new RunRadarStage($c['tenant']->id))->handle();

        $stage = $this->stage($c['tenant']->id, PipelineStage::RADAR);
        $this->assertSame([PipelineStage::SUCCEEDED, null, 'core_run:'.$c['today']], [$stage->status, $stage->reason, $stage->source_ref]);
        $this->assertTrue(json_decode($stage->detail, true)['prediction_from_today']);
        $this->assertGreaterThan(0, $this->recommendations($c['tenant']->id));
    }

    public function test_when_todays_prediction_fails_the_radar_of_that_company_ends_blocked_not_stale(): void
    {
        Queue::fake();
        $c = $this->companyWithTodaysPrediction('failed');
        $this->fakeSink();

        $this->app->make(TenantContext::class)->runAs($c['tenant'], fn () => app(DailyPipeline::class)->customerPredictionFinished(CoreRun::query()->findOrFail($c['today'])));

        $predict = $this->stage($c['tenant']->id, PipelineStage::CUSTOMER_PREDICT);
        $this->assertSame([PipelineStage::FAILED, 'prediction_failed', 'core_run:'.$c['today']], [$predict->status, $predict->reason, $predict->source_ref]);
        Queue::assertPushed(RunRadarStage::class);

        (new RunRadarStage($c['tenant']->id))->handle();

        $this->assertSame(PipelineStage::BLOCKED, $this->stage($c['tenant']->id, PipelineStage::RADAR)->status);
        $this->assertSame(0, $this->recommendations($c['tenant']->id));
    }

    public function test_a_company_with_no_prediction_requested_today_keeps_working_with_the_current_one_and_says_so(): void
    {
        $c = $this->radarCompany();
        DB::table('core_runs')->where('id', $c['run'])->update(['created_at' => now()->subDays(3)]);   // p. ej. programación manual
        $this->fakeSink();

        $this->artisan('predictily:radar-materialize')->assertExitCode(0);

        $this->assertSame(12, $this->recommendations($c['tenant']->id));
        $stage = $this->stage($c['tenant']->id, PipelineStage::RADAR);
        $this->assertSame([PipelineStage::SUCCEEDED, 'no_prediction_today'], [$stage->status, $stage->reason]);
    }
}
