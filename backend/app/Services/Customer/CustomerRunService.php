<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Exceptions\PlanLimitExceededException;
use App\Jobs\Customer\RunCustomerPrediction;
use App\Jobs\Customer\RunCustomerTraining;
use App\Models\CoreRun;
use App\Models\ModelRegistry;
use App\Models\Tenant;
use App\Models\TrainingJob;
use App\Models\User;
use App\Services\Customer\Exceptions\CustomerRunConflictException;
use App\Services\Forecasting\Support\CoreServices;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Solicita corridas del Customer Core: `train` (dataset de cortes, protocolo temporal, registro de modelos) y `predict`
 * (recalcula variables al día con los modelos en producción). Entrenar ≠ predecir (ADR-V2-08).
 *
 * - Horizontes: los del plan ∩ {7, 30, 90}. Un horizonte pedido fuera del plan responde 402 (no se revela un pronóstico
 *   comercial no autorizado); sin horizontes pedidos se usan todos los permitidos.
 * - Concurrencia: una sola corrida activa por empresa y operación (409).
 * - Nunca entrena dentro de la petición HTTP: crea core_run + training_job en «queued» y despacha el Job a la cola.
 */
class CustomerRunService
{
    public const HORIZONS = [7, 30, 90];

    public const OPERATIONS = ['train', 'predict'];

    public function __construct(private readonly TenantContext $context) {}

    /** @return list<int> horizontes del plan que el Customer Core soporta, ascendentes */
    public function allowedHorizons(Tenant $tenant): array
    {
        $allowed = array_values(array_intersect(self::HORIZONS, CoreServices::allowedHorizons($tenant)));
        sort($allowed);

        return $allowed;
    }

    /**
     * @param  list<int>|null  $horizons  null = todos los permitidos por el plan
     * @param  string|null  $asOf  AAAA-MM-DD. predict: fecha de las variables (null = hoy). train: corte retroactivo (null = último día completo)
     */
    public function request(
        Tenant $tenant,
        ?User $user,
        string $operation,
        ?array $horizons = null,
        ?string $asOf = null,
        string $triggeredBy = 'user',
        bool $dispatch = true,
    ): CoreRun {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw ValidationException::withMessages(['operation' => 'La operación debe ser train o predict.']);
        }
        $allowed = $this->allowedHorizons($tenant);
        if ($horizons === null || $horizons === []) {
            $horizons = $allowed;
        } else {
            $horizons = array_values(array_unique(array_map('intval', $horizons)));
            sort($horizons);
            foreach ($horizons as $h) {
                if (! in_array($h, self::HORIZONS, true)) {
                    throw ValidationException::withMessages(['horizons' => 'Los horizontes de comportamiento de compra son 7, 30 y 90 días.']);
                }
                CoreServices::assertHorizon($tenant, $h);   // 402 si el plan no lo incluye
            }
        }
        if ($horizons === []) {
            throw new PlanLimitExceededException('horizons', null, null, 'Su plan no incluye horizontes de comportamiento de compra (7, 30 o 90 días).');
        }
        $tz = $tenant->timezone ?: 'America/Guayaquil';
        $today = CarbonImmutable::now($tz)->startOfDay();
        $asOfDate = $this->parseAsOf($asOf, $tz, $today, $operation);
        $historical = $asOfDate !== null && $asOfDate->lessThan($today);

