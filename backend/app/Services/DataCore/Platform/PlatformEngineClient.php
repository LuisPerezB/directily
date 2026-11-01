<?php

declare(strict_types=1);

namespace App\Services\DataCore\Platform;

use App\Services\Forecasting\Exceptions\EngineUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de las rutas /v1/platform del motor (configuración efectiva y extensiones). El motor lee SOLO la empresa
 * indicada; el backend siempre pasa la empresa del contexto, nunca un identificador recibido del usuario.
 */
class PlatformEngineClient
{
    /** @return array{status: int, body: array<string, mixed>} */
    public function effectiveConfig(int $tenantId, string $core): array
    {
        return $this->send('get', '/v1/platform/effective-config', ['tenant_id' => $tenantId, 'core' => $core]);
    }

    /**
     * Validación en seco de valores de empresa (no guarda nada).
     *
     * @param  array<string, mixed>  $overrides
     * @return array{status: int, body: array<string, mixed>}
     */
    public function validateConfig(int $tenantId, string $core, array $overrides): array
    {
        return $this->send('post', '/v1/platform/validate-config', ['tenant_id' => $tenantId, 'core' => $core, 'overrides' => (object) $overrides]);
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public function extensions(int $tenantId): array
    {
        return $this->send('get', "/v1/platform/tenants/{$tenantId}/extensions", []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function send(string $method, string $path, array $payload): array
    {
        try {
            $request = Http::baseUrl((string) config('engine.url'))->acceptJson()->asJson()
                ->timeout((int) config('engine.short_timeout', 60))->connectTimeout((int) config('engine.connect_timeout', 10));
            if ($token = config('engine.token')) {
                $request = $request->withToken((string) $token);
            }
            $response = $method === 'get' ? $request->get($path, $payload) : $request->post($path, $payload);
        } catch (ConnectionException $e) {
            throw new EngineUnavailableException('El motor predictivo no respondió: '.$e->getMessage(), previous: $e);
        }
        if ($response->status() >= 500 || in_array($response->status(), [401, 403], true)) {
            throw new EngineUnavailableException('El motor respondió con un error ('.$response->status().').');
        }

        return ['status' => $response->status(), 'body' => (array) ($response->json() ?? [])];
    }
}
