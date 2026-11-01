<?php

declare(strict_types=1);

namespace App\Services\Supply;

use App\Services\Forecasting\Exceptions\EngineAuthenticationException;
use App\Services\Forecasting\Exceptions\EngineConflictException;
use App\Services\Forecasting\Exceptions\EngineException;
use App\Services\Forecasting\Exceptions\EngineNotFoundException;
use App\Services\Forecasting\Exceptions\EngineServerException;
use App\Services\Forecasting\Exceptions\EngineUnavailableException;
use App\Services\Forecasting\Exceptions\InsufficientDataException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente del router `/v1/supply` del motor (decisiones comunes: planes, costos, escenarios, causalidad, simulación).
 * Mismas reglas que EngineClient: autenticación Bearer, reintento solo ante errores de conexión y excepciones tipadas.
 */
class SupplyEngineClient
{
    /**
     * Ejecuta una corrida ya creada en `core_runs` (supply/plan, supply/simulate, cost/forecast, scenario/evaluate).
     * Idempotente en el motor: una corrida completada se devuelve sin recalcular.
     *
     * @return array<string, mixed>
     */
    public function execute(int $coreRunId, int $tenantId, ?int $trainingJobId): array
    {
        return $this->send('post', "/v1/supply/runs/{$coreRunId}/execute", [
            'tenant_id' => $tenantId, 'training_job_id' => $trainingJobId,
        ], (int) config('engine.timeout', 900));
    }

    /**
     * Verificación de requisitos causales (sin estimador; el motor responde insufficient_evidence).
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function causalCheck(int $tenantId, array $request): array
    {
        return $this->send('post', '/v1/supply/causal/check', ['tenant_id' => $tenantId, 'request' => (object) $request],
            max((int) config('engine.short_timeout', 60), 120));
    }

    /** @return array<string, mixed> */
    public function capabilities(): array
    {
        return $this->send('get', '/v1/supply/capabilities', [], (int) config('engine.short_timeout', 60));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function send(string $method, string $path, array $payload, int $timeout): array
    {
        try {
            $response = $this->request($timeout)->{$method}($path, $method === 'get' ? null : $payload);
        } catch (ConnectionException $e) {
            throw new EngineUnavailableException('El motor predictivo no respondió: '.$e->getMessage(), previous: $e);
        }

        return $this->handle($response);
    }

    private function request(int $timeout): PendingRequest
    {
        $request = Http::baseUrl((string) config('engine.url'))
            ->acceptJson()->asJson()->timeout($timeout)
            ->connectTimeout((int) config('engine.connect_timeout', 10))
            ->retry(
                max(1, (int) config('engine.connection_retries', 2) + 1),
                (int) config('engine.retry_sleep_ms', 500),
                fn (\Throwable $e) => $e instanceof ConnectionException,
                throw: false,
            );
        $token = config('engine.token');

        return $token ? $request->withToken($token) : $request;
    }

    /** @return array<string, mixed> */
    private function handle(Response $response): array
    {
        if ($response->successful()) {
            return (array) ($response->json() ?? []);
        }
        $body = (array) ($response->json() ?? []);
        $detail = $body['detail'] ?? $body;
        $detail = is_array($detail) ? $detail : ['message' => (string) $detail];
        $message = (string) ($detail['message'] ?? $body['message'] ?? 'Error del motor ('.$response->status().')');
        $code = isset($detail['code']) ? (string) $detail['code'] : (isset($body['code']) ? (string) $body['code'] : null);
        $details = (array) ($detail['details'] ?? $body['details'] ?? []);
        $status = $response->status();

        throw match (true) {
            $status === 401 || $status === 403 => new EngineAuthenticationException($message, $status, $code, $details),
            $status === 404 => new EngineNotFoundException($message, $status, $code, $details),
            $status === 409 => new EngineConflictException($message, $status, $code, $details),
            $status === 422 => new InsufficientDataException($message, $status, $code ?? 'insufficient_data', $details),
            $status >= 500 => new EngineServerException($message, $status, $code, $details),
            default => new EngineException($message, $status, $code, $details),
        };
    }
}
