<?php

/*
| Rutas del Predictily Bridge (V2.5 · W6-B; ADR-V25-10). Prefijo /api/v1.
|
| - Administración (usuario con empresa activa, habilidad datasources.manage): código de registro de un solo uso por fuente, lista y detalle
|   de dispositivos, revocación (inmediata) y reinicio de checkpoints.
| - Agente: `POST bridge/enrol` es público (acotado por IP; el código de un solo uso lo autoriza). El resto exige token de dispositivo + firma
|   Ed25519 de la petición (AuthenticateBridgeDevice): latido, checkpoints, declaración de subida y confirmación de checkpoint.
| - `PUT bridge/uploads/{device}/{sha256}` es la URL PREFIRMADA de UN lote (firma relativa del servidor: empresa, dispositivo, entidad, SHA-256,
|   tamaño, tipo y vencimiento); no usa token, pero relee el estado del dispositivo (un dispositivo revocado no sube nada).
*/

use App\Http\Controllers\Api\Bridge\BridgeAgentController;
use App\Http\Controllers\Api\Bridge\BridgeDeviceController;
use App\Http\Middleware\AuthenticateBridgeDevice;
use Illuminate\Support\Facades\Route;

// Límites de tasa (bridge-enrol, bridge-agent, bridge-put): App\Providers\BridgeServiceProvider (siguen definidos con la caché de rutas).

Route::middleware(['auth:sanctum', 'throttle:api', 'tenant', 'tenant.ability:datasources.manage'])->group(function (): void {
    Route::post('data-sources/{dataSource}/bridge/enrolments', [BridgeDeviceController::class, 'issueEnrolment'])->middleware('throttle:ingest');
    Route::get('bridge/devices', [BridgeDeviceController::class, 'index']);
    Route::get('bridge/devices/{device}', [BridgeDeviceController::class, 'show'])->whereNumber('device');
    Route::post('bridge/devices/{device}/revoke', [BridgeDeviceController::class, 'revoke'])->whereNumber('device');
    Route::delete('bridge/devices/{device}/checkpoints/{entity}', [BridgeDeviceController::class, 'resetCheckpoint'])->whereNumber('device');
});

Route::post('bridge/enrol', [BridgeAgentController::class, 'enrol'])->middleware('throttle:bridge-enrol');

Route::middleware(['throttle:bridge-agent-ip', 'throttle:bridge-agent', AuthenticateBridgeDevice::class])->group(function (): void {
    Route::post('bridge/heartbeat', [BridgeAgentController::class, 'heartbeat']);
    Route::get('bridge/checkpoints', [BridgeAgentController::class, 'checkpoints']);
    Route::post('bridge/checkpoints', [BridgeAgentController::class, 'commit']);
    Route::post('bridge/uploads', [BridgeAgentController::class, 'declareUpload']);
});

Route::put('bridge/uploads/{device}/{sha256}', [BridgeAgentController::class, 'putUpload'])
    ->middleware(['throttle:bridge-put', 'signed:relative'])->whereNumber('device')->name('bridge.uploads.put');
