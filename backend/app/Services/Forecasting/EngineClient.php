<?php

declare(strict_types=1);

namespace App\Services\Forecasting;

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
 * Cliente HTTP del motor predictivo interno (FastAPI). Contrato: docs/06 §4.
 *
 * - Autenticación Bearer con ENGINE_API_TOKEN.
 * - Reintenta SOLO ante errores de conexión; las respuestas HTTP del motor se
 *   convierten en excepciones tipadas (ver Exceptions/).
 */
class EngineClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly int $timeout,
        private readonly int $shortTimeout,
        private readonly int $connectTimeout,
        private readonly int $connectionRetries,
        private readonly int $retrySleepMs,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: (string) config('engine.url'),
            token: config('engine.token'),
            timeout: (int) config('engine.timeout', 900),
            shortTimeout: (int) config('engine.short_timeout', 60),
            connectTimeout: (int) config('engine.connect_timeout', 10),
            connectionRetries: (int) config('engine.connection_retries', 2),
            retrySleepMs: (int) config('engine.retry_sleep_ms', 500),
        );
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        return $this->send('get', '/health', [], $this->shortTimeout, authenticated: false);
    }

    /** @return array<string, mixed> */
    public function capabilities(): array
    {
        return $this->send('get', '/v1/capabilities', [], $this->shortTimeout);
    }

    /**
     * Ejecuta el pipeline completo de una ejecución (síncrono, timeout largo).
     *
     * @param  list<int>  $horizons
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function executeRun(
        int $runId,
        int $tenantId,
        int $trainingJobId,
        array $horizons = [7, 14, 30, 60, 90],
        ?string $cutoffDate = null,
        array $options = [],
    ): array {
        return $this->send('post', "/v1/forecast-runs/{$runId}/execute", [
            'tenant_id' => $tenantId,
            'training_job_id' => $trainingJobId,
            'horizons' => array_values($horizons),
            'cutoff_date' => $cutoffDate,
            'options' => (object) $options,
        ], $this->timeout);
    }

    /** @return array<string, mixed> */
    public function readiness(int $tenantId, ?int $trainingJobId = null): array
    {
        return $this->send('post', "/v1/tenants/{$tenantId}/readiness", [
            'training_job_id' => $trainingJobId,
        ], max($this->shortTimeout, 300));
    }

    /** @return array<string, mixed> */
    public function evaluateActuals(int $tenantId, ?int $trainingJobId = null, int $lookbackRuns = 10): array
    {
        return $this->send('post', "/v1/tenants/{$tenantId}/evaluate-actuals", [
            'training_job_id' => $trainingJobId,
            'lookback_runs' => $lookbackRuns,
        ], max($this->shortTimeout, 600));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $payload, int $timeout, bool $authenticated = true): array
    {
        try {
            $response = $this->request($timeout, $authenticated)->{$method}($path, $method === 'get' ? null : $payload);
        } catch (ConnectionException $e) {
            throw new EngineUnavailableException(
                'El motor predictivo no respondió: '.$e->getMessage(),
                previous: $e,
            );
        }

        return $this->handle($response);
    }

    private function request(int $timeout, bool $authenticated): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->connectTimeout($this->connectTimeout)
            // Reintento únicamente ante errores de conexión; nunca ante respuestas HTTP.
            ->retry(
                max(1, $this->connectionRetries + 1),
                $this->retrySleepMs,
                fn (\Throwable $e) => $e instanceof ConnectionException,
                throw: false,
            );

        if ($authenticated && $this->token) {
            $request = $request->withToken($this->token);
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function handle(Response $response): array
    {
        if ($response->successful()) {
            return (array) ($response->json() ?? []);
        }

        $body = (array) ($response->json() ?? []);
        // FastAPI devuelve errores en "detail" (string u objeto).
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
