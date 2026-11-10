<?php

declare(strict_types=1);

namespace App\Services\Forecasting\Support;

/**
 * Colas de los trabajos que llaman al motor (ADR-V26-01 · F3). Dos clases de carga para que un entrenamiento largo de una empresa
 * no deje esperando a los trabajos cortos de todas las demás:
 *
 * - heavy: entrenamiento y pronóstico (minutos): RunForecastPipeline, RunCustomerTraining, operaciones de Supply.
 * - light: trabajos cortos y frecuentes: predicción diaria de clientes, evaluación contra reales, preparación de datos.
 *
 * Sin ENGINE_LIGHT_QUEUE ambas son la MISMA cola (un solo worker, como antes): separar es una decisión de despliegue, porque la cola
 * ligera necesita su propio worker (docker-compose.yml lo define; la variante SQS de infra/aws aún no tiene esa cola).
 */
final class EngineQueue
{
    public static function heavy(): string
    {
        return (string) config('engine.queue', 'forecasting');
    }

    public static function light(): string
    {
        $light = config('engine.light_queue');

        return is_string($light) && $light !== '' ? $light : self::heavy();
    }
}
