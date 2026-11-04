<?php

/*
|--------------------------------------------------------------------------
| Revenue Radar y Agenda Comercial — /api/v1  (V2.5 · W5-A; ADR-V25-12; V25-AGD-001 a -021)
|--------------------------------------------------------------------------
| Fachadas V2.2 sobre Customer Core y el Decision Engine, sin duplicar lógica. Contrato: docs/06-contratos-integracion.md §3.17 y
| docs/product/LEGACY_API_COMPATIBILITY.md. auth:sanctum + empresa activa (tenant) + habilidad por rol (solo las existentes):
|   forecast.view  leer resumen, oportunidades, agenda, ficha del comprador, pronósticos agregados y preparación de abastecimiento;
|   decisions.manage  registrar seguimiento (feedback) de una oportunidad (analista, administrador, propietario; el viewer no). Es la habilidad de W5-B para gestionar decisiones;
|   forecast.run   proponer al Decision Engine las oportunidades de la predicción vigente.
| Alcance comercial (V2.5 · W6-A, antes TODO(W6)): el vendedor ve y toca solo su cartera y lo que le asignaron. Las listas, el resumen y la agenda se acotan en PredictiveSalesAgenda
| (CommercialScope); las rutas con id (oportunidad, ficha del comprador) responden 404 fuera del alcance (middleware EnforceCommercialScope, que corre tras `tenant`) y la
| materialización es de equipo (403 con alcance restringido). `seller_id` es solo un filtro que se compone con el alcance. Ver docs/security/ENTERPRISE_SECURITY.md.
| /models/evaluations ya existe (W3-D, analytics.php) y se reutiliza tal cual. Se usan ids explícitos (no route-model binding): la empresa se fija en el middleware.
| Predictily no envía mensajes ni escribe en el ERP: toda gestión es humana y queda auditada.
*/

use App\Http\Controllers\Api\Radar\CustomerFichaController;
use App\Http\Controllers\Api\Radar\DemandForecastController;
use App\Http\Controllers\Api\Radar\RadarAgendaController;
use App\Http\Controllers\Api\Radar\RadarOpportunityController;
use App\Http\Controllers\Api\Radar\RadarSummaryController;
use App\Http\Controllers\Api\Radar\SupplyReadinessController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->name('radar.')->group(function (): void {
    Route::middleware('tenant.ability:forecast.view')->group(function (): void {
        Route::get('radar/summary', [RadarSummaryController::class, 'show'])->name('summary');
        Route::get('radar/opportunities', [RadarOpportunityController::class, 'index'])->name('opportunities.index');
        Route::get('radar/opportunities/{id}', [RadarOpportunityController::class, 'show'])->whereNumber('id')->name('opportunities.show');
        Route::get('radar/agenda', [RadarAgendaController::class, 'index'])->name('agenda');

        Route::get('customers/{id}/predictions', [CustomerFichaController::class, 'predictions'])->whereNumber('id')->name('customers.predictions');
        Route::get('customers/{id}/demand', [CustomerFichaController::class, 'demand'])->whereNumber('id')->name('customers.demand');
        Route::get('customers/{id}/products', [CustomerFichaController::class, 'products'])->whereNumber('id')->name('customers.products');
        Route::get('customers/{id}/overview', [CustomerFichaController::class, 'overview'])->whereNumber('id')->name('customers.overview');

        Route::get('demand/forecasts', [DemandForecastController::class, 'index'])->name('demand.forecasts');
        Route::get('supply/readiness', [SupplyReadinessController::class, 'index'])->name('supply.readiness');
    });

    Route::post('radar/opportunities/materialize', [RadarOpportunityController::class, 'materialize'])
        ->middleware(['tenant.ability:forecast.run', 'throttle:10,1'])->name('opportunities.materialize');
    Route::post('radar/opportunities/{id}/feedback', [RadarOpportunityController::class, 'feedback'])->whereNumber('id')
        // V2.5 · W6-A: «descartado» sobre una oportunidad revisada la rechaza formalmente (decisions.approve): con MFA activo exige un segundo factor fresco.
        ->middleware(['tenant.ability:decisions.manage', 'mfa.step_up:approval', 'throttle:60,1'])->name('opportunities.feedback');
});
