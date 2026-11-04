<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Bridge\BridgeDeviceAuthenticator;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas del agente Bridge (alias `bridge.device`): autentica con token + firma (BridgeDeviceAuthenticator), fija la empresa del
 * dispositivo como empresa activa (el scope fail-closed hace el resto) y deja el dispositivo y su fuente en los atributos de la
 * petición. No usa sesiones ni Sanctum: un token de usuario no sirve aquí y un token de dispositivo no sirve en el resto de la API.
 */
final class AuthenticateBridgeDevice
{
    public function __construct(private readonly TenantContext $context, private readonly BridgeDeviceAuthenticator $authenticator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $auth = $this->authenticator->authenticate($request);
        $device = $auth['device'];
        // Latido implícito: cualquier petición autenticada indica que el agente está vivo (como mucho una escritura cada 30 s).
        if ($device->last_seen_at === null || $device->last_seen_at->diffInSeconds(now(), true) >= 30) {
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
        $request->attributes->set('bridge_device', $device);
        $request->attributes->set('bridge_source', $auth['source']);
        $this->context->set($auth['tenant']);

        try {
            return $next($request);
        } finally {
            $this->context->forget();
        }
    }
}
