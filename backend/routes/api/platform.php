<?php

/*
| Rutas del core platform (etapa 2 · P3): configuración efectiva por precedencia y extensiones. Prefijo /api/v1. [T] = empresa activa.
| Contrato: docs/architecture/v2/P3-datos.md §8.
*/

use App\Http\Controllers\Api\DataCore\EffectiveConfigController;
use App\Http\Controllers\Api\DataCore\ExtensionsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api', 'tenant'])->group(function (): void {
    Route::get('tenant/effective-config', [EffectiveConfigController::class, 'show'])->middleware('tenant.ability:data.view');
    Route::put('tenant/core-config/{core}', [EffectiveConfigController::class, 'update'])->middleware('tenant.ability:settings.manage');
    Route::get('platform/extensions', [ExtensionsController::class, 'show'])->middleware('tenant.ability:data.view');
});
