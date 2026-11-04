<?php

/*
| Decision Engine y workflow (V2.5 · W5-B; ADR-V25-13). Prefijo /api/v1. [T] = requiere empresa activa.
| Contrato: docs/decision/DECISION_ENGINE.md y docs/openapi/v2.yaml. Predictily propone y audita: NO escribe en el ERP ni envía mensajes.
|
| - GET  decisions                    lista con filtros (decisions.view). Las del grupo de control no se listan salvo include_control de quien aprueba.
| - GET  decisions/summary            conteos por estado, tipo y etiqueta de atribución (decisions.view).
| - GET  decisions/settings           parámetros del Decision Engine de la empresa y versión de la fórmula (decisions.view).
| - PUT  decisions/settings           actualiza los parámetros, incluida la fracción de control del experimento (settings.manage).
| - GET  decisions/{id}               detalle con contrato completo, línea de tiempo y transiciones disponibles (decisions.view).
| - POST decisions/{id}/transition    transición del workflow con idempotency_key (decisions.manage como mínimo; approve/reject exigen decisions.approve
|                                     y segregación de funciones; el workflow lo decide por transición).
| Rutas de outcomes y ROI (W5-C) se agregan al final de este archivo.
|
| Outcomes y ROI honestos (V2.5 · W5-C; ADR-V25-14), SOLO LECTURA. Contrato: docs/decision/OUTCOME_MEASUREMENT.md y docs/openapi/v2.yaml.
| - GET decisions/outcomes  resultados medidos por recomendación, con filtros y paginado (decisions.view). El grupo de control solo lo lista quien aprueba.
| - GET decisions/roi       ROI honesto con su método: potencial vs observado vs incremento estimado (ITT), costos y límites (decisions.view).
*/

use App\Http\Controllers\Api\Decision\DecisionController;
use App\Http\Controllers\Api\Decision\DecisionSettingsController;
use App\Http\Controllers\Api\Decision\OutcomeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api', 'tenant'])->prefix('decisions')->name('decisions.')->group(function (): void {
    Route::middleware('tenant.ability:decisions.view')->group(function (): void {
        Route::get('/', [DecisionController::class, 'index'])->name('index');
        Route::get('summary', [DecisionController::class, 'summary'])->name('summary');
        Route::get('settings', [DecisionSettingsController::class, 'show'])->name('settings.show');
        Route::get('{id}', [DecisionController::class, 'show'])->whereNumber('id')->name('show');
    });
    Route::put('settings', [DecisionSettingsController::class, 'update'])->middleware('tenant.ability:settings.manage')->name('settings.update');
    Route::post('{id}/transition', [DecisionController::class, 'transition'])->whereNumber('id')
        // V2.5 · W6-A: aprobar o rechazar formalmente (to_status approved|rejected) exige un segundo factor fresco a quien tiene MFA activo.
        ->middleware(['tenant.ability:decisions.manage', 'mfa.step_up:approval', 'throttle:120,1'])->name('transition');
});

// >>> W5-C outcomes y ROI (rutas de outcomes: las agrega W5-C debajo de esta línea)
Route::middleware(['auth:sanctum', 'throttle:api', 'tenant', 'tenant.ability:decisions.view'])->prefix('decisions')->name('decisions.')->group(function (): void {
    Route::get('outcomes', [OutcomeController::class, 'index'])->name('outcomes.index');
    Route::get('roi', [OutcomeController::class, 'roi'])->name('outcomes.roi');
});
