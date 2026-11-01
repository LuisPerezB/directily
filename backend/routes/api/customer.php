<?php

/*
|--------------------------------------------------------------------------
| Rutas del Customer Core (comportamiento de compra) — /api/v1/customer  (etapa 2, paquete P2)
|--------------------------------------------------------------------------
| Contrato: docs/06-contratos-integracion.md §3.13 y docs/architecture/v2/P2-comportamiento.md.
| auth:sanctum + empresa activa (tenant) + habilidad por rol: forecast.view (leer) / forecast.run (entrenar y predecir).
| Se usan ids explícitos (no route-model binding): el contexto de empresa se fija en el middleware «tenant».
*/

use App\Http\Controllers\Api\Customer\CustomerEvaluationController;
use App\Http\Controllers\Api\Customer\CustomerModelRollbackController;
use App\Http\Controllers\Api\Customer\CustomerPredictionController;
use App\Http\Controllers\Api\Customer\CustomerProfileController;
use App\Http\Controllers\Api\Customer\CustomerReconciliationController;
use App\Http\Controllers\Api\Customer\CustomerRunController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->prefix('customer')->name('customer.')->group(function (): void {
    Route::post('runs', [CustomerRunController::class, 'store'])
        ->middleware(['tenant.ability:forecast.run', 'throttle:20,1'])->name('runs.store');

    Route::middleware('tenant.ability:forecast.view')->group(function (): void {
        Route::get('runs', [CustomerRunController::class, 'index'])->name('runs.index');
        Route::get('runs/{id}', [CustomerRunController::class, 'show'])->whereNumber('id')->name('runs.show');
        Route::get('predictions', [CustomerPredictionController::class, 'index'])->name('predictions.index');
        Route::get('customers/{id}/profile', [CustomerProfileController::class, 'show'])->whereNumber('id')->name('customers.profile');
        Route::get('evaluation', [CustomerEvaluationController::class, 'show'])->name('evaluation.show');
        Route::get('reconciliation', [CustomerReconciliationController::class, 'index'])->name('reconciliation.index');
    });
});

// Reversión del modelo servido del Customer Core (ADR-V2-07): re-sirve una versión validada/retirada del mismo horizonte y objetivo.
Route::middleware(['auth:sanctum', 'tenant'])->post('models/customer/rollback', [CustomerModelRollbackController::class, 'store'])
    ->middleware(['tenant.ability:forecast.run', 'throttle:10,1'])->name('models.customer.rollback');
