<?php

/*
| Ciclo de vida del registro de modelos (V2.5 · W4; ADR-V25-07). Prefijo /api/v1. [T] = requiere empresa activa.
|
| - GET  models/{id}            detalle con eventos de auditoría (forecast.view). La lista GET models vive en analytics.php.
| - POST models/{id}/validate   candidate → validated (compuertas automáticas; forecast.run).
| - POST models/{id}/approve    validated → approved (models.approve; aprobador ≠ publicador/entrenador; firma y gate de datos).
| - POST models/{id}/reject     → rejected con motivo obligatorio (models.approve; misma segregación).
| - POST models/{id}/promote    approved → production (models.approve).
| Las reversiones (models/demand/rollback, models/customer/rollback) siguen en sus archivos y exigen modelos aprobados o legados.
*/

use App\Http\Controllers\Api\Models\ModelRegistryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api', 'tenant'])->name('models.')->group(function (): void {
    Route::get('models/{id}', [ModelRegistryController::class, 'show'])->whereNumber('id')
        ->middleware('tenant.ability:forecast.view')->name('show');
    Route::post('models/{id}/validate', [ModelRegistryController::class, 'validateModel'])->whereNumber('id')
        ->middleware(['tenant.ability:forecast.run', 'throttle:20,1'])->name('validate');
    Route::post('models/{id}/approve', [ModelRegistryController::class, 'approve'])->whereNumber('id')
        ->middleware(['tenant.ability:models.approve', 'throttle:20,1'])->name('approve');
    Route::post('models/{id}/reject', [ModelRegistryController::class, 'reject'])->whereNumber('id')
        ->middleware(['tenant.ability:models.approve', 'throttle:20,1'])->name('reject');
    Route::post('models/{id}/promote', [ModelRegistryController::class, 'promote'])->whereNumber('id')
        ->middleware(['tenant.ability:models.approve', 'throttle:10,1'])->name('promote');
});
