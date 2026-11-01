<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataCore;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\DataCore\Platform\PlatformEngineClient;
use App\Services\Forecasting\Exceptions\EngineUnavailableException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Configuración por precedencia (ADR-V2-06): valores del core → perfil sectorial opcional → configuración de la empresa.
 * El motor resuelve y valida; este controlador es un proxy que muestra valor y procedencia por clave y guarda los
 * valores de empresa en `tenants.settings.cores.<core>` solo si el motor los valida (claves desconocidas rechazadas).
 */
final class EffectiveConfigController extends Controller
{
    public const CORES = ['demand', 'customer', 'supply', 'cost', 'scenario'];

    public function __construct(private readonly PlatformEngineClient $engine, private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    /** GET /tenant/effective-config?core= */
    public function show(Request $request): JsonResponse
    {
        $core = $request->validate(['core' => ['required', Rule::in(self::CORES)]])['core'];
        $tenant = $this->context->require();
        try {
            $result = $this->engine->effectiveConfig($tenant->id, $core);
        } catch (EngineUnavailableException) {
            return $this->unavailable();
        }
        if ($result['status'] !== 200) {
            return response()->json($this->failure($result['body']), $result['status'] === 404 ? 404 : 422);
        }

        return response()->json(['data' => $this->present($result['body'], $tenant, $core)]);
    }

    /**
     * PUT /tenant/core-config/{core} — define (reemplaza) los valores de empresa de un core. `overrides: {}` los elimina.
     * No modifica ejecuciones pasadas: cada ejecución guarda su configuración efectiva y su versión.
     */
    public function update(Request $request, string $core): JsonResponse
    {
        abort_unless(in_array($core, self::CORES, true), 404);
        $data = $request->validate(['overrides' => ['present', 'array']]);
        $tenant = $this->context->require();
        $overrides = $data['overrides'];
        try {
            $result = $this->engine->validateConfig($tenant->id, $core, $overrides);
        } catch (EngineUnavailableException) {
            return $this->unavailable();
        }
        if ($result['status'] !== 200) {
            return response()->json($this->failure($result['body']), 422);
        }

        $settings = (array) ($tenant->settings ?? []);
        $cores = (array) ($settings['cores'] ?? []);
        $previous = array_keys((array) ($cores[$core] ?? []));
        if ($overrides === []) {
            unset($cores[$core]);
        } else {
            $cores[$core] = $overrides;
        }
        $settings['cores'] = $cores;
        if ($cores === []) {
            unset($settings['cores']);
        }
        Tenant::query()->whereKey($tenant->id)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);
        $tenant->settings = $settings;
        $this->audit->log('tenant.core_config_updated', $tenant, ['core' => $core, 'keys' => array_keys($overrides), 'previous_keys' => $previous, 'config_version' => $result['body']['config_version'] ?? null]);

        return response()->json(['data' => $this->present($result['body'], $tenant, $core)]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function present(array $body, Tenant $tenant, string $core): array
    {
        return [
            'core' => $core,
            'config_version' => $body['config_version'] ?? null,
            'profile' => $body['profile'] ?? null,
            'precedence' => $body['precedence'] ?? ['core', 'profile', 'tenant'],
            'keys' => $body['keys'] ?? [],
            'tenant_overrides' => (object) ((array) (((array) ($tenant->settings ?? []))['cores'][$core] ?? [])),
            'note' => 'Los cambios de configuración no modifican ejecuciones pasadas: cada ejecución conserva la configuración efectiva con la que se calculó.',
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function failure(array $body): array
    {
        return [
            'message' => (string) ($body['message'] ?? 'La configuración no es válida.'),
            'code' => (string) ($body['code'] ?? 'invalid_config'),
            'errors' => $body['errors'] ?? [],
        ];
    }

    private function unavailable(): JsonResponse
    {
        return response()->json(['message' => 'El motor predictivo no está disponible; intente de nuevo en unos minutos.', 'code' => 'engine_unavailable'], 503);
    }
}
