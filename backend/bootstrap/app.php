<?php

use App\Http\Middleware\EnsureSuperadmin;
use App\Http\Middleware\EnsureTenantAbility;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SPA en el mismo dominio (cookies de sesión + CSRF de Sanctum).
        $middleware->statefulApi();
        $middleware->api(append: [SecurityHeaders::class]);
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'tenant.ability' => EnsureTenantAbility::class,
            'superadmin' => EnsureSuperadmin::class,
        ]);
        // La empresa debe resolverse ANTES del route model binding (global scope fail-closed).
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: ResolveTenant::class,
        );
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1,172.16.0.0/12,10.0.0.0/8'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Nunca exponer en producción que una consulta se intentó sin empresa activa.
        $exceptions->render(function (TenantNotResolvedException $e, Request $request) {
            Log::error('Consulta sin empresa activa', ['path' => $request->path(), 'error' => $e->getMessage()]);

            return response()->json([
                'message' => app()->isProduction() ? 'Error interno del servidor.' : $e->getMessage(),
                'code' => 'tenant_context_missing',
            ], 500);
        });

        // 404 en español sin revelar el modelo ni el id consultado.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $message = $e->getPrevious() instanceof ModelNotFoundException
                    ? 'Recurso no encontrado.'
                    : 'Ruta no encontrada.';

                return response()->json(['message' => $message, 'code' => 'not_found'], 404);
            }

            return null;
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getPrevious() instanceof AuthorizationException && $e->getMessage() !== 'This action is unauthorized.'
                        ? $e->getMessage()
                        : 'No tiene permisos para realizar esta acción.',
                    'code' => 'forbidden',
                ], 403);
            }

            return null;
        });
    })->create();
