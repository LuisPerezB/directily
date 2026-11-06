<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Jobs\Pipeline\RunRadarStage;
use App\Models\CoreRun;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Services\Forecasting\Support\ActiveRuns;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ciclo diario por empresa (ADR-V26-01 · F1): las etapas dependen del ESTADO de la anterior, no de la hora.
 *
 * - Deja constancia de cada etapa en `pipeline_stages` (qué pasó, y por qué una etapa no se ejecutó).
 * - Responde en qué estado está la predicción de clientes de HOY de una empresa, que es de lo que depende el radar.
 * - Cuando esa predicción termina (bien o mal), lanza el radar de esa empresa: ya no espera a su hora ni corre con datos de ayer.
 *
 * El estado de la predicción se lee de `core_runs` (la fuente de verdad), no de `pipeline_stages`: así no hay dos registros que
 * puedan contradecirse. `pipeline_stages` es el historial que se consulta para saber qué pasó.
 */
final class DailyPipeline
{
    /** No se pidió una predicción hoy (programación manual, sin modelo, plan sin el módulo): se usa la vigente. */
    public const PREDICTION_NONE = 'none';

    /** La predicción de hoy terminó bien. */
    public const PREDICTION_READY = 'ready';

    /** La predicción de hoy está en cola o en curso. */
    public const PREDICTION_WAITING = 'waiting';

    /** La predicción de hoy falló, se canceló o quedó colgada. */
    public const PREDICTION_FAILED = 'failed';

    public function __construct(private readonly TenantContext $context) {}

    /** Día de negocio de la empresa: el día en su zona horaria. */
    public function businessDate(Tenant $tenant, ?CarbonImmutable $at = null): CarbonImmutable
    {
        return ($at ?? CarbonImmutable::now())->setTimezone($tenant->timezone ?: 'America/Guayaquil')->startOfDay();
    }

    /**
     * Estado de la predicción de clientes de producción pedida en ese día de negocio (la más reciente, si hubo varias).
     *
     * @return array{state: string, run: CoreRun|null}
     */
    public function customerPredictionState(Tenant $tenant, CarbonImmutable $date): array
    {
        return $this->context->runAs($tenant, function () use ($date): array {
            $run = CoreRun::query()->where('core', 'customer')->where('operation', 'predict')->where('run_type', 'production')
                ->where('created_at', '>=', $date->utc())->where('created_at', '<', $date->addDay()->utc())
                ->latest('id')->first();
            if ($run === null) {
                return ['state' => self::PREDICTION_NONE, 'run' => null];
            }
            if ($run->status === 'completed') {
                return ['state' => self::PREDICTION_READY, 'run' => $run];
            }
            // En cola o en curso y aún vigente (misma regla que impide pedir otra): se espera. Si ya venció, se da por fallida.
            $waiting = in_array($run->status, ['queued', 'running'], true) && ActiveRuns::apply(CoreRun::query()->whereKey($run->getKey()))->exists();

            return ['state' => $waiting ? self::PREDICTION_WAITING : self::PREDICTION_FAILED, 'run' => $run];
        });
    }

    /**
     * La predicción de clientes llegó a un estado final. La anota y, si es la de hoy, lanza el radar de la empresa: si terminó
     * bien, el radar propone con ella; si falló, el radar queda `blocked` con el motivo en vez de usar la de ayer en silencio.
     * Requiere el contexto de la empresa (lo tiene el trabajo que la ejecuta).
     */
    public function customerPredictionFinished(CoreRun $run): void
    {
        if ($run->core !== 'customer' || $run->operation !== 'predict' || $run->run_type !== 'production') {
            return;
        }
        $tenant = $this->context->require();
        $date = $this->businessDate($tenant, CarbonImmutable::parse($run->created_at));
        $ok = $run->status === 'completed';
        $this->record($tenant, $date, PipelineStage::CUSTOMER_PREDICT, $ok ? PipelineStage::SUCCEEDED : PipelineStage::FAILED, [
            'reason' => $ok ? null : 'prediction_'.$run->status,
            'source_ref' => 'core_run:'.$run->getKey(),
        ]);

        if ($date->equalTo($this->businessDate($tenant))) {
            RunRadarStage::dispatch((int) $tenant->getKey());
        }
    }

    /**
     * Anota el estado de una etapa (una fila por empresa, día y etapa; repetir la etapa actualiza su fila). NUNCA lanza: llevar el
     * historial no puede tumbar la etapa que lo escribe; si falla, queda un aviso en el registro.
     *
     * @param  array{blocked_by?: string|null, reason?: string|null, source_ref?: string|null, detail?: array<string, mixed>|null}  $attributes
     */
    public function record(Tenant $tenant, CarbonImmutable $date, string $stage, string $status, array $attributes = []): ?PipelineStage
    {
        $save = function () use ($date, $stage, $status, $attributes): PipelineStage {
            $row = PipelineStage::query()->firstOrNew(['business_date' => $date->toDateString(), 'stage' => $stage]);
            $row->fill([
                'status' => $status,
                'blocked_by' => $attributes['blocked_by'] ?? null,
                'reason' => $attributes['reason'] ?? null,
                'source_ref' => $attributes['source_ref'] ?? null,
                'detail' => $attributes['detail'] ?? null,
            ]);
            $now = CarbonImmutable::now();
            if ($status === PipelineStage::RUNNING) {
                $row->started_at = $now;
                $row->finished_at = null;
            } else {
                $row->finished_at = $status === PipelineStage::WAITING ? null : $now;
            }
            $row->save();

            return $row;
        };

        try {
            return $this->context->runAs($tenant, function () use ($save): PipelineStage {
                try {
                    return $save();
                } catch (UniqueConstraintViolationException) {
                    return $save();   // otro proceso creó la fila a la vez: ahora existe y se actualiza
                }
            });
        } catch (Throwable $e) {
            Log::warning('Ciclo diario: no se pudo anotar el estado de la etapa', [
                'tenant_id' => $tenant->getKey(), 'stage' => $stage, 'status' => $status, 'exception' => $e::class,
            ]);

            return null;
        }
    }
}
