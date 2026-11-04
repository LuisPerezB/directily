<?php

/*
|--------------------------------------------------------------------------
| Intelligence Gateway (V2.5 · W6-D; ADR-V25-17). Prefijo /api/v1. [T] = requiere empresa activa.
|--------------------------------------------------------------------------
| Contrato: docs/architecture/INTELLIGENCE_GATEWAY.md y docs/openapi/v2.yaml. Las rutas del asistente (assistant/status y assistant/messages) siguen en platform.php.
|
| - GET  gateway/tool-calls                    auditoría de las llamadas a herramientas, sin PII (audit.view).
| - GET  gateway/channels/{channel}            canal de mensajería y sus plantillas (decisions.view). 404 si el canal no existe.
| - POST gateway/channels/{channel}/drafts     crea un BORRADOR desde una plantilla; no envía nada (decisions.manage).
| - POST gateway/channels/{channel}/drafts/{draft}/confirm   confirmación humana explícita del texto exacto (decisions.manage).
| WhatsApp es SOLO un contrato: sin un proveedor registrado en el ChannelRegistry todas las rutas de canal responden 404 y Predictily no envía ningún mensaje.
*/

use App\Http\Controllers\Api\Conversation\ChannelController;
use App\Http\Controllers\Api\Conversation\GatewayAuditController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api', 'tenant'])->group(function (): void {
    Route::get('gateway/tool-calls', [GatewayAuditController::class, 'index'])->middleware('tenant.ability:audit.view')->name('gateway.tool-calls');

    Route::prefix('gateway/channels')->name('gateway.channels.')->group(function (): void {
        Route::get('{channel}', [ChannelController::class, 'show'])->middleware('tenant.ability:decisions.view')->name('show');
        Route::post('{channel}/drafts', [ChannelController::class, 'createDraft'])->middleware(['tenant.ability:decisions.manage', 'throttle:30,1'])->name('drafts.store');
        Route::post('{channel}/drafts/{draft}/confirm', [ChannelController::class, 'confirm'])->whereUuid('draft')
            ->middleware(['tenant.ability:decisions.manage', 'throttle:30,1'])->name('drafts.confirm');
    });
});
