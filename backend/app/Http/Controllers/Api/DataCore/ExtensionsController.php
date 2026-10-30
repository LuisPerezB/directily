<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataCore;

use App\Http\Controllers\Controller;
use App\Services\DataCore\Platform\PlatformEngineClient;
use App\Services\Forecasting\Exceptions\EngineUnavailableException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/** GET /platform/extensions — perfil sectorial y proveedores (calendario, variables, restricciones) que aplican a la empresa. */
final class ExtensionsController extends Controller
{
    public function __construct(private readonly PlatformEngineClient $engine, private readonly TenantContext $context) {}

    public function show(): JsonResponse
    {
        try {
            $result = $this->engine->extensions($this->context->require()->id);
        } catch (EngineUnavailableException) {
            return response()->json(['message' => 'El motor predictivo no está disponible; intente de nuevo en unos minutos.', 'code' => 'engine_unavailable'], 503);
        }

        return response()->json(['data' => $result['body']], $result['status'] === 200 ? 200 : 404);
    }
}
