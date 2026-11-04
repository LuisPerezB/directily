<?php

/*
| Rutas de Factor Intelligence y taxonomía (V2.5 · W2-F; ADR-V25-16 y ADR-V25-25). Prefijo /api/v1. [T] = requiere empresa activa.
| Contrato: docs/intelligence/*.md y docs/openapi/v2.yaml. Sin integraciones externas ni LLM.
*/

use App\Http\Controllers\Api\Intelligence\AdminFactorController;
use App\Http\Controllers\Api\Intelligence\ExternalEventController;
use App\Http\Controllers\Api\Intelligence\FactorController;
use App\Http\Controllers\Api\Intelligence\SectorProfileController;
use App\Http\Controllers\Api\Intelligence\SharedForecastGrantController;
use App\Http\Controllers\Api\Intelligence\TaxonomyAssignmentController;
use App\Http\Controllers\Api\Intelligence\TaxonomyNodeController;
use App\Services\Intelligence\SharedGrants\EnsureSharedForecastGrantsEnabled;
use Illuminate\Support\Facades\Route;

// ── Catálogo público de factores: escritura SOLO del propietario de la plataforma (superadministrador), sin empresa en contexto ──
Route::prefix('admin/intelligence')->middleware(['auth:sanctum', 'throttle:api', 'superadmin'])->group(function (): void {
    Route::get('factors', [AdminFactorController::class, 'index']);
    Route::post('factors', [AdminFactorController::class, 'store']);
    Route::patch('factors/{key}', [AdminFactorController::class, 'update'])->where('key', '[a-z0-9_.]+');
});

Route::middleware(['auth:sanctum', 'throttle:api', 'tenant'])->prefix('intelligence')->group(function (): void {
    // ── Catálogo de factores (lectura para toda empresa) y activación por empresa ──
    Route::get('factors', [FactorController::class, 'index'])->middleware('tenant.ability:data.view');
    Route::put('factors/{key}/activation', [FactorController::class, 'activation'])->middleware('tenant.ability:settings.manage')->where('key', '[a-z0-9_.]+');

    // ── Eventos externos manuales con procedencia; las alertas informativas se listan aparte ──
    Route::get('events', [ExternalEventController::class, 'index'])->middleware('tenant.ability:data.view');
    Route::get('events/informative-alerts', [ExternalEventController::class, 'informative'])->middleware('tenant.ability:data.view');
    Route::get('events/{id}', [ExternalEventController::class, 'show'])->middleware('tenant.ability:data.view')->whereNumber('id');
    Route::post('events', [ExternalEventController::class, 'store'])->middleware('tenant.ability:catalog.manage');
    Route::patch('events/{id}', [ExternalEventController::class, 'update'])->middleware('tenant.ability:catalog.manage')->whereNumber('id');
    Route::delete('events/{id}', [ExternalEventController::class, 'destroy'])->middleware('tenant.ability:catalog.manage')->whereNumber('id');
    // Verificar o rechazar: administrador o propietario y siempre un usuario distinto de quien propuso el evento.
    Route::post('events/{id}/verify', [ExternalEventController::class, 'verify'])->middleware('tenant.ability:settings.manage')->whereNumber('id');
    Route::post('events/{id}/reject', [ExternalEventController::class, 'reject'])->middleware('tenant.ability:settings.manage')->whereNumber('id');

    // ── Taxonomía básica: nodos versionados y asignación producto → nodo con procedencia ──
    Route::get('taxonomy/nodes', [TaxonomyNodeController::class, 'index'])->middleware('tenant.ability:data.view');
    Route::get('taxonomy/nodes/{node}', [TaxonomyNodeController::class, 'show'])->middleware('tenant.ability:data.view')->whereNumber('node');
    Route::post('taxonomy/nodes', [TaxonomyNodeController::class, 'store'])->middleware('tenant.ability:catalog.manage');
    Route::patch('taxonomy/nodes/{node}', [TaxonomyNodeController::class, 'update'])->middleware('tenant.ability:catalog.manage')->whereNumber('node');
    Route::delete('taxonomy/nodes/{node}', [TaxonomyNodeController::class, 'destroy'])->middleware('tenant.ability:catalog.manage')->whereNumber('node');
    Route::post('taxonomy/nodes/{node}/versions', [TaxonomyNodeController::class, 'newVersion'])->middleware('tenant.ability:catalog.manage')->whereNumber('node');
    Route::get('taxonomy/assignments', [TaxonomyAssignmentController::class, 'index'])->middleware('tenant.ability:data.view');
    Route::post('taxonomy/assignments', [TaxonomyAssignmentController::class, 'store'])->middleware('tenant.ability:catalog.manage');
    Route::patch('taxonomy/assignments/{assignment}', [TaxonomyAssignmentController::class, 'update'])->middleware('tenant.ability:catalog.manage')->whereNumber('assignment');
    Route::post('taxonomy/assignments/{assignment}/verify', [TaxonomyAssignmentController::class, 'verify'])->middleware('tenant.ability:catalog.manage')->whereNumber('assignment');
    Route::delete('taxonomy/assignments/{assignment}', [TaxonomyAssignmentController::class, 'destroy'])->middleware('tenant.ability:catalog.manage')->whereNumber('assignment');

    // ── Perfiles de relevancia sectorial de la empresa ──
    Route::get('sector-profiles', [SectorProfileController::class, 'index'])->middleware('tenant.ability:data.view');
    Route::post('sector-profiles', [SectorProfileController::class, 'store'])->middleware('tenant.ability:settings.manage');
    Route::patch('sector-profiles/{profile}', [SectorProfileController::class, 'update'])->middleware('tenant.ability:settings.manage')->whereNumber('profile');
    Route::delete('sector-profiles/{profile}', [SectorProfileController::class, 'destroy'])->middleware('tenant.ability:settings.manage')->whereNumber('profile');
});

