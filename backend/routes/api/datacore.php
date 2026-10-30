<?php

/*
| Rutas del core de Datos (etapa 2 · P3). Prefijo /api/v1. [T] = requiere empresa activa.
| Contrato: docs/architecture/v2/P3-datos.md y docs/06-contratos-integracion.md §2.9, §3.14.
*/

use App\Http\Controllers\Api\DataCore\CalendarEventController;
use App\Http\Controllers\Api\DataCore\CommitmentController;
use App\Http\Controllers\Api\DataCore\DataCoverageController;
use App\Http\Controllers\Api\DataCore\DataQualityController;
use App\Http\Controllers\Api\DataCore\SourceMappingController;
use App\Http\Controllers\Api\DataCore\SourceSyncController;
use App\Http\Controllers\Api\DataCore\UnitConversionController;
use App\Http\Controllers\Api\DataCore\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api', 'tenant'])->group(function (): void {
    // ── Fuentes SQL de solo lectura: consultas autorizadas y sincronización ──
    Route::middleware('tenant.ability:datasources.manage')->group(function (): void {
        Route::get('data-sources/{dataSource}/mappings', [SourceMappingController::class, 'index']);
        Route::post('data-sources/{dataSource}/mappings', [SourceMappingController::class, 'store']);
        Route::get('data-sources/{dataSource}/mappings/{mapping}', [SourceMappingController::class, 'show']);
        Route::post('data-sources/{dataSource}/mappings/{mapping}/preview', [SourceMappingController::class, 'preview']);
        Route::post('data-sources/{dataSource}/mappings/{mapping}/approve', [SourceMappingController::class, 'approve']);
        Route::post('data-sources/{dataSource}/mappings/{mapping}/deactivate', [SourceMappingController::class, 'deactivate']);
        Route::post('data-sources/{dataSource}/sync', [SourceSyncController::class, 'store'])->middleware('throttle:ingest');
        Route::get('data-sources/{dataSource}/syncs', [SourceSyncController::class, 'index']);
        Route::get('syncs/{sync}', [SourceSyncController::class, 'show'])->whereNumber('sync');
    });

    // ── Cobertura de fuentes y calidad ampliada ──
    Route::get('data-coverage', [DataCoverageController::class, 'index'])->middleware('tenant.ability:data.view');
    Route::put('data-coverage', [DataCoverageController::class, 'update'])->middleware('tenant.ability:imports.manage');
    Route::get('data-quality/extended', [DataQualityController::class, 'extended'])->middleware('tenant.ability:data.view');

    // ── Calendario con alcance y estado de conocimiento ──
    Route::get('calendar-events', [CalendarEventController::class, 'index'])->middleware('tenant.ability:data.view');
    Route::middleware('tenant.ability:catalog.manage')->group(function (): void {
        Route::post('calendar-events', [CalendarEventController::class, 'store']);
        Route::patch('calendar-events/{id}', [CalendarEventController::class, 'update'])->whereNumber('id');
        Route::delete('calendar-events/{id}', [CalendarEventController::class, 'destroy'])->whereNumber('id');
    });

    // ── Compromisos comerciales (no son ventas) ──
    Route::get('commitments', [CommitmentController::class, 'index'])->middleware('tenant.ability:data.view');
    Route::get('commitments/{commitment}', [CommitmentController::class, 'show'])->middleware('tenant.ability:data.view')->whereNumber('commitment');
    Route::middleware('tenant.ability:imports.manage')->group(function (): void {
        Route::post('commitments', [CommitmentController::class, 'store']);
        Route::post('commitments/{commitment}/cancel', [CommitmentController::class, 'cancel'])->whereNumber('commitment');
    });

    // ── Almacenes y conversiones de unidades ──
    Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('tenant.ability:data.view');
    Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('tenant.ability:catalog.manage');
    Route::get('unit-conversions', [UnitConversionController::class, 'index'])->middleware('tenant.ability:data.view');
    Route::post('unit-conversions', [UnitConversionController::class, 'store'])->middleware('tenant.ability:catalog.manage');
    Route::delete('unit-conversions/{unitConversion}', [UnitConversionController::class, 'destroy'])->middleware('tenant.ability:catalog.manage')->whereNumber('unitConversion');
});
