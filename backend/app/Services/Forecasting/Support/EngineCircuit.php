<?php

declare(strict_types=1);

namespace App\Services\Forecasting\Support;

use App\Services\Forecasting\Exceptions\EngineUnavailableException;
use App\Support\Ops\OpsAlert;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cortacircuitos hacia el motor predictivo (ADR-V26-01 · F6), compartido por todos sus clientes y por todos los workers (estado en
 * la caché).
 *
 * Sin él, con el motor caído cada trabajo de cada empresa gastaba sus intentos esperando una conexión que no iba a llegar y quedaba
 * fallido; al volver el motor había que relanzarlos a mano. Con él:
 *
 * - CERRADO (normal): las llamadas pasan. Cada llamada que no logra conectar suma un fallo; una que sí conecta los borra.
 * - ABIERTO: tras `failure_threshold` fallos seguidos se abre durante `open_seconds`. Las llamadas fallan al instante, sin tocar la
 *   red, con EngineUnavailableException (código `engine_circuit_open`); los trabajos de cola se aplazan en vez de gastar intentos
 *   (App\Jobs\Middleware\WaitForEngine). Al abrirse genera una alerta operativa.
 * - PRUEBA: al vencer `open_seconds` las llamadas vuelven a pasar. Si el motor sigue caído, el primer fallo lo reabre.
 *
 * Solo cuenta lo que indica que el motor NO ESTÁ (conexión rechazada, host no resuelto, tiempo de conexión agotado, conexión
 * cortada). Una respuesta HTTP de error o un tiempo de LECTURA agotado significan que el motor está y contesta o trabaja: no abren.
 *
 * Nunca lanza por su cuenta: si la caché falla, el cortacircuitos se comporta como cerrado (igual que antes de existir).
 */
final class EngineCircuit
{
    public const OPEN_CODE = 'engine_circuit_open';

    private const FAILURES = 'engine:circuit:failures';

    private const OPEN = 'engine:circuit:open';

    private const ALERTED = 'engine:circuit:alerted';

    public function enabled(): bool
    {
        return (bool) config('engine.circuit.enabled', true);
    }

    public function isOpen(): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        try {
            return (bool) Cache::get(self::OPEN, false);
        } catch (Throwable) {
            return false;
        }
    }

    /** Segundos que conviene esperar antes de volver a intentar mientras está abierto. */
    public function retryAfterSeconds(): int
    {
        return max(30, (int) config('engine.circuit.open_seconds', 60));
    }

    /**
     * Ejecuta una llamada al motor bajo el cortacircuitos.
     *
     * @template T
     *
     * @param  callable(): T  $request  la llamada HTTP; debe dejar salir ConnectionException
     * @param  bool  $probe  true para la comprobación de salud: pasa aunque esté abierto (es la que confirma si el motor volvió)
     * @return T
     *
     * @throws EngineUnavailableException si está abierto
     * @throws ConnectionException la de la llamada, tal cual (cada cliente la traduce como ya hacía)
     */
    public function call(callable $request, bool $probe = false): mixed
    {
        if (! $probe && $this->isOpen()) {
            throw new EngineUnavailableException(
                'El motor predictivo no está disponible; se reintentará en breve.',
                errorCode: self::OPEN_CODE,
            );
        }
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            $this->recordFailure($e);

            throw $e;
        }
        $this->recordSuccess();

        return $response;
    }

    public function recordFailure(ConnectionException $e): void
    {
        if (! $this->enabled() || ! self::isUnreachable($e)) {
            return;
        }
        try {
            $open = $this->retryAfterSeconds();
            // La cuenta vive más que la apertura: si al reabrir las llamadas el motor sigue caído, el primer fallo lo reabre.
            $failures = (int) Cache::get(self::FAILURES, 0) + 1;
            Cache::put(self::FAILURES, $failures, $open * 10);
            if ($failures < max(1, (int) config('engine.circuit.failure_threshold', 3))) {
                return;
            }
            Cache::put(self::OPEN, true, $open);
            if (Cache::add(self::ALERTED, true, 900)) {   // una alerta por caída, no una por cada reapertura
                app(OpsAlert::class)->engineUnavailable($failures);
            }
        } catch (Throwable) {
            // Sin caché no hay estado que guardar: el cortacircuitos queda cerrado.
        }
    }

    /** El motor respondió (con el resultado que sea): está disponible. */
    public function recordSuccess(): void
    {
        if (! $this->enabled()) {
            return;
        }
        try {
            if (Cache::get(self::FAILURES) !== null) {
                Cache::forget(self::FAILURES);
                Cache::forget(self::OPEN);
                Cache::forget(self::ALERTED);
            }
        } catch (Throwable) {
            // Igual que arriba.
        }
    }

    /**
     * ¿El fallo indica que el motor no está? Todo error de conexión lo indica salvo el tiempo de LECTURA agotado («Operation timed
     * out after N ms»): ahí la conexión se estableció y el motor sigue trabajando (un entrenamiento largo), no está caído.
     */
    public static function isUnreachable(ConnectionException $e): bool
    {
        return ! str_contains($e->getMessage(), 'Operation timed out');
    }
}