// ── Pronósticos compartidos entre empresas (V2.1 §10): DESACTIVADO por bandera; con ella apagada todas estas rutas responden 404 ──
// EnsureSharedForecastGrantsEnabled va PRIMERO: la ruta «no existe» ni siquiera para quien no tiene sesión.
Route::middleware([EnsureSharedForecastGrantsEnabled::class, 'auth:sanctum', 'throttle:api', 'tenant'])->prefix('intelligence')->group(function (): void {
    Route::middleware('tenant.ability:settings.manage')->group(function (): void {
        Route::get('shared-forecast-grants', [SharedForecastGrantController::class, 'index']);
        Route::post('shared-forecast-grants', [SharedForecastGrantController::class, 'store']);
        Route::get('shared-forecast-grants/{grantId}', [SharedForecastGrantController::class, 'show'])->whereNumber('grantId');
        Route::post('shared-forecast-grants/{grantId}/activate', [SharedForecastGrantController::class, 'activate'])->whereNumber('grantId');
        Route::post('shared-forecast-grants/{grantId}/revoke', [SharedForecastGrantController::class, 'revoke'])->whereNumber('grantId');
        Route::get('shared-forecast-grants/{grantId}/accesses', [SharedForecastGrantController::class, 'accesses'])->whereNumber('grantId');
    });
    Route::middleware('tenant.ability:forecast.view')->group(function (): void {
        Route::get('received-forecast-grants', [SharedForecastGrantController::class, 'received']);
        Route::get('received-forecast-grants/{grantId}/forecast', [SharedForecastGrantController::class, 'forecast'])->whereNumber('grantId');
    });
});

// OPTIONS explícito sobre las mismas URI: sin él, Laravel responde 200 con la cabecera Allow aunque la bandera esté apagada y delata las rutas.
// Con la bandera apagada responde el mismo 404; encendida, 204 con los verbos de cada URI. (Los verbos no registrados —PUT, PATCH, DELETE…— se resuelven
// en bootstrap/app.php: 404 apagada, 405 encendida.)
Route::middleware([EnsureSharedForecastGrantsEnabled::class])->prefix('intelligence')->group(function (): void {
    $allow = [
        'shared-forecast-grants' => 'GET, HEAD, POST, OPTIONS',
        'shared-forecast-grants/{grantId}' => 'GET, HEAD, OPTIONS',
        'shared-forecast-grants/{grantId}/activate' => 'POST, OPTIONS',
        'shared-forecast-grants/{grantId}/revoke' => 'POST, OPTIONS',
        'shared-forecast-grants/{grantId}/accesses' => 'GET, HEAD, OPTIONS',
        'received-forecast-grants' => 'GET, HEAD, OPTIONS',
        'received-forecast-grants/{grantId}/forecast' => 'GET, HEAD, OPTIONS',
    ];
    foreach ($allow as $uri => $verbs) {
        Route::options($uri, fn () => response('', 204, ['Allow' => $verbs]))->whereNumber('grantId');
    }
});
