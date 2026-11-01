<?php

declare(strict_types=1);

namespace App\Services\Models\Usage;

use App\Models\CoreRun;
use App\Models\ForecastRun;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Medición de uso por empresa (`tenant_usage_records`; V2.5 · W4, ADR-V25-09 y §14). Registra CANTIDADES medidas, nunca precios: los
 * precios son parámetros del modelo de costos (docs/architecture/AWS_COST_MODEL.md).
 *
 * Métricas (unidad) por ejecución, a partir de lo que el motor ya reporta en el resumen de la ejecución:
 *  - `runs` (run): 1 por ejecución completada.
 *  - `train_cpu_seconds` (s): demanda = backtesting + entrenamiento + shadow (`summary.timings_seconds`); Customer `train` = suma de
 *    `summary.horizons.*.duration_s` (o la duración de la corrida si el resumen no la desglosa).
 *  - `inference_cpu_seconds` (s): demanda = reconciliación de los pronósticos servidos; Customer `predict` = duración de la corrida.
 *  - `run_wall_seconds` (s): duración total de la ejecución en el motor (`summary.duration_ms`).
 * Son SEGUNDOS DE RELOJ del proceso del motor, un proxy del tiempo de CPU (el motor corre en CPU y no mide vCPU): el modelo de costos los
 * multiplica por los vCPU de la tarea. Por día: `storage_bytes`/`storage_objects` de Bronze y de artefactos (StorageUsageSnapshot).
 *
 * Idempotente: la clave (empresa, fuente, referencia, métrica) es única; reintentar un job o repetir el comando diario reemplaza la fila.
 * Nunca interrumpe la operación medida: un fallo se registra en el log y se ignora.
 */
final class TenantUsageMeter
{
    public const RUNS = 'runs';

    public const TRAIN_SECONDS = 'train_cpu_seconds';

    public const INFERENCE_SECONDS = 'inference_cpu_seconds';

    public const WALL_SECONDS = 'run_wall_seconds';

    public const STORAGE_BYTES = 'storage_bytes';

    public const STORAGE_OBJECTS = 'storage_objects';

    public const SOURCE_FORECAST_RUN = 'forecast_run';

    public const SOURCE_CORE_RUN = 'core_run';

    public const SOURCE_BRONZE = 'bronze';

    public const SOURCE_ARTIFACTS = 'artifacts';

    /** @return list<array{metric: string, quantity: float, unit: string}> lo que se registra (vacío si la ejecución no está completada) */
    public function recordForecastRun(ForecastRun $run): array
    {
        if ($run->status !== ForecastRun::STATUS_COMPLETED) {
            return [];
        }
        $summary = (array) ($run->summary ?? []);
        $t = (array) ($summary['timings_seconds'] ?? []);
        $train = $this->sum($t, ['backtesting', 'training', 'shadow']);
        $inference = $this->sum($t, ['reconciling']);
        $wall = isset($summary['duration_ms']) && is_numeric($summary['duration_ms']) ? (float) $summary['duration_ms'] / 1000
            : $this->sum($t, ['total', 'loading_data']);

        return $this->recordRun((int) $run->tenant_id, self::SOURCE_FORECAST_RUN, (string) $run->id, $run->completed_at, [
            self::TRAIN_SECONDS => $train, self::INFERENCE_SECONDS => $inference, self::WALL_SECONDS => $wall,
        ]);
    }

