<?php

declare(strict_types=1);

namespace App\Console\Commands\Lineage;

use App\Models\Tenant;
use App\Services\DataCore\Bronze\BronzePurger;
use Illuminate\Console\Command;

/**
 * Retención de Bronze (ADR-V25-24): purga los objetos vencidos según la política de cada empresa
 * (`tenants.settings` → `data.bronze_retention_days`, con precedencia sobre `bronze.retention_days`). El contenido se borra del
 * almacén, la fila queda en estado `purged` y cada purga se audita. Sin política (0 días) nada vence.
 */
final class BronzePurgeCommand extends Command
{
    protected $signature = 'predictily:bronze-purge {--tenant= : UUID de una sola empresa (por defecto, todas las activas)} {--dry-run : Solo informa lo que se purgaría}';

    protected $description = 'Purga los objetos Bronze vencidos según la política de retención de cada empresa';

    public function handle(BronzePurger $purger): int
    {
        $dry = (bool) $this->option('dry-run');
        $uuid = $this->option('tenant');
        $tenants = Tenant::query()->where('status', 'active')
            ->when(is_string($uuid) && $uuid !== '', fn ($q) => $q->where('uuid', $uuid))->orderBy('id')->get();
        if (is_string($uuid) && $uuid !== '' && $tenants->isEmpty()) {
            $this->error('No hay una empresa activa con ese UUID.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($tenants as $tenant) {
            $r = $purger->run($tenant, $dry);
            $failed += $r['failed'];
            $this->line(sprintf(
                'Empresa #%d · retención %s (%s) · vencidos %d · %s %d · retenidos por cuarentena pendiente %d · fallidos %d',
                $tenant->id, $r['retention_days'] > 0 ? $r['retention_days'].' días' : 'sin vencimiento', $r['policy_source'],
                $r['due'], $dry ? 'se purgarían' : 'purgados', $dry ? $r['due'] : $r['purged'], $r['held_pending_quarantine'], $r['failed'],
            ));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
