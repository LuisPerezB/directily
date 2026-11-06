<?php

declare(strict_types=1);

namespace App\Jobs\Customer;

use App\Models\CoreRun;
use App\Services\Forecasting\Support\EngineQueue;
use App\Services\Pipeline\DailyPipeline;

/**
 * Predicción diaria del Customer Core (operation = predict): carga los modelos en producción y recalcula las variables a
 * la fecha; la recencia cambia aunque no haya una venta nueva. No entrena.
 *
 * Va a la cola de trabajos cortos del motor (ADR-V26-01 · F3): el radar y las decisiones de la mañana dependen de ella, así que
 * no debe esperar detrás de los pronósticos y entrenamientos de la noche.
 */
class RunCustomerPrediction extends RunCustomerOperation
{
    public static function queueName(): string
    {
        return EngineQueue::light();
    }

    /** Al terminar (bien o mal) la predicción del día, el ciclo diario lanza el radar de la empresa (ADR-V26-01 · F1). */
    protected function runFinished(CoreRun $run): void
    {
        app(DailyPipeline::class)->customerPredictionFinished($run);
    }
}
