<?php

declare(strict_types=1);

namespace App\Support\Ops;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Alertas operativas (ADR-V26-01 · F5): un fallo debe llegar a quien opera, no quedarse en la salida de un contenedor.
 *
 * - Siempre escribe en el registro de la aplicación con nivel CRITICAL y un código estable en `ops_alert`.
 * - Si `ops.alerts.log_channel` está definido, también lo envía a ese canal, con límite de repetición por origen.
 * - Nunca lanza: una alerta que falla no puede tumbar al worker ni al planificador que la emite.
 * - Nunca incluye el texto de la excepción (puede traer SQL o datos); solo su clase. El detalle queda en el registro.
 */
final class OpsAlert
{
    public const SCHEDULED_TASK_FAILED = 'scheduled_task_failed';

    public const QUEUE_JOB_FAILED = 'queue_job_failed';

    public const ENGINE_UNAVAILABLE = 'engine_unavailable';

    /** Una tarea del planificador terminó con código de salida distinto de cero. */
    public function scheduledTaskFailed(string $command, ?int $exitCode): void
    {
        $task = self::taskName($command);

        $this->raise(self::SCHEDULED_TASK_FAILED, $task, ['task' => $task, 'exit_code' => $exitCode]);
    }

    /** Un trabajo de cola agotó sus intentos o se marcó fallido: ya está en `failed_jobs`. */
    public function jobFailed(string $job, ?string $queue, string $connection, ?int $tenantId, string $exception): void
    {
        $this->raise(self::QUEUE_JOB_FAILED, $job, [
            'job' => $job,
            'queue' => $queue,
            'connection' => $connection,
            'tenant_id' => $tenantId,
            'exception' => $exception,
        ]);
    }

    /** El motor predictivo dejó de responder y el cortacircuitos se abrió: los trabajos que lo necesitan quedan aplazados. */
    public function engineUnavailable(int $failures): void
    {
        $this->raise(self::ENGINE_UNAVAILABLE, 'engine', ['consecutive_failures' => $failures]);
    }

    /** Nombre del comando artisan dentro de la línea que ejecuta el planificador (`'/usr/bin/php' 'artisan' predictily:purge`). */
    public static function taskName(string $command): string
    {
        return preg_match('/\b(predictily:[a-z0-9:_-]+)/', $command, $m) === 1 ? $m[1] : trim($command);
    }

    /**
     * Empresa de un trabajo a partir de su carga en la cola, sin deserializarla: los trabajos de Predictily llevan `tenantId`.
     * Devuelve null si el trabajo no la declara o la carga está cifrada.
     */
    public static function tenantIdFromPayload(string $rawBody): ?int
    {
        $command = json_decode($rawBody, true)['data']['command'] ?? null;

        return is_string($command) && preg_match('/"tenantId";i:(\d+);/', $command, $m) === 1 ? (int) $m[1] : null;
    }

    /** @param array<string, mixed> $context */
    private function raise(string $code, string $origin, array $context): void
    {
        $message = "Alerta operativa: {$code} · {$origin}";
        $context = ['ops_alert' => $code] + $context;

        try {
            Log::critical($message, $context);
        } catch (Throwable) {
            // Sin registro disponible no hay dónde avisar; se sigue con el canal adicional.
        }

        $channel = config('ops.alerts.log_channel');
        if (! is_string($channel) || $channel === '' || $this->alreadySent($code, $origin)) {
            return;
        }

        try {
            Log::channel($channel)->critical($message, $context);
        } catch (Throwable $e) {
            try {
                Log::warning('No se pudo enviar la alerta operativa al canal adicional', ['channel' => $channel, 'exception' => $e::class]);
            } catch (Throwable) {
                // Nada más que hacer: la alerta ya quedó (o se intentó dejar) en el registro de la aplicación.
            }
        }
    }

    /** ¿Se envió ya esta misma alerta al canal adicional hace poco? Si la caché falla, se envía igual: mejor repetir que callar. */
    private function alreadySent(string $code, string $origin): bool
    {
        $seconds = (int) config('ops.alerts.throttle_seconds', 900);
        if ($seconds <= 0) {
            return false;
        }

        try {
            return ! Cache::add('ops-alert:'.$code.':'.sha1($origin), 1, $seconds);
        } catch (Throwable) {
            return false;
        }
    }
}
