<?php

declare(strict_types=1);

namespace App\Console\Commands\Models;

use App\Models\Tenant;
use App\Services\Models\Usage\StorageUsageSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Foto diaria del almacenamiento por empresa (V2.5 · W4; ADR-V25-09, §14): bytes de Bronze y de artefactos de modelos en
 * `tenant_usage_records` (`storage_bytes`, `storage_objects`). Solo CANTIDADES, sin precios. Idempotente por día: repetirlo reemplaza la foto.
 */
final class UsageSnapshotCommand extends Command
{
    protected $signature = 'predictily:usage-snapshot {--tenant= : UUID de una sola empresa (por defecto, todas las activas)} {--date= : Día de consumo AAAA-MM-DD (por defecto, hoy en la zona de cada empresa)} {--dry-run : Solo informa lo medido}';

    protected $description = 'Registra el almacenamiento diario (Bronze y artefactos) de cada empresa para el modelo de costos';

    public function handle(StorageUsageSnapshot $snapshot): int
    {
        $date = $this->option('date');
        if (is_string($date) && $date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $this->error('La fecha debe tener el formato AAAA-MM-DD.');

            return self::FAILURE;
        }
        $uuid = $this->option('tenant');
        $tenants = Tenant::query()->where('status', 'active')
            ->when(is_string($uuid) && $uuid !== '', fn ($q) => $q->where('uuid', $uuid))->orderBy('id')->get();
        if (is_string($uuid) && $uuid !== '' && $tenants->isEmpty()) {
            $this->error('No hay una empresa activa con ese UUID.');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');

        // Una empresa con un fallo (disco ilegible, dato corrupto) no impide medir a las demás: se informa, se registra en el log y el comando
        // termina con código de error al final.
        $failed = 0;
        foreach ($tenants as $tenant) {
            try {
                $day = is_string($date) && $date !== '' ? $date : CarbonImmutable::now((string) ($tenant->timezone ?: config('app.timezone')))->toDateString();
                $m = $dry ? $snapshot->measure($tenant) : $snapshot->record($tenant, $day);
                $this->line(sprintf(
                    'Empresa #%d · %s · Bronze %d bytes (%d objetos) · artefactos %d bytes (%d objetos%s)%s',
                    $tenant->id, $day, $m['bronze_bytes'], $m['bronze_objects'], $m['artifact_bytes'], $m['artifact_objects'],
                    $m['artifacts_visible'] ? '' : '; el volumen de artefactos no es visible desde el backend', $dry ? ' [simulación]' : '',
                ));
            } catch (Throwable $e) {
                $failed++;
                Log::warning('predictily:usage-snapshot: no se pudo medir una empresa', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
                $this->error(sprintf('Empresa #%d · FALLÓ la medición (%s); se continúa con las demás.', $tenant->id, $e::class));
            }
        }
        if ($failed > 0) {
            $this->error("Empresas con error: {$failed} de {$tenants->count()}.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
