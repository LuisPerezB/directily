<?php

declare(strict_types=1);

namespace App\Services\Forecasting\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qué ejecución (forecast_runs / core_runs) sigue "activa" y bloquea pedir otra igual (ADR-V26-01 · F3).
 *
 * - En curso (`running`): vigente `engine.stale_run_minutes` desde que EMPEZÓ (`started_at`, que fija el motor), no desde que se
 *   creó. Con la cola larga una ejecución puede empezar horas después de pedirse; medir desde la creación la daba por vencida
 *   mientras seguía corriendo y dejaba pedir otra encima.
 * - En cola (`queued`): vigente `engine.stale_queued_minutes` desde su creación. Es un tope de seguridad por si el trabajo se
 *   perdió sin marcar la ejecución como fallida; es mayor que el de las ejecuciones en curso porque esperar en cola es normal.
 */
final class ActiveRuns
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function apply(Builder $query): Builder
    {
        $now = CarbonImmutable::now();
        $runningSince = $now->subMinutes((int) config('engine.stale_run_minutes', 120));
        $queuedSince = $now->subMinutes((int) config('engine.stale_queued_minutes', 240));

        return $query->where(static function (Builder $active) use ($runningSince, $queuedSince): void {
            $active
                ->where(static fn (Builder $running) => $running
                    ->where('status', 'running')
                    ->whereRaw('COALESCE(started_at, created_at) >= ?', [$runningSince]))
                ->orWhere(static fn (Builder $queued) => $queued
                    ->where('status', 'queued')
                    ->where('created_at', '>=', $queuedSince));
        });
    }
}
