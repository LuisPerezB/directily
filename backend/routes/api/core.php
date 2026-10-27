<?php

/*
| Rutas del núcleo (backend-core). Prefijo /api/v1. Ver docs/06 §3.2–3.7 y §3.13.
| [T] = requiere empresa activa (header X-Tenant-ID o token ligado a empresa).
*/

use App\Http\Controllers\Api\Core\AdminController;
use App\Http\Controllers\Api\Core\AuditLogController;
use App\Http\Controllers\Api\Core\AuthController;
use App\Http\Controllers\Api\Core\BranchController;
use App\Http\Controllers\Api\Core\DataSourceController;
use App\Http\Controllers\Api\Core\DemoDataController;
use App\Http\Controllers\Api\Core\ImportController;
use App\Http\Controllers\Api\Core\LeadTimeController;
use App\Http\Controllers\Api\Core\ProductCategoryController;
use App\Http\Controllers\Api\Core\ProductController;
use App\Http\Controllers\Api\Core\PromotionController;
use App\Http\Controllers\Api\Core\SaleController;
use App\Http\Controllers\Api\Core\SupplierController;
use App\Http\Controllers\Api\Core\TenantController;
use App\Http\Controllers\Api\Core\TenantExportController;
use App\Http\Controllers\Api\Core\TenantUserController;
use Illuminate\Support\Facades\Route;

// ── Autenticación ──────────────────────────────────────────────────────────
Route::prefix('auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('password', [AuthController::class, 'changePassword']);
    });
});

