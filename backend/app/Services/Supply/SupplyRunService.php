<?php

declare(strict_types=1);

namespace App\Services\Supply;

use App\Jobs\Supply\RunCostForecast;
use App\Jobs\Supply\RunSupplyPlan;
use App\Jobs\Supply\RunSupplySimulation;
use App\Models\CoreRun;
use App\Models\Tenant;
use App\Models\TrainingJob;
use App\Models\User;
use App\Services\Forecasting\Support\CoreServices;
use App\Services\Supply\Exceptions\SupplyRunConflictException;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Solicita corridas de decisiones: plan de reposición, pronóstico de costos, simulación histórica (asíncronas, cola
 * `forecasting`) y evaluación de escenarios (síncrona: la ejecuta quien llama con `executeNow`).
 *
 * - Una sola corrida activa por empresa, core y operación (409).
 * - Las recomendaciones NO envían pedidos ni comprometen fondos.
 * - Nunca calcula dentro de la petición HTTP salvo la evaluación de escenarios (rápida, sin entrenamiento).
 */
class SupplyRunService
{
    /** @var array<string, array{core: string, operation: string, job: class-string|null, job_type: string, result_type: string}> */
    public const KINDS = [
        'plan' => ['core' => 'supply', 'operation' => 'plan', 'job' => RunSupplyPlan::class, 'job_type' => 'supply_plan', 'result_type' => 'optimized_plan'],
        'simulation' => ['core' => 'supply', 'operation' => 'simulate', 'job' => RunSupplySimulation::class, 'job_type' => 'supply_simulation', 'result_type' => 'evaluation'],
        'cost_forecast' => ['core' => 'cost', 'operation' => 'forecast', 'job' => RunCostForecast::class, 'job_type' => 'cost_forecast', 'result_type' => 'forecast'],
        'scenario' => ['core' => 'scenario', 'operation' => 'evaluate', 'job' => null, 'job_type' => 'scenario_evaluation', 'result_type' => 'conditional_simulation'],
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $options  parámetros de la corrida (se guardan en core_runs.options)
     */
    public function request(Tenant $tenant, ?User $user, string $kind, array $options = [], string $triggeredBy = 'user', bool $dispatch = true): CoreRun
    {
        $def = self::KINDS[$kind];
        $historical = (bool) ($options['historical'] ?? false);

        $run = $this->context->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $user, $def, $options, $historical, $triggeredBy, $dispatch) {
            Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->first();   // serializa solicitudes de la misma empresa
            if ($def['job'] !== null) {
                $active = $this->activeRun($def['core'], $def['operation']);
                if ($active !== null) {
                    throw new SupplyRunConflictException($active);
                }
            }
            $version = (int) CoreRun::query()->where('core', $def['core'])->max('version') + 1;
            $run = CoreRun::query()->create([
                'core' => $def['core'],
                'operation' => $def['operation'],
                'version' => $version,
                'run_type' => $historical ? 'historical' : 'production',
                'status' => 'queued',
                'result_type' => $def['result_type'],
                'options' => $options,
                'triggered_by' => $triggeredBy,
                'user_id' => $user?->getKey(),
            ]);
            $job = TrainingJob::query()->create([
                'core_run_id' => $run->getKey(),
                'job_type' => $def['job_type'],
                'status' => TrainingJob::STATUS_QUEUED,
                'progress' => 0,
                'attempts' => 0,
                'triggered_by' => $triggeredBy,
                'user_id' => $user?->getKey(),
                'params' => ['kind' => $def['job_type']],
                'queued_at' => CarbonImmutable::now(),
            ]);
            if ($dispatch && $def['job'] !== null) {
                $def['job']::dispatch($tenant->getKey(), $run->getKey(), $job->getKey())
                    ->onQueue((string) config('engine.queue', 'forecasting'))
                    ->afterCommit();
            }

            return $run;
        }));

        $this->context->runAs($tenant, fn () => CoreServices::audit('supply.run_requested', $run, [
            'core' => $run->core, 'operation' => $run->operation, 'version' => $run->version, 'triggered_by' => $triggeredBy,
        ]));

        return $this->context->runAs($tenant, fn () => $run->fresh(['trainingJobs']) ?? $run);
    }

    /** Corrida de la misma operación en cola o en proceso que aún no se considera vencida. */
    public function activeRun(string $core, string $operation): ?CoreRun
    {
        $staleBefore = CarbonImmutable::now()->subMinutes((int) config('engine.stale_run_minutes', 120));

        return CoreRun::query()->where('core', $core)->where('operation', $operation)
            ->whereIn('status', ['queued', 'running'])->where('created_at', '>=', $staleBefore)->latest('id')->first();
    }

    public function latestPlan(): ?CoreRun
    {
        return CoreRun::query()->where('core', 'supply')->where('operation', 'plan')->where('status', 'completed')
            ->orderByDesc('is_current')->orderByDesc('id')->first();
    }
}
