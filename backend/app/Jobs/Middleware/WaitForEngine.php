<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Services\Forecasting\Support\EngineCircuit;
use Closure;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;

/**
 * Middleware de cola de los trabajos que llaman al motor (ADR-V26-01 · F6): mientras el cortacircuitos está abierto, el trabajo NO
 * se ejecuta ni falla; se aplaza y vuelve solo cuando el motor responde.
 *
 * - Trabajo normal: se encola una COPIA con espera y el actual termina sin hacer nada. La copia es un trabajo nuevo, así que esperar
 *   al motor no consume intentos (`$tries` sigue contando solo ejecuciones reales). La ejecución de la empresa queda «en cola».
 * - Tras `engine.circuit.max_deferrals` aplazamientos deja de esperar: se ejecuta, falla al conectar y sigue la vía normal de fallo
 *   (reintento, `failed()`, alerta), para que nada quede esperando indefinidamente.
 * - Trabajo único (ShouldBeUnique): no se puede encolar una copia mientras el actual conserva su candado, así que se devuelve a la
 *   cola con espera. Eso sí consume un intento; son trabajos breves y periódicos que se vuelven a programar solos.
 *
 * El trabajo debe usar App\Jobs\Concerns\WaitsForEngine (lleva la cuenta de aplazamientos) e ir DESPUÉS de SetTenantContext.
 */
final class WaitForEngine
{
    public function handle(object $job, Closure $next): void
    {
        $circuit = app(EngineCircuit::class);
        if (! $circuit->isOpen()) {
            $next($job);

            return;
        }

        $delay = $circuit->retryAfterSeconds();
        if ($job instanceof ShouldBeUnique) {
            $job->release($delay);

            return;
        }

        $max = (int) config('engine.circuit.max_deferrals', 60);
        if (! property_exists($job, 'engineDeferrals') || $job->engineDeferrals >= $max) {
            $next($job);   // agotó la espera (o no lleva la cuenta): se ejecuta y falla por la vía normal

            return;
        }

        $copy = clone $job;
        $copy->job = null;   // el trabajo de cola en curso pertenece al original, no a la copia
        $copy->engineDeferrals++;
        dispatch($copy)->delay($delay);

        Log::info('Motor no disponible: trabajo aplazado', [
            'job' => $job::class, 'tenant_id' => $job->tenantId ?? null, 'deferrals' => $copy->engineDeferrals, 'retry_in_seconds' => $delay,
        ]);
    }
}