// Descarga firmada de exportaciones (enlace temporal; también exige sesión del usuario solicitante).
Route::get('tenant/export/{export}/download', [TenantExportController::class, 'download'])
    ->middleware(['auth:sanctum', 'signed'])
    ->name('tenant.export.download');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('tenants', [TenantController::class, 'index']);
    Route::post('tenants', [TenantController::class, 'store']);

    Route::get('import-templates/{entityType}', [ImportController::class, 'template']);
    Route::get('import-fields/{entityType}', [ImportController::class, 'fieldDefinitions']);

    // ── Plataforma (superadministrador; sin acceso a datos de negocio) ──
    Route::prefix('admin')->middleware('superadmin')->group(function (): void {
        Route::get('tenants', [AdminController::class, 'tenants']);
        Route::patch('tenants/{uuid}', [AdminController::class, 'updateTenant']);
        Route::get('plans', [AdminController::class, 'plans']);
        Route::get('usage', [AdminController::class, 'usage']);
    });

    // Empresas con eliminación solicitada: solo cancelar o exportar.
    Route::middleware('tenant:allow-pending')->group(function (): void {
        Route::post('tenant/cancel-deletion', [TenantController::class, 'cancelDeletion'])->middleware('tenant.ability:tenant.delete');
        Route::post('tenant/export', [TenantExportController::class, 'store'])->middleware('tenant.ability:tenant.export_all');
        Route::get('tenant/export/{export}', [TenantExportController::class, 'show'])->middleware('tenant.ability:tenant.export_all');
    });

    // ── Rutas de empresa [T] ──
    Route::middleware('tenant')->group(function (): void {
        Route::get('tenant', [TenantController::class, 'show']);
        Route::patch('tenant', [TenantController::class, 'update'])->middleware('tenant.ability:settings.manage');
        Route::post('tenant/complete-onboarding', [TenantController::class, 'completeOnboarding'])->middleware('tenant.ability:settings.manage');
        Route::put('tenant/shared-training-consent', [TenantController::class, 'sharedTrainingConsent'])->middleware('tenant.ability:tenant.consent');
        Route::get('tenant/usage', [TenantController::class, 'usage']);
        Route::post('tenant/demo-data', [DemoDataController::class, 'store'])->middleware('tenant.ability:settings.manage');
        Route::delete('tenant', [TenantController::class, 'destroy'])->middleware('tenant.ability:tenant.delete');

        Route::middleware('tenant.ability:users.manage')->group(function (): void {
            Route::get('tenant/users', [TenantUserController::class, 'index']);
            Route::post('tenant/users', [TenantUserController::class, 'store']);
            Route::patch('tenant/users/{user}', [TenantUserController::class, 'update'])->whereNumber('user');
            Route::delete('tenant/users/{user}', [TenantUserController::class, 'destroy'])->whereNumber('user');
        });

        // Catálogos
        Route::get('branches', [BranchController::class, 'index'])->middleware('tenant.ability:data.view');
        Route::get('branches/{branch}', [BranchController::class, 'show'])->middleware('tenant.ability:data.view');
        Route::post('branches', [BranchController::class, 'store'])->middleware('tenant.ability:branches.manage');
        Route::patch('branches/{branch}', [BranchController::class, 'update'])->middleware('tenant.ability:branches.manage');
        Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('tenant.ability:branches.manage');

        Route::get('product-categories', [ProductCategoryController::class, 'index'])->middleware('tenant.ability:data.view');
        Route::post('product-categories', [ProductCategoryController::class, 'store'])->middleware('tenant.ability:catalog.manage');
        Route::patch('product-categories/{productCategory}', [ProductCategoryController::class, 'update'])->middleware('tenant.ability:catalog.manage');

        Route::get('products', [ProductController::class, 'index'])->middleware('tenant.ability:data.view');
        Route::get('products/{product}', [ProductController::class, 'show'])->middleware('tenant.ability:data.view');
        Route::post('products', [ProductController::class, 'store'])->middleware('tenant.ability:catalog.manage');
        Route::patch('products/{product}', [ProductController::class, 'update'])->middleware('tenant.ability:catalog.manage');

        Route::get('suppliers', [SupplierController::class, 'index'])->middleware('tenant.ability:data.view');
        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('tenant.ability:catalog.manage');
        Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('tenant.ability:catalog.manage');

        Route::get('lead-times', [LeadTimeController::class, 'index'])->middleware('tenant.ability:data.view');
        Route::post('lead-times', [LeadTimeController::class, 'store'])->middleware('tenant.ability:catalog.manage');
        Route::delete('lead-times/{leadTime}', [LeadTimeController::class, 'destroy'])->middleware('tenant.ability:catalog.manage');

        Route::get('promotions', [PromotionController::class, 'index'])->middleware('tenant.ability:data.view');
        Route::get('promotions/{promotion}', [PromotionController::class, 'show'])->middleware('tenant.ability:data.view');
        Route::post('promotions', [PromotionController::class, 'store'])->middleware('tenant.ability:catalog.manage');
        Route::patch('promotions/{promotion}', [PromotionController::class, 'update'])->middleware('tenant.ability:catalog.manage');
        Route::delete('promotions/{promotion}', [PromotionController::class, 'destroy'])->middleware('tenant.ability:catalog.manage');

        // Ventas
        Route::get('sales', [SaleController::class, 'index'])->middleware('tenant.ability:data.view');
        Route::get('sales/summary', [SaleController::class, 'summary'])->middleware('tenant.ability:data.view');
        Route::get('sales/{sale}', [SaleController::class, 'show'])->middleware('tenant.ability:data.view')->whereNumber('sale');
        Route::post('sales/batch', [SaleController::class, 'batch'])->middleware(['tenant.ability:imports.manage', 'throttle:ingest']);

        // Importaciones
        Route::get('imports', [ImportController::class, 'index'])->middleware('tenant.ability:data.view');
        Route::get('imports/{import}', [ImportController::class, 'show'])->middleware('tenant.ability:data.view');
        Route::get('imports/{import}/errors', [ImportController::class, 'errors'])->middleware('tenant.ability:data.view');
        Route::get('imports/{import}/errors.csv', [ImportController::class, 'errorsCsv'])->middleware('tenant.ability:data.view');
        Route::middleware('tenant.ability:imports.manage')->group(function (): void {
            Route::post('imports', [ImportController::class, 'store']);
            Route::put('imports/{import}/mapping', [ImportController::class, 'mapping']);
            Route::post('imports/{import}/confirm', [ImportController::class, 'confirm']);
            Route::post('imports/{import}/reject', [ImportController::class, 'reject']);
        });

        // Fuentes de datos y conectores
        Route::get('connectors', [DataSourceController::class, 'connectors'])->middleware('tenant.ability:data.view');
        Route::get('data-sources', [DataSourceController::class, 'index'])->middleware('tenant.ability:data.view');
        Route::middleware('tenant.ability:datasources.manage')->group(function (): void {
            Route::post('data-sources', [DataSourceController::class, 'store']);
            Route::patch('data-sources/{dataSource}', [DataSourceController::class, 'update']);
            Route::delete('data-sources/{dataSource}', [DataSourceController::class, 'destroy']);
            Route::post('data-sources/{dataSource}/tokens', [DataSourceController::class, 'createToken']);
            Route::delete('data-sources/{dataSource}/tokens/{tokenId}', [DataSourceController::class, 'revokeToken'])->whereNumber('tokenId');
            Route::post('data-sources/{dataSource}/test', [DataSourceController::class, 'test']);
        });

        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('tenant.ability:audit.view');
    });
});
