<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Decision\RecommendationSink;
use App\Models\PersonalAccessToken;
use App\Services\Auth\Commercial\CommercialScopeContext;
use App\Services\DataCore\Bronze\BronzeStore;
use App\Services\DataCore\Bronze\FilesystemBronzeStore;
use App\Services\Decision\DecisionEngine;
use App\Services\Tenancy\TenantRls;
use App\Support\Ops\OpsAlert;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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
        // V2.5 · W6-A: alcance comercial de la persona en la petición (lo fija ResolveTenant; mismo ciclo de vida que la empresa activa).
        $this->app->scoped(CommercialScopeContext::class);
        // Almacén Bronze inmutable (ADR-V25-24): implementación por sistema de archivos; el controlador S3 lo agrega W2-B.
        $this->app->bind(BronzeStore::class, FilesystemBronzeStore::class);
        // V2.5 · W5-B (ADR-V25-13) · >>> W5-B decision-engine: punto único por el que Radar, reposición y escenarios proponen recomendaciones.
        $this->app->bind(RecommendationSink::class, DecisionEngine::class);
    }

    public function boot(): void
    {
        // Detecta consultas N+1 y asignaciones masivas indebidas en desarrollo/pruebas.
        Model::shouldBeStrict(! $this->app->isProduction());

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // V2.5 · W6-A: un rollback deshace los `SET` hechos dentro de la transacción; si RLS está activo, la empresa del contexto se vuelve a fijar en la conexión (sin efecto con la bandera apagada).
        Event::listen(TransactionRolledBack::class, static fn () => TenantRls::reassert());

        // ADR-V26-01 · F5: todo trabajo de cola que queda fallido (intentos agotados, fail() o timeout) genera una alerta operativa.
        Queue::failing(static function (JobFailed $event): void {
            app(OpsAlert::class)->jobFailed(
                job: $event->job->resolveName(),
                queue: $event->job->getQueue(),
                connection: (string) $event->connectionName,
                tenantId: OpsAlert::tenantIdFromPayload($event->job->getRawBody()),
                exception: $event->exception::class,
            );
        });

        // 5 intentos por minuto por combinación email + IP.
        RateLimiter::for('login', function (Request $request) {
            $key = Str::lower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key)->response(fn () => response()->json([
                'message' => 'Demasiados intentos de inicio de sesión. Intente nuevamente en un minuto.',
                'code' => 'too_many_attempts',
            ], 429));
        });

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        // V2.5 · W6-A: segundo factor. Además del bloqueo persistente por persona (MfaService), un límite por petición: 10/min por desafío o persona + IP. Nunca registra el código.
        RateLimiter::for('mfa', function (Request $request) {
            $who = $request->user()?->getAuthIdentifier() ?? substr(hash('sha256', (string) $request->input('challenge')), 0, 16);

            return Limit::perMinute(10)->by('mfa|'.$who.'|'.$request->ip())->response(fn () => response()->json([
                'message' => 'Demasiados intentos de verificación. Intente nuevamente en un minuto.',
                'code' => 'too_many_attempts',
            ], 429));
        });

        // API general: por usuario (o IP) y empresa.
        RateLimiter::for('api', function (Request $request) {
            $key = ($request->user()?->getAuthIdentifier() ?? $request->ip()).'|'.$request->header('X-Tenant-ID', '-');

            return Limit::perMinute(300)->by($key);
        });

        // Ingesta por API REST (conector) — lotes de hasta 500 documentos.
        RateLimiter::for('ingest', fn (Request $request) => Limit::perMinute(60)->by(($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
