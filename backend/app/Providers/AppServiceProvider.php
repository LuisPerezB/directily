<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped: se reinicia por petición/trabajo (seguro con Octane y workers de cola).
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        // Detecta consultas N+1 y asignaciones masivas indebidas en desarrollo/pruebas.
        Model::shouldBeStrict(! $this->app->isProduction());

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // 5 intentos por minuto por combinación email + IP.
        RateLimiter::for('login', function (Request $request) {
            $key = Str::lower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key)->response(fn () => response()->json([
                'message' => 'Demasiados intentos de inicio de sesión. Intente nuevamente en un minuto.',
                'code' => 'too_many_attempts',
            ], 429));
        });

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        // API general: por usuario (o IP) y empresa.
        RateLimiter::for('api', function (Request $request) {
            $key = ($request->user()?->getAuthIdentifier() ?? $request->ip()).'|'.$request->header('X-Tenant-ID', '-');

            return Limit::perMinute(300)->by($key);
        });

        // Ingesta por API REST (conector) — lotes de hasta 500 documentos.
        RateLimiter::for('ingest', fn (Request $request) => Limit::perMinute(60)->by(($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
