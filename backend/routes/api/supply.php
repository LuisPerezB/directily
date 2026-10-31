<?php

/*
|--------------------------------------------------------------------------
| Rutas de decisiones comunes — /api/v1/supply  (etapa 2, paquete P4)
|--------------------------------------------------------------------------
| Contrato: docs/06-contratos-integracion.md §3.14 y docs/architecture/v2/P4-decisiones.md.
| auth:sanctum + empresa activa (tenant) + habilidad por rol:
|   forecast.view  leer planes, pronósticos de costos, simulaciones, evaluaciones y configuración;
|   forecast.run   solicitar planes, pronósticos de costos, simulaciones, evaluaciones y verificación causal;
|   catalog.manage escribir los datos de configuración (ofertas, tasas, capacidades, presupuestos, recursos, recetas).
| Se usan ids explícitos (no route-model binding): el contexto de empresa se fija en el middleware «tenant».
| Las recomendaciones son sugerencias: Predictily no envía pedidos ni compromete fondos.
*/

use App\Http\Controllers\Api\Supply\BudgetController;
use App\Http\Controllers\Api\Supply\CapacityController;
use App\Http\Controllers\Api\Supply\CausalController;
use App\Http\Controllers\Api\Supply\CostForecastController;
use App\Http\Controllers\Api\Supply\ExchangeRateController;
use App\Http\Controllers\Api\Supply\OfferController;
use App\Http\Controllers\Api\Supply\PlanController;
use App\Http\Controllers\Api\Supply\ProductionRecipeController;
use App\Http\Controllers\Api\Supply\ProductionResourceController;
use App\Http\Controllers\Api\Supply\ScenarioEvaluationController;
use App\Http\Controllers\Api\Supply\SimulationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->prefix('supply')->name('supply.')->group(function (): void {
    // ── Solicitar cálculos (forecast.run) ──
    Route::middleware('tenant.ability:forecast.run')->group(function (): void {
        Route::post('plans', [PlanController::class, 'store'])->middleware('throttle:20,1')->name('plans.store');
        Route::post('cost-forecasts', [CostForecastController::class, 'store'])->middleware('throttle:20,1')->name('cost-forecasts.store');
        Route::post('simulations', [SimulationController::class, 'store'])->middleware('throttle:10,1')->name('simulations.store');
        Route::post('scenarios/evaluate', [ScenarioEvaluationController::class, 'evaluate'])->middleware('throttle:30,1')->name('scenarios.evaluate');
        Route::post('causal/check', [CausalController::class, 'check'])->middleware('throttle:30,1')->name('causal.check');
    });

    // ── Consultar resultados y configuración (forecast.view) ──
    Route::middleware('tenant.ability:forecast.view')->group(function (): void {
        Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
        Route::get('plans/{id}', [PlanController::class, 'show'])->whereNumber('id')->name('plans.show');
        Route::get('cost-forecasts', [CostForecastController::class, 'index'])->name('cost-forecasts.index');
        Route::get('cost-forecasts/runs', [CostForecastController::class, 'runs'])->name('cost-forecasts.runs');
        Route::get('simulations', [SimulationController::class, 'index'])->name('simulations.index');
        Route::get('simulations/{id}', [SimulationController::class, 'show'])->whereNumber('id')->name('simulations.show');
        Route::get('scenarios/evaluations', [ScenarioEvaluationController::class, 'index'])->name('scenarios.index');
        Route::get('scenarios/evaluations/{id}', [ScenarioEvaluationController::class, 'show'])->whereNumber('id')->name('scenarios.show');

        foreach ([
            'offers' => OfferController::class, 'exchange-rates' => ExchangeRateController::class, 'capacities' => CapacityController::class,
            'budgets' => BudgetController::class, 'production/resources' => ProductionResourceController::class,
            'production/recipes' => ProductionRecipeController::class,
        ] as $path => $controller) {
            $name = str_replace('/', '.', $path);
            Route::get($path, [$controller, 'index'])->name("$name.index");
            Route::get("$path/{id}", [$controller, 'show'])->whereNumber('id')->name("$name.show");
        }
    });

    // ── Escribir configuración (catalog.manage) ──
    Route::middleware('tenant.ability:catalog.manage')->group(function (): void {
        foreach ([
            'offers' => OfferController::class, 'exchange-rates' => ExchangeRateController::class, 'capacities' => CapacityController::class,
            'budgets' => BudgetController::class, 'production/resources' => ProductionResourceController::class,
            'production/recipes' => ProductionRecipeController::class,
        ] as $path => $controller) {
            $name = str_replace('/', '.', $path);
            Route::post($path, [$controller, 'store'])->name("$name.store");
            Route::patch("$path/{id}", [$controller, 'update'])->whereNumber('id')->name("$name.update");
            Route::delete("$path/{id}", [$controller, 'destroy'])->whereNumber('id')->name("$name.destroy");
        }
    });
});
