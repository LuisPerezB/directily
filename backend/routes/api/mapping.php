<?php

/*
| Descubrimiento de esquema y Auto Mapper (V2.5 · W6-C; §4). Prefijo /api/v1. [T] = requiere empresa activa.
| Contrato: docs/integrations/AUTO_MAPPING.md y docs/openapi/v2.yaml (bloque «W6-C»). Predictily propone y audita: NO escribe en el ERP, NO aprueba por sí solo y
| NO inicia sincronizaciones. La propuesta es determinista, con puntajes heurísticos (no probabilidades).
|
| Empresa [T]:
| - POST data-sources/{id}/schema/discover                       metadatos del catálogo del origen (datasources.manage)
| - POST data-sources/{id}/mapping-proposals                     propone el mapeo por entidad y lo guarda como plantilla `proposed` (datasources.manage)
| - GET  mapping-templates · GET mapping-templates/{id}          plantillas de la empresa y públicas de la plataforma (datasources.manage)
| - PATCH mapping-templates/{id}                                 corrección humana de una propuesta (datasources.manage); pública → 403
| - POST mapping-templates/{id}/revise · /fork                   otra versión · copia propia de una pública (datasources.manage)
| - POST mapping-templates/{id}/approve · /retire                aprobación y retiro humanos y auditados (settings.manage)
| - POST data-sources/{id}/mapping-templates/{id}/drift          deriva de esquema contra la fuente, sin aplicar (datasources.manage)
| - POST data-sources/{id}/mapping-templates/{id}/apply          aplica una plantilla aprobada: crea el mapeo SQL activo (datasources.manage + settings.manage)
| Plataforma (superadministrador, sin empresa): /admin/mapping-templates (alta, edición, aprobación y retiro de plantillas públicas).
*/

use App\Http\Controllers\Api\DataCore\MappingTemplateController;
use App\Http\Controllers\Api\DataCore\PlatformMappingTemplateController;
use App\Http\Controllers\Api\DataCore\SchemaDiscoveryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api', 'tenant'])->group(function (): void {
    Route::middleware('tenant.ability:datasources.manage')->group(function (): void {
        Route::post('data-sources/{dataSource}/schema/discover', [SchemaDiscoveryController::class, 'discover'])->middleware('throttle:ingest');
        Route::post('data-sources/{dataSource}/mapping-proposals', [SchemaDiscoveryController::class, 'propose'])->middleware('throttle:ingest');
        Route::post('data-sources/{dataSource}/mapping-templates/{template}/drift', [MappingTemplateController::class, 'drift'])->middleware('throttle:ingest')->whereNumber('template');

        Route::get('mapping-templates', [MappingTemplateController::class, 'index']);
        Route::get('mapping-templates/{template}', [MappingTemplateController::class, 'show'])->whereNumber('template');
        Route::patch('mapping-templates/{template}', [MappingTemplateController::class, 'update'])->whereNumber('template');
        Route::post('mapping-templates/{template}/revise', [MappingTemplateController::class, 'revise'])->whereNumber('template');
        Route::post('mapping-templates/{template}/fork', [MappingTemplateController::class, 'fork'])->whereNumber('template');
    });

    // Aprobar o retirar una plantilla es una decisión de administración: habilidad de ajustes (sensible para MFA, W6-A).
    Route::middleware('tenant.ability:settings.manage')->group(function (): void {
        Route::post('mapping-templates/{template}/approve', [MappingTemplateController::class, 'approve'])->whereNumber('template');
        Route::post('mapping-templates/{template}/retire', [MappingTemplateController::class, 'retire'])->whereNumber('template');
    });

    // Aplicar activa un mapeo que ejecutará una consulta contra el ERP del cliente: exige la habilidad de fuentes Y la de ajustes.
    Route::post('data-sources/{dataSource}/mapping-templates/{template}/apply', [MappingTemplateController::class, 'apply'])
        ->middleware(['tenant.ability:datasources.manage,settings.manage', 'throttle:ingest'])->whereNumber('template');
});

// Plantillas públicas de la plataforma: superadministrador, sin empresa activa.
Route::middleware(['auth:sanctum', 'throttle:api', 'superadmin'])->prefix('admin/mapping-templates')->group(function (): void {
    Route::get('/', [PlatformMappingTemplateController::class, 'index']);
    Route::post('/', [PlatformMappingTemplateController::class, 'store']);
    Route::get('{id}', [PlatformMappingTemplateController::class, 'show'])->whereNumber('id');
    Route::patch('{id}', [PlatformMappingTemplateController::class, 'update'])->whereNumber('id');
    Route::post('{id}/approve', [PlatformMappingTemplateController::class, 'approve'])->whereNumber('id');
    Route::post('{id}/retire', [PlatformMappingTemplateController::class, 'retire'])->whereNumber('id');
});