    /** Solo el Customer Core: `train` e `predict`. Otra operación registra únicamente `runs` y la duración total. */
    public function recordCoreRun(CoreRun $run): array
    {
        if ($run->status !== 'completed') {
            return [];
        }
        $summary = (array) ($run->summary ?? []);
        $wall = isset($summary['duration_ms']) && is_numeric($summary['duration_ms']) ? (float) $summary['duration_ms'] / 1000 : null;
        $metrics = [self::WALL_SECONDS => $wall];
        if ($run->core === 'customer' && $run->operation === 'train') {
            $perHorizon = 0.0;
            $seen = false;
            foreach ((array) ($summary['horizons'] ?? []) as $horizon) {
                if (is_array($horizon) && isset($horizon['duration_s']) && is_numeric($horizon['duration_s'])) {
                    $perHorizon += (float) $horizon['duration_s'];
                    $seen = true;
                }
            }
            $metrics[self::TRAIN_SECONDS] = $seen ? $perHorizon : $wall;
        } elseif ($run->core === 'customer' && $run->operation === 'predict') {
            $metrics[self::INFERENCE_SECONDS] = $wall;
        }

        return $this->recordRun((int) $run->tenant_id, self::SOURCE_CORE_RUN, (string) $run->id, $run->completed_at, $metrics);
    }

    /** Foto diaria de almacenamiento: reemplaza la del mismo día (`source_ref` = fecha). */
    public function recordStorage(int $tenantId, string $day, string $source, int $bytes, int $objects): void
    {
        $this->safely(function () use ($tenantId, $day, $source, $bytes, $objects): void {
            $this->upsert($tenantId, $day, self::STORAGE_BYTES, (float) $bytes, 'bytes', $source, $day);
            $this->upsert($tenantId, $day, self::STORAGE_OBJECTS, (float) $objects, 'objects', $source, $day);
        });
    }

    /**
     * @param  array<string, ?float>  $seconds  métrica => segundos (null o negativo = el motor no lo reportó: no se registra)
     * @return list<array{metric: string, quantity: float, unit: string}>
     */
    private function recordRun(int $tenantId, string $source, string $ref, ?CarbonInterface $completedAt, array $seconds): array
    {
        $recorded = [];
        $this->safely(function () use ($tenantId, $source, $ref, $completedAt, $seconds, &$recorded): void {
            $period = $this->period($tenantId, $completedAt);
            $rows = [[self::RUNS, 1.0, 'run']];
            foreach ($seconds as $metric => $value) {
                if ($value !== null && $value >= 0.0) {
                    $rows[] = [$metric, round($value, 4), 's'];
                }
            }
            foreach ($rows as [$metric, $quantity, $unit]) {
                $this->upsert($tenantId, $period, $metric, $quantity, $unit, $source, $ref);
                $recorded[] = ['metric' => $metric, 'quantity' => $quantity, 'unit' => $unit];
            }
        });

        return $recorded;
    }

    private function upsert(int $tenantId, string $period, string $metric, float $quantity, string $unit, string $source, string $ref): void
    {
        DB::table('tenant_usage_records')->upsert(
            [['tenant_id' => $tenantId, 'period' => $period, 'metric' => $metric, 'quantity' => $quantity, 'unit' => $unit, 'source' => $source, 'source_ref' => $ref, 'created_at' => now()]],
            ['tenant_id', 'source', 'source_ref', 'metric'],
            ['period', 'quantity', 'unit'],
        );
    }

    /** Día de consumo en la zona horaria de la empresa. */
    private function period(int $tenantId, ?CarbonInterface $at): string
    {
        $zone = (string) (Tenant::query()->whereKey($tenantId)->value('timezone') ?: config('app.timezone', 'UTC'));
        $moment = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();   // inmutable: no altera la fecha de la ejecución
        try {
            return $moment->setTimezone($zone)->toDateString();
        } catch (Throwable) {
            return $moment->toDateString();
        }
    }

    /** @param array<string, mixed> $values */
    private function sum(array $values, array $keys): ?float
    {
        $total = 0.0;
        $seen = false;
        foreach ($keys as $key) {
            if (isset($values[$key]) && is_numeric($values[$key])) {
                $total += (float) $values[$key];
                $seen = true;
            }
        }

        return $seen ? $total : null;
    }

    /** Todo o nada: la medición de una ejecución es una transacción (punto de guardado si ya hay una abierta) y nunca propaga el fallo. */
    private function safely(callable $callback): void
    {
        try {
            DB::transaction($callback);
        } catch (Throwable $e) {
            Log::warning('No se pudo registrar el uso de la empresa', ['error' => $e->getMessage()]);
        }
    }
}
