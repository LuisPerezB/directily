<?php

/*
| Rutas del core platform (etapa 2 · P3): configuración efectiva por precedencia y extensiones. Prefijo /api/v1. [T] = empresa activa.
| Contrato: docs/architecture/v2/P3-datos.md §8.
*/

use App\Http\Controllers\Api\Conversation\AssistantController;
use App\Http\Controllers\Api\DataCore\EffectiveConfigController;
use App\Http\Controllers\Api\DataCore\ExtensionsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api', 'tenant'])->group(function (): void {
    Route::get('tenant/effective-config', [EffectiveConfigController::class, 'show'])->middleware('tenant.ability:data.view');
    Route::put('tenant/core-config/{core}', [EffectiveConfigController::class, 'update'])->middleware('tenant.ability:settings.manage');
    Route::get('platform/extensions', [ExtensionsController::class, 'show'])->middleware('tenant.ability:data.view');
});

/*
| Asistente conversacional OPCIONAL (etapa 2 · P5b, ADR-V2-12). Prefijo /api/v1/assistant. [T] = empresa activa.
| `data.view`; cada herramienta exige además la habilidad de la ruta de resultados equivalente. 503 assistant_disabled sin proveedor.
| Su ausencia no afecta a ninguna otra ruta. Contrato: docs/architecture/v2/P5b-plataforma.md y docs/openapi/v2.yaml.
*/
Route::middleware(['auth:sanctum', 'throttle:api', 'tenant'])->prefix('assistant')->name('assistant.')->group(function (): void {
    Route::get('status', [AssistantController::class, 'status'])->middleware('tenant.ability:data.view')->name('status');
    Route::post('messages', [AssistantController::class, 'message'])->middleware('tenant.ability:data.view')->name('messages');
});