        $run = $this->context->runAs($tenant, fn () => DB::transaction(function () use ($tenant, $user, $operation, $horizons, $asOfDate, $historical, $triggeredBy, $dispatch) {
            Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->first();   // serializa solicitudes de la misma empresa
            $active = $this->activeRun($operation);
            if ($active !== null) {
                throw new CustomerRunConflictException($active);
            }
            $version = (int) CoreRun::query()->where('core', 'customer')->max('version') + 1;
            $run = CoreRun::query()->create([
                'core' => 'customer',
                'operation' => $operation,
                'version' => $version,
                'run_type' => $historical || ($operation === 'train' && $asOfDate !== null) ? 'historical' : 'production',
                'status' => 'queued',
                'result_type' => $operation === 'train' ? 'evaluation' : 'forecast',
                'as_of' => $asOfDate?->toDateString(),
                'horizons' => $horizons,
                'options' => ['requested_horizons' => $horizons, 'requested_as_of' => $asOfDate?->toDateString()],
                'triggered_by' => $triggeredBy,
                'user_id' => $user?->getKey(),
            ]);
            $job = TrainingJob::query()->create([
                'core_run_id' => $run->getKey(),
                'job_type' => 'customer_'.$operation,
                'status' => TrainingJob::STATUS_QUEUED,
                'progress' => 0,
                'attempts' => 0,
                'triggered_by' => $triggeredBy,
                'user_id' => $user?->getKey(),
                'params' => ['operation' => $operation, 'horizons' => $horizons, 'as_of' => $asOfDate?->toDateString()],
                'queued_at' => CarbonImmutable::now(),
            ]);
            if ($dispatch) {
                $jobClass = $operation === 'train' ? RunCustomerTraining::class : RunCustomerPrediction::class;
                $jobClass::dispatch($tenant->getKey(), $run->getKey(), $job->getKey())
                    ->onQueue((string) config('engine.queue', 'forecasting'))
                    ->afterCommit();
            }

            return $run;
        }));

        $this->context->runAs($tenant, fn () => CoreServices::audit('customer.run_requested', $run, [
            'operation' => $operation, 'version' => $run->version, 'horizons' => $horizons, 'as_of' => $asOfDate?->toDateString(),
            'triggered_by' => $triggeredBy,
        ]));

        return $this->context->runAs($tenant, fn () => $run->fresh(['trainingJobs']) ?? $run);
    }

    /** Corrida de la misma operación en cola o en proceso que aún no se considera vencida. */
    public function activeRun(string $operation): ?CoreRun
    {
        $staleBefore = CarbonImmutable::now()->subMinutes((int) config('engine.stale_run_minutes', 120));

        return CoreRun::query()->where('core', 'customer')->where('operation', $operation)
            ->whereIn('status', ['queued', 'running'])->where('created_at', '>=', $staleBefore)->latest('id')->first();
    }

    /**
     * Corrida de predicción vigente (is_current) o, en su defecto, la última de PRODUCCIÓN completada. Una corrida
     * retroactiva (`historical`) nunca se sirve por defecto: solo se consulta pidiéndola con `run_id`.
     */
    public function latestPrediction(): ?CoreRun
    {
        return CoreRun::query()->where('core', 'customer')->where('operation', 'predict')->where('status', 'completed')
            ->where('run_type', 'production')->orderByDesc('is_current')->orderByDesc('id')->first();
    }

    /** Igual que latestPrediction() para el entrenamiento: la evaluación vigente nunca es una retroactiva. */
    public function latestEvaluation(): ?CoreRun
    {
        return CoreRun::query()->where('core', 'customer')->where('operation', 'train')->where('status', 'completed')
            ->where('run_type', 'production')->orderByDesc('is_current')->orderByDesc('id')->first();
    }

    /** ¿Hay modelos de comportamiento en producción? (la predicción programada solo corre con modelo). */
    public function hasProductionModels(): bool
    {
        return ModelRegistry::query()->where('core', 'customer')->where('status', 'production')->exists();
    }

    private function parseAsOf(?string $asOf, string $tz, CarbonImmutable $today, string $operation): ?CarbonImmutable
    {
        if ($asOf === null || $asOf === '') {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $asOf, $tz);
        } catch (\Throwable) {
            $date = false;
        }
        if ($date === false) {
            throw ValidationException::withMessages(['as_of' => 'La fecha debe tener formato AAAA-MM-DD.']);
        }
        if ($date->greaterThan($today)) {
            throw ValidationException::withMessages(['as_of' => 'La fecha no puede ser futura.']);
        }
        if ($operation === 'train' && $date->greaterThanOrEqualTo($today)) {
            throw ValidationException::withMessages(['as_of' => 'El corte de entrenamiento debe ser anterior a hoy.']);
        }

        return $date;
    }
}
