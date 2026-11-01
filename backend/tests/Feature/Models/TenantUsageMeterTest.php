<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Jobs\Customer\RunCustomerTraining;
use App\Jobs\Forecasting\RunForecastPipeline;
use App\Jobs\Middleware\SetTenantContext;
use App\Models\CoreRun;
use App\Models\ForecastRun;
use App\Models\Tenant;
use App\Models\TrainingJob;
use App\Services\Customer\CustomerEngineClient;
use App\Services\Customer\CustomerRunService;
use App\Services\Forecasting\EngineClient;
use App\Services\Forecasting\ForecastRunService;
use App\Services\Forecasting\TrainingJobRecorder;
use App\Services\Models\Usage\TenantUsageMeter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Medición de uso por empresa (`tenant_usage_records`; V25-REG, ADR-V25-09 §14): segundos de entrenamiento e inferencia que ya reportan las
 * ejecuciones del motor, idempotente, aislada por empresa y SIN precios.
 */
class TenantUsageMeterTest extends TestCase
{
    use InteractsWithTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['engine.url' => 'http://engine.test', 'engine.token' => 'secret-engine-token', 'engine.retry_sleep_ms' => 0]);
    }

    private function meter(): TenantUsageMeter
    {
        return app(TenantUsageMeter::class);
    }

    /** @return array<string, array{quantity: float, unit: string}> métrica => cantidad de las filas de la fuente y referencia */
    private function rows(Tenant $tenant, string $source, string|int $ref): array
    {
        return DB::table('tenant_usage_records')->where('tenant_id', $tenant->id)->where('source', $source)->where('source_ref', (string) $ref)->orderBy('metric')->get()
            ->mapWithKeys(fn ($r) => [$r->metric => ['quantity' => (float) $r->quantity, 'unit' => $r->unit]])->all();
    }

    /** @param array<string, mixed> $summary */
    private function demandRun(Tenant $tenant, array $summary, string $status = 'completed', ?string $completedAt = '2026-11-02 03:30:00'): ForecastRun
    {
        $id = (int) DB::table('forecast_runs')->insertGetId([
            'tenant_id' => $tenant->id, 'version' => (int) DB::table('forecast_runs')->where('tenant_id', $tenant->id)->max('version') + 1, 'run_type' => 'production',
            'status' => $status, 'triggered_by' => 'user', 'horizon_days' => 30, 'frequency' => 'D', 'summary' => json_encode($summary), 'is_current' => false,
            'completed_at' => $completedAt, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->withinTenant($tenant, fn () => ForecastRun::query()->findOrFail($id));
    }

    /** @param array<string, mixed> $summary */
    private function coreRun(Tenant $tenant, string $operation, array $summary, string $status = 'completed'): CoreRun
    {
        $id = (int) DB::table('core_runs')->insertGetId([
            'tenant_id' => $tenant->id, 'core' => 'customer', 'operation' => $operation, 'version' => (int) DB::table('core_runs')->where('tenant_id', $tenant->id)->max('version') + 1,
            'run_type' => 'production', 'status' => $status, 'options' => '{}', 'assumptions' => '[]', 'limitations' => '[]', 'summary' => json_encode($summary),
            'triggered_by' => 'user', 'completed_at' => '2026-11-01 15:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->withinTenant($tenant, fn () => CoreRun::query()->findOrFail($id));
    }

    public function test_a_demand_run_records_training_inference_and_wall_seconds_from_what_the_engine_reported(): void
    {
        [$tenant] = $this->createTenantWithUser();
        $run = $this->demandRun($tenant, [
            'timings_seconds' => ['profiling' => 1.0, 'backtesting' => 40.5, 'training' => 12.25, 'reconciling' => 3.0, 'shadow' => 4.0, 'total' => 61.0, 'loading_data' => 2.0],
            'duration_ms' => 63500,
        ]);

        $recorded = $this->meter()->recordForecastRun($run);

        $this->assertCount(4, $recorded);
        $rows = $this->rows($tenant, 'forecast_run', $run->id);
        $this->assertSame(['inference_cpu_seconds' => ['quantity' => 3.0, 'unit' => 's'], 'run_wall_seconds' => ['quantity' => 63.5, 'unit' => 's'],
            'runs' => ['quantity' => 1.0, 'unit' => 'run'], 'train_cpu_seconds' => ['quantity' => 56.75, 'unit' => 's']], $rows);
        // El día de consumo es el de la ZONA DE LA EMPRESA: 03:30 UTC del 2 es el 1 en Guayaquil (UTC−5).
        $this->assertSame('2026-11-01', (string) DB::table('tenant_usage_records')->where('tenant_id', $tenant->id)->value('period'));
    }

    public function test_metering_is_idempotent_and_replaces_instead_of_duplicating(): void
    {
        [$tenant] = $this->createTenantWithUser();
        $run = $this->demandRun($tenant, ['timings_seconds' => ['backtesting' => 10, 'training' => 5, 'reconciling' => 1], 'duration_ms' => 20000]);
        $this->meter()->recordForecastRun($run);
        $this->meter()->recordForecastRun($run);
        $this->meter()->recordForecastRun($run);
        $this->assertSame(4, DB::table('tenant_usage_records')->where('tenant_id', $tenant->id)->count());
        // un reintento con datos corregidos reemplaza el valor
        DB::table('forecast_runs')->where('id', $run->id)->update(['summary' => json_encode(['timings_seconds' => ['backtesting' => 30, 'training' => 5, 'reconciling' => 1], 'duration_ms' => 40000])]);
        $this->meter()->recordForecastRun($this->withinTenant($tenant, fn () => ForecastRun::query()->findOrFail($run->id)));
        $this->assertSame(35.0, $this->rows($tenant, 'forecast_run', $run->id)['train_cpu_seconds']['quantity']);
        $this->assertSame(4, DB::table('tenant_usage_records')->where('tenant_id', $tenant->id)->count());
        // el índice único lo garantiza aunque dos procesos escriban a la vez
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('tenant_usage_records')->insert(['tenant_id' => $tenant->id, 'period' => '2026-11-01', 'metric' => 'runs', 'quantity' => 1, 'unit' => 'run', 'source' => 'forecast_run', 'source_ref' => (string) $run->id]));
    }

    public function test_only_completed_runs_are_metered_and_missing_timings_are_not_invented(): void
    {
        [$tenant] = $this->createTenantWithUser();
        foreach (['failed', 'running', 'queued', 'cancelled'] as $status) {
            $this->assertSame([], $this->meter()->recordForecastRun($this->demandRun($tenant, ['duration_ms' => 1000], $status)));
        }
        $this->assertSame(0, DB::table('tenant_usage_records')->count());
        // completada pero sin desglose ni duración: solo se registra que hubo una ejecución
        $bare = $this->demandRun($tenant, ['series_count' => 3]);
        $this->meter()->recordForecastRun($bare);
        $this->assertSame(['runs' => ['quantity' => 1.0, 'unit' => 'run']], $this->rows($tenant, 'forecast_run', $bare->id));
        // sin desglose pero con duración total (ejecuciones antiguas): la duración sí
        $old = $this->demandRun($tenant, ['duration_ms' => 12500]);
        $this->meter()->recordForecastRun($old);
        $this->assertSame(['run_wall_seconds', 'runs'], array_keys($this->rows($tenant, 'forecast_run', $old->id)));
    }

    public function test_customer_train_and_predict_runs_are_metered_by_operation(): void
    {
        [$tenant] = $this->createTenantWithUser();
        $train = $this->coreRun($tenant, 'train', ['duration_ms' => 100000, 'horizons' => ['7' => ['duration_s' => 20.5], '30' => ['duration_s' => 30.25], '90' => ['status' => 'abstained']]]);
        $predict = $this->coreRun($tenant, 'predict', ['duration_ms' => 8500, 'rows' => 120]);
        $other = $this->coreRun($tenant, 'evaluate', ['duration_ms' => 1000]);
        foreach ([$train, $predict, $other] as $run) {
            $this->meter()->recordCoreRun($run);
        }
        $this->assertSame(['run_wall_seconds' => ['quantity' => 100.0, 'unit' => 's'], 'runs' => ['quantity' => 1.0, 'unit' => 'run'], 'train_cpu_seconds' => ['quantity' => 50.75, 'unit' => 's']], $this->rows($tenant, 'core_run', $train->id));
        $this->assertSame(['inference_cpu_seconds' => ['quantity' => 8.5, 'unit' => 's'], 'run_wall_seconds' => ['quantity' => 8.5, 'unit' => 's'], 'runs' => ['quantity' => 1.0, 'unit' => 'run']], $this->rows($tenant, 'core_run', $predict->id));
        $this->assertSame(['run_wall_seconds', 'runs'], array_keys($this->rows($tenant, 'core_run', $other->id)));
        // un entrenamiento sin desglose por horizonte usa la duración de la corrida
        $flat = $this->coreRun($tenant, 'train', ['duration_ms' => 9000]);
        $this->meter()->recordCoreRun($flat);
        $this->assertSame(9.0, $this->rows($tenant, 'core_run', $flat->id)['train_cpu_seconds']['quantity']);
        $this->assertSame([], $this->meter()->recordCoreRun($this->coreRun($tenant, 'train', ['duration_ms' => 1], 'failed')));
    }

    public function test_usage_is_isolated_between_companies_even_with_the_same_run_id_reference(): void
    {
        [$a] = $this->createTenantWithUser();
        [$b] = $this->createTenantWithUser();
        $runA = $this->demandRun($a, ['duration_ms' => 1000]);
        $runB = $this->demandRun($b, ['duration_ms' => 2000]);
        $this->meter()->recordForecastRun($runA);
        $this->meter()->recordForecastRun($runB);
        $this->assertSame(1.0, $this->rows($a, 'forecast_run', $runA->id)['run_wall_seconds']['quantity']);
        $this->assertSame(2.0, $this->rows($b, 'forecast_run', $runB->id)['run_wall_seconds']['quantity']);
        $this->assertSame([], $this->rows($a, 'forecast_run', $runB->id), 'A no ve ni acumula el consumo de B');
        // mismo (fuente, referencia, métrica) en dos empresas no colisiona
        DB::table('tenant_usage_records')->where('tenant_id', $b->id)->update(['source_ref' => (string) $runA->id]);
        $this->assertSame(2, DB::table('tenant_usage_records')->where('source_ref', (string) $runA->id)->where('metric', 'runs')->count());
    }

    public function test_the_table_and_the_metrics_carry_quantities_never_prices(): void
    {
        $columns = Schema::getColumnListing('tenant_usage_records');
        foreach ($columns as $column) {
            $this->assertDoesNotMatchRegularExpression('/price|cost|amount|usd|currency|precio|costo/i', $column, "tenant_usage_records no debe tener la columna {$column}");
        }
        [$tenant] = $this->createTenantWithUser();
        $this->meter()->recordForecastRun($this->demandRun($tenant, ['timings_seconds' => ['training' => 1, 'reconciling' => 1], 'duration_ms' => 3000]));
        $this->meter()->recordCoreRun($this->coreRun($tenant, 'train', ['duration_ms' => 1000]));
        $this->meter()->recordStorage($tenant->id, '2026-11-01', 'bronze', 10, 1);
        $metrics = DB::table('tenant_usage_records')->distinct()->pluck('metric')->sort()->values()->all();
        $this->assertSame(['inference_cpu_seconds', 'run_wall_seconds', 'runs', 'storage_bytes', 'storage_objects', 'train_cpu_seconds'], $metrics);
        $this->assertEqualsCanonicalizing(['s', 'run', 'bytes', 'objects'], DB::table('tenant_usage_records')->distinct()->pluck('unit')->all());
    }

    // ───────────────────────── enganchado a los jobs ─────────────────────────

    public function test_the_demand_job_meters_the_completed_run_and_a_metering_failure_never_breaks_it(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser();
        $run = app(ForecastRunService::class)->request($tenant, $user, 90, null);
        $job = $this->withinTenant($tenant, fn () => TrainingJob::query()->where('forecast_run_id', $run->id)->firstOrFail());
        // el motor deja el resumen con los tiempos; el job de Laravel solo cierra y mide
        DB::table('forecast_runs')->where('id', $run->id)->update(['summary' => json_encode(['timings_seconds' => ['backtesting' => 7, 'training' => 3, 'reconciling' => 2], 'duration_ms' => 15000])]);
        Http::fake(['engine.test/*' => Http::response(['run_id' => $run->id, 'status' => 'completed'])]);

        $pipeline = new RunForecastPipeline($tenant->id, $run->id, $job->id);
        $pipeline->withFakeQueueInteractions();
        app(SetTenantContext::class)->handle($pipeline, fn ($j) => $j->handle(app(EngineClient::class), app(TrainingJobRecorder::class)));

        $this->assertSame(TrainingJob::STATUS_SUCCEEDED, $job->fresh()->status);
        $rows = $this->rows($tenant, 'forecast_run', $run->id);
        $this->assertSame([1.0, 10.0, 2.0, 15.0], [$rows['runs']['quantity'], $rows['train_cpu_seconds']['quantity'], $rows['inference_cpu_seconds']['quantity'], $rows['run_wall_seconds']['quantity']]);

        // Reponer la medición: un job repetido de una ejecución ya completada no duplica nada.
        $again = new RunForecastPipeline($tenant->id, $run->id, $job->id);
        $again->withFakeQueueInteractions();
        app(SetTenantContext::class)->handle($again, fn ($j) => $j->handle(app(EngineClient::class), app(TrainingJobRecorder::class)));
        $this->assertSame(4, DB::table('tenant_usage_records')->where('tenant_id', $tenant->id)->count());

        // Un valor que desborda la columna hace fallar la medición: se registra en el log y el job termina bien.
        [$t2, $u2] = $this->createTenantWithUser();
        $run2 = app(ForecastRunService::class)->request($t2, $u2, 90, null);
        $job2 = $this->withinTenant($t2, fn () => TrainingJob::query()->where('forecast_run_id', $run2->id)->firstOrFail());
        DB::table('forecast_runs')->where('id', $run2->id)->update(['summary' => json_encode(['duration_ms' => 1e30])]);
        $p2 = new RunForecastPipeline($t2->id, $run2->id, $job2->id);
        $p2->withFakeQueueInteractions();
        app(SetTenantContext::class)->handle($p2, fn ($j) => $j->handle(app(EngineClient::class), app(TrainingJobRecorder::class)));
        $this->assertSame(TrainingJob::STATUS_SUCCEEDED, $job2->fresh()->status);
        $this->assertSame('completed', $this->withinTenant($t2, fn () => ForecastRun::query()->find($run2->id)->status));
        $this->assertSame(0, DB::table('tenant_usage_records')->where('tenant_id', $t2->id)->count(), 'todo o nada: la medición fallida no deja filas a medias');
    }

    public function test_the_customer_training_job_meters_the_completed_run(): void
    {
        Queue::fake();
        [$tenant, $user] = $this->createTenantWithUser();
        $run = app(CustomerRunService::class)->request($tenant, $user, 'train');
        $job = $this->withinTenant($tenant, fn () => TrainingJob::query()->where('core_run_id', $run->id)->firstOrFail());
        DB::table('core_runs')->where('id', $run->id)->update(['summary' => json_encode(['duration_ms' => 20000, 'horizons' => ['30' => ['duration_s' => 12.5]]])]);
        Http::fake(['engine.test/*' => Http::response(['run_id' => $run->id, 'status' => 'completed', 'operation' => 'train'])]);

        $queued = new RunCustomerTraining($tenant->id, $run->id, $job->id);
        $queued->withFakeQueueInteractions();
        app(SetTenantContext::class)->handle($queued, fn ($j) => $j->handle(app(CustomerEngineClient::class), app(TrainingJobRecorder::class)));

        $rows = $this->rows($tenant, 'core_run', $run->id);
        $this->assertSame([1.0, 12.5, 20.0], [$rows['runs']['quantity'], $rows['train_cpu_seconds']['quantity'], $rows['run_wall_seconds']['quantity']]);
    }
}
