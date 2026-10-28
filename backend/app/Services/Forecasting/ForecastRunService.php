<?php

declare(strict_types=1);

namespace App\Services\Forecasting;

use App\Exceptions\PlanLimitExceededException;
use App\Jobs\Forecasting\RunForecastPipeline;
use App\Models\ForecastRun;
use App\Models\Tenant;
use App\Models\TrainingJob;
use App\Models\User;
use App\Services\Forecasting\Exceptions\ForecastRunConflictException;
use App\Services\Forecasting\Support\CoreServices;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Solicita ejecuciones de pronóstico: valida plan y concurrencia, versiona,
 * crea forecast_run + training_job en "queued" y despacha el Job a la cola.
 * Nunca entrena dentro de la petición HTTP.
 */
class ForecastRunService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  int|null  $horizonDays  null = el mayor horizonte que permite el plan de la empresa
     *                                 (así la programación y las solicitudes sin horizonte explícito
     *                                 no piden uno que el plan no incluye).
     */
    public function request(
        Tenant $tenant,
        ?User $user,
        ?int $horizonDays = null,
        ?string $cutoffDate = null,
        string $triggeredBy = 'user',
        bool $dispatch = true,
    ): ForecastRun {
        $horizonDays ??= $this->maxAllowedHorizon($tenant)
            ?? throw new PlanLimitExceededException('horizons', null, null, 'Su plan no incluye horizontes de pronóstico.');
        $this->validateHorizon($tenant, $horizonDays);
        $cutoff = $this->validateCutoff($tenant, $cutoffDate);
        CoreServices::assertPlanLimit($tenant, 'max_forecast_runs_per_day');

        $run = $this->context->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $user, $horizonDays, $cutoff, $triggeredBy, $dispatch) {
            // Serializa solicitudes concurrentes de la misma empresa.
            Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->first();

            $active = $this->activeRun();
            if ($active !== null) {
                throw new ForecastRunConflictException($active);
            }

            $version = (int) ForecastRun::query()->max('version') + 1;
            $horizons = $this->horizonsUpTo($tenant, $horizonDays);
            $now = CarbonImmutable::now();

            $run = ForecastRun::query()->create([
                'version' => $version,
                'run_type' => $cutoff ? ForecastRun::TYPE_HISTORICAL : ForecastRun::TYPE_PRODUCTION,
                'status' => ForecastRun::STATUS_QUEUED,
                'triggered_by' => $triggeredBy,
                'user_id' => $user?->getKey(),
                'horizon_days' => $horizonDays,
                'frequency' => 'D',
                'cutoff_date' => $cutoff,
                'strategy' => 'auto',
                'options' => [
                    'horizons' => $horizons,
                    'cutoff_date' => $cutoff,
                ],
                'is_current' => false,
            ]);

            $job = TrainingJob::query()->create([
                'forecast_run_id' => $run->getKey(),
                'job_type' => TrainingJob::TYPE_FORECAST_PIPELINE,
                'status' => TrainingJob::STATUS_QUEUED,
                'progress' => 0,
                'attempts' => 0,
                'triggered_by' => $triggeredBy,
                'user_id' => $user?->getKey(),
                'params' => ['horizons' => $horizons, 'cutoff_date' => $cutoff],
                'queued_at' => $now,
            ]);

            if ($dispatch) {
                RunForecastPipeline::dispatch($tenant->getKey(), $run->getKey(), $job->getKey())
                    ->onQueue((string) config('engine.queue', 'forecasting'))
                    ->afterCommit();
            }

            return $run;
        }));

        CoreServices::recordUsage($tenant, 'forecast_runs');
        $this->context->runAs($tenant, fn () => CoreServices::audit('forecast.requested', $run, [
            'version' => $run->version,
            'horizon_days' => $horizonDays,
            'cutoff_date' => $cutoff,
            'triggered_by' => $triggeredBy,
        ]));

        return $this->context->runAs($tenant, fn () => $run->fresh(['trainingJob']) ?? $run);
    }

    /** Ejecución en cola o en proceso que aún no se considera vencida. */
    public function activeRun(): ?ForecastRun
    {
        $staleBefore = CarbonImmutable::now()->subMinutes((int) config('engine.stale_run_minutes', 120));

        return ForecastRun::query()
            ->active()
            ->where('created_at', '>=', $staleBefore)
            ->latest('id')
            ->first();
    }

    /** Mayor horizonte soportado por el motor e incluido en el plan; null si el plan no incluye ninguno. */
    public function maxAllowedHorizon(Tenant $tenant): ?int
    {
        $supported = array_map('intval', (array) config('engine.horizons', [7, 30, 90]));
        $allowed = array_values(array_intersect($supported, CoreServices::allowedHorizons($tenant)));

        return $allowed === [] ? null : max($allowed);
    }

    private function validateHorizon(Tenant $tenant, int $horizonDays): void
    {
        $supported = array_map('intval', (array) config('engine.horizons', [7, 30, 90]));
        if (! in_array($horizonDays, $supported, true)) {
            throw ValidationException::withMessages([
                'horizon_days' => 'El horizonte debe ser uno de: '.implode(', ', $supported).' días.',
            ]);
        }
        CoreServices::assertHorizon($tenant, $horizonDays);
    }

    private function validateCutoff(Tenant $tenant, ?string $cutoffDate): ?string
    {
        if ($cutoffDate === null || $cutoffDate === '') {
            return null;
        }
        try {
            $cutoff = CarbonImmutable::createFromFormat('!Y-m-d', $cutoffDate, $tenant->timezone ?: 'America/Guayaquil');
        } catch (\Throwable) {
            $cutoff = false;
        }
        if ($cutoff === false) {
            throw ValidationException::withMessages(['cutoff_date' => 'La fecha de corte debe tener formato AAAA-MM-DD.']);
        }
        $today = CarbonImmutable::now($tenant->timezone ?: 'America/Guayaquil')->startOfDay();
        if ($cutoff->greaterThanOrEqualTo($today)) {
            throw ValidationException::withMessages(['cutoff_date' => 'La fecha de corte debe ser anterior a hoy.']);
        }

        return $cutoff->toDateString();
    }

    /** @return list<int> */
    private function horizonsUpTo(Tenant $tenant, int $horizonDays): array
    {
        $horizons = array_values(array_filter(
            CoreServices::allowedHorizons($tenant),
            fn (int $h) => $h <= $horizonDays,
        ));
        if (! in_array($horizonDays, $horizons, true)) {
            $horizons[] = $horizonDays;
        }
        sort($horizons);

        return array_values(array_unique($horizons));
    }
}
