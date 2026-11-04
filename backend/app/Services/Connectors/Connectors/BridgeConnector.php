<?php

declare(strict_types=1);

namespace App\Services\Connectors\Connectors;

use App\Models\BridgeDevice;
use App\Models\DataSource;
use App\Services\Bridge\BridgeEnrolmentService;
use App\Services\Connectors\ConnectorTestResult;

/**
 * Predictily Bridge (ADR-V25-10; W6-B): agente Python de SOLO LECTURA instalado en la red del cliente que empuja lotes comprimidos por HTTPS
 * saliente (sin puertos entrantes). Predictily no se conecta al ERP: no guarda sus credenciales ni su host. La fuente nace `inactive` hasta
 * que se registra su primer dispositivo con un código de un solo uso (BridgeEnrolmentService) y se reinactiva si se revocan todos.
 *
 * Estado honesto: PostgreSQL, SQLite y CSV/Excel probados con el agente local; MySQL y SQL Server implementados SIN servidor de prueba
 * (`implemented_untested`); el instalador de Windows y su firma de código están BLOQUEADOS (docs/integrations/BRIDGE_WINDOWS.md).
 */
final class BridgeConnector extends AbstractConnector
{
    public function key(): string
    {
        return BridgeEnrolmentService::CONNECTOR_KEY;
    }

    public function name(): string
    {
        return 'Predictily Bridge (agente de solo lectura en la red del cliente)';
    }

    public function description(): string
    {
        return 'Un agente instalado junto al ERP extrae con consultas SELECT autorizadas localmente, comprime los lotes y los sube con una URL prefirmada a Bronze; el pipeline de importación crea las ventas. Las credenciales del ERP no salen del equipo del cliente. Probado con PostgreSQL, SQLite y archivos locales; MySQL y SQL Server sin servidor de prueba; instalador de Windows bloqueado.';
    }

    public function status(): string
    {
        return 'requires_credentials';
    }

    public function verification(): string
    {
        return 'tested_with_test_database';
    }

    public function entityTypes(): array
    {
        return (array) config('bridge.entities', []);
    }

    public function credentialKeys(): array
    {
        return [];
    }

    public function test(DataSource $source): ConnectorTestResult
    {
        $base = ['verification' => $this->verification(), 'read_only' => true, 'transport' => 'https_outbound_push'];
        if (! $source->exists) {
            return new ConnectorTestResult(false, 'Aún no hay un agente Bridge registrado para esta fuente. Genere un código de registro e instale el agente.', $base + ['active_devices' => 0]);
        }
        $active = BridgeDevice::query()->where('data_source_id', $source->id)->whereNull('revoked_at')->get();
        if ($active->isEmpty()) {
            return new ConnectorTestResult(false, 'No hay dispositivos Bridge activos para esta fuente. Genere un código de registro e instale el agente.', $base + ['active_devices' => 0]);
        }
        $online = $active->filter(fn (BridgeDevice $d) => $d->status() === 'active')->count();

        return new ConnectorTestResult(true, $online > 0 ? 'Agente Bridge conectado.' : 'Hay dispositivos registrados pero ninguno ha enviado un latido reciente.', $base + ['active_devices' => $active->count(), 'online_devices' => $online]);
    }
}
