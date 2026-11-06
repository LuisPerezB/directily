<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

/**
 * Cola de los trabajos por empresa del ciclo diario (ADR-V26-01 · F4): radar, decisiones y medición de resultados. No llaman al
 * motor: leen y escriben en la base de datos. Sin PIPELINE_QUEUE van a `default`, que existe en todos los despliegues; con una cola
 * propia (docker-compose.yml: `batch`) no esperan detrás de las importaciones.
 */
final class PipelineQueue
{
    public static function name(): string
    {
        $queue = config('ops.pipeline.queue');

        return is_string($queue) && $queue !== '' ? $queue : 'default';
    }
}
