<?php

/*
|--------------------------------------------------------------------------
| Rutas analíticas (backend-analytics) — /api/v1
|--------------------------------------------------------------------------
| Contrato: docs/06-contratos-integracion.md §3.8–3.12. Todas requieren sesión o token
| (auth:sanctum), empresa activa (tenant) y la habilidad indicada por rol.
| Se usan ids explícitos (no route-model binding) porque el contexto de empresa se
| establece en el middleware "tenant", después de la sustitución de bindings.
*/

use App\Http\Controllers\Api\Analytics\AccuracyController;
use App\Http\Controllers\Api\Analytics\AlertController;
use App\Http\Controllers\Api\Analytics\DashboardController;
use App\Http\Controllers\Api\Analytics\ExportController;
use App\Http\Controllers\Api\Analytics\ForecastController;
use App\Http\Controllers\Api\Analytics\ForecastRunController;
use App\Http\Controllers\Api\Analytics\InventoryController;
use App\Http\Controllers\Api\Analytics\ReportController;
use App\Http\Controllers\Api\Analytics\ScenarioController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->name('analytics.')->group(function (): void {
    // Ejecuciones y trabajos
    Route::post('forecast-runs', [ForecastRunController::class, 'store'])
        ->middleware(['tenant.ability:forecast.run', 'throttle:20,1'])->name('forecast-runs.store');
    Route::get('forecast-runs', [ForecastRunController::class, 'index'])
        ->middleware('tenant.ability:forecast.view')->name('forecast-runs.index');
    Route::get('forecast-runs/{id}', [ForecastRunController::class, 'show'])->whereNumber('id')
        ->middleware('tenant.ability:forecast.view')->name('forecast-runs.show');
    Route::get('jobs/{id}', [ForecastRunController::class, 'job'])->whereNumber('id')
        ->middleware('tenant.ability:forecast.view')->name('jobs.show');

    Route::middleware('tenant.ability:forecast.view')->group(function (): void {
        // Pronósticos
        Route::get('forecasts/series', [ForecastController::class, 'series'])->name('forecasts.series');
        Route::get('forecasts/products', [ForecastController::class, 'products'])->name('forecasts.products');
        Route::get('forecasts/categories', [ForecastController::class, 'categories'])->name('forecasts.categories');
        Route::get('dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');

        // Precisión
        Route::get('accuracy/summary', [AccuracyController::class, 'summary'])->name('accuracy.summary');
        Route::get('accuracy/comparison', [AccuracyController::class, 'comparison'])->name('accuracy.comparison');
        Route::get('model-evaluations', [AccuracyController::class, 'evaluations'])->name('model-evaluations.index');
        Route::get('models', [AccuracyController::class, 'models'])->name('models.index');

        // Inventario
        Route::get('inventory/recommendations', [InventoryController::class, 'recommendations'])->name('inventory.recommendations');
        Route::get('inventory/summary', [InventoryController::class, 'summary'])->name('inventory.summary');

        // Reportes
        Route::get('reports/forecast-summary', [ReportController::class, 'forecastSummary'])->name('reports.forecast-summary');
    });

    Route::patch('inventory/recommendations/{id}', [InventoryController::class, 'update'])->whereNumber('id')
        ->middleware('tenant.ability:alerts.manage')->name('inventory.recommendations.update');

    // Alertas
    Route::get('alerts', [AlertController::class, 'index'])
        ->middleware('tenant.ability:alerts.view')->name('alerts.index');
    Route::patch('alerts/{id}', [AlertController::class, 'update'])->whereNumber('id')
        ->middleware('tenant.ability:alerts.manage')->name('alerts.update');

    // Escenarios
    Route::post('scenarios/simulate', [ScenarioController::class, 'simulate'])
        ->middleware(['tenant.ability:scenarios.run', 'throttle:60,1'])->name('scenarios.simulate');
    Route::get('scenarios', [ScenarioController::class, 'index'])
        ->middleware('tenant.ability:forecast.view')->name('scenarios.index');
    Route::post('scenarios', [ScenarioController::class, 'store'])
        ->middleware('tenant.ability:scenarios.run')->name('scenarios.store');
    Route::get('scenarios/{id}', [ScenarioController::class, 'show'])->whereNumber('id')
        ->middleware('tenant.ability:forecast.view')->name('scenarios.show');
    Route::delete('scenarios/{id}', [ScenarioController::class, 'destroy'])->whereNumber('id')
        ->middleware('tenant.ability:scenarios.run')->name('scenarios.destroy');

    // Exportaciones CSV
    Route::middleware(['tenant.ability:data.export', 'throttle:30,1'])->prefix('exports')->name('exports.')->group(function (): void {
        Route::get('forecasts.csv', [ExportController::class, 'forecasts'])->name('forecasts');
        Route::get('recommendations.csv', [ExportController::class, 'recommendations'])->name('recommendations');
        Route::get('accuracy.csv', [ExportController::class, 'accuracy'])->name('accuracy');
        Route::get('sales-summary.csv', [ExportController::class, 'salesSummary'])->name('sales-summary');
    });
});
