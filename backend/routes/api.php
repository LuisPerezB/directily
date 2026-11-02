<?php

/*
|--------------------------------------------------------------------------
| API pública de Predictily — /api/v1
|--------------------------------------------------------------------------
| Contrato: docs/06-contratos-integracion.md y docs/openapi/predictily-api.yaml
|
| - routes/api/core.php       auth, empresas, usuarios, catálogos, ventas, importaciones,
|                             fuentes de datos, auditoría, administración (plataforma).
| - routes/api/analytics.php  pronósticos, dashboard, precisión, inventario,
|                             alertas, escenarios, exportaciones, trabajos.
*/

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    require __DIR__.'/api/core.php';
    require __DIR__.'/api/analytics.php';
    // Etapa 2 (docs/architecture/PREDICTILY_V2_GAP_ANALYSIS.md §5): un archivo por paquete.
    require __DIR__.'/api/datacore.php';
    require __DIR__.'/api/customer.php';
    require __DIR__.'/api/supply.php';
    require __DIR__.'/api/platform.php';
    // V2.5 · W2-F: Factor Intelligence y taxonomía (catálogo de factores, eventos, taxonomía, perfiles; concesiones desactivadas por bandera).
    require __DIR__.'/api/intelligence.php';
    // V2.5 · W2-B: linaje (Bronze), reconciliación e identidades (alias).
    require __DIR__.'/api/lineage.php';
    // V2.5 · W4-A: ciclo de vida del registro de modelos (detalle, validar, aprobar, rechazar, promover).
    require __DIR__.'/api/models.php';
    // V2.5 · W5-A: Revenue Radar y Agenda Comercial (fachadas V2.2: radar, ficha del comprador, pronósticos agregados y preparación de abastecimiento).
    require __DIR__.'/api/radar.php';
    // V2.5 · W5-B: Decision Engine y workflow (recomendaciones, transiciones con segregación e idempotencia, parámetros de la empresa).
    require __DIR__.'/api/decision.php';
});
