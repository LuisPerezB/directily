<?php

/*
| Rutas de linaje, identidades y reconciliación (V2.5 · W2-B; ADR-V25-04, ADR-V25-24). Prefijo /api/v1. [T] = requiere empresa activa.
|
| - lineage/bronze              metadatos de los objetos Bronze (data.view); descarga del contenido solo propietario/administrador,
|                               con auditoría (datasources.manage).
| - lineage/reconciliation      informes de reconciliación por carga y por ventana (data.view).
| - catalog/{products,customers}/{id}/aliases y /code-change   alias de identidad y cambio de código (catalog.manage).
|   Fusión y división de identidades: DISEÑADAS, sin rutas.
*/

use App\Http\Controllers\Api\Catalog\CustomerAliasController;
use App\Http\Controllers\Api\Catalog\ProductAliasController;
use App\Http\Controllers\Api\Lineage\BronzeObjectController;
use App\Http\Controllers\Api\Lineage\ReconciliationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api', 'tenant'])->group(function (): void {
    // ── Bronze: metadatos y descarga auditada ──
    Route::get('lineage/bronze', [BronzeObjectController::class, 'index'])->middleware('tenant.ability:data.view');
    Route::get('lineage/bronze/{object}', [BronzeObjectController::class, 'show'])->middleware('tenant.ability:data.view')->whereNumber('object');
    Route::get('lineage/bronze/{object}/content', [BronzeObjectController::class, 'content'])->middleware('tenant.ability:datasources.manage')->whereNumber('object');

    // ── Reconciliación fuente → Bronze → Silver → Gold ──
    Route::get('lineage/reconciliation', [ReconciliationController::class, 'index'])->middleware('tenant.ability:data.view');
    Route::get('lineage/reconciliation/{report}', [ReconciliationController::class, 'show'])->middleware('tenant.ability:data.view')->whereNumber('report');

    // ── Identidades: alias y cambio de código ──
    Route::get('catalog/products/{product}/aliases', [ProductAliasController::class, 'index'])->middleware('tenant.ability:data.view')->whereNumber('product');
    Route::post('catalog/products/{product}/aliases', [ProductAliasController::class, 'store'])->middleware('tenant.ability:catalog.manage')->whereNumber('product');
    Route::delete('catalog/products/{product}/aliases/{alias}', [ProductAliasController::class, 'destroy'])->middleware('tenant.ability:catalog.manage')->whereNumber(['product', 'alias']);
    Route::post('catalog/products/{product}/code-change', [ProductAliasController::class, 'codeChange'])->middleware('tenant.ability:catalog.manage')->whereNumber('product');

    Route::get('catalog/customers/{customer}/aliases', [CustomerAliasController::class, 'index'])->middleware('tenant.ability:data.view')->whereNumber('customer');
    Route::post('catalog/customers/{customer}/aliases', [CustomerAliasController::class, 'store'])->middleware('tenant.ability:catalog.manage')->whereNumber('customer');
    Route::delete('catalog/customers/{customer}/aliases/{alias}', [CustomerAliasController::class, 'destroy'])->middleware('tenant.ability:catalog.manage')->whereNumber(['customer', 'alias']);
    Route::post('catalog/customers/{customer}/code-change', [CustomerAliasController::class, 'codeChange'])->middleware('tenant.ability:catalog.manage')->whereNumber('customer');
});
