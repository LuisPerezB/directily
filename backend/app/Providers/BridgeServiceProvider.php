<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Límites de tasa del Predictily Bridge (V2.5 · W6-B). Viven en un proveedor —no en el archivo de rutas— para que sigan definidos con la caché de
 * rutas (`artisan optimize`, que ejecuta el entrypoint de producción).
 *
 * - `bridge-enrol`: canje del código de registro, por IP (el código de un solo uso es la autorización; esto frena la adivinación).
 * - `bridge-agent`: peticiones firmadas del dispositivo, por credencial (hash del token) e IP: un dispositivo ruidoso no agota el cupo de los demás.
 * - `bridge-agent-ip`: las mismas peticiones, contadas solo por IP (varios agentes detrás de una misma salida a Internet comparten este cupo).
 * - `bridge-put`: subida por URL prefirmada, por IP.
 */
final class BridgeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('bridge-enrol', fn (Request $request) => Limit::perMinute((int) config('bridge.enrol_attempts_per_minute', 10))->by('bridge-enrol:'.$request->ip()));
        RateLimiter::for('bridge-agent', fn (Request $request) => Limit::perMinute((int) config('bridge.device_requests_per_minute', 240))->by('bridge-agent:'.sha1((string) $request->bearerToken().'|'.$request->ip())));
        // Solo por IP y ANTES de autenticar: quien rota valores de Bearer (cada uno con su propio contador) no evade el límite (revisión adversarial B-4).
        RateLimiter::for('bridge-agent-ip', fn (Request $request) => Limit::perMinute((int) config('bridge.ip_requests_per_minute', 240))->by('bridge-agent-ip:'.$request->ip()));
        RateLimiter::for('bridge-put', fn (Request $request) => Limit::perMinute((int) config('bridge.device_requests_per_minute', 240))->by('bridge-put:'.$request->ip()));
    }
}
