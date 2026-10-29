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
});
