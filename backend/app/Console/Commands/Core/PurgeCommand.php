<?php

declare(strict_types=1);

namespace App\Console\Commands\Core;

use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Políticas de conservación y eliminación (LOPDP):
 * 1) Empresas con eliminación solicitada hace más de N días (gracia) → borrado definitivo
 *    (cascada en BD + archivos privados).
 * 2) Ventas más antiguas que tenants.data_retention_months → se eliminan.
 * 3) Bitácora de auditoría más antigua que la retención configurada → se elimina.
 * 4) Exportaciones vencidas → se elimina el ZIP.
 */
final class PurgeCommand extends Command
{
    protected $signature = 'predictily:purge {--dry-run : Solo informa lo que se eliminaría}';

    protected $description = 'Aplica retención de datos y elimina empresas cuyo período de gracia venció';

    public function handle(AuditLogger $audit): int
    {
        $dry = (bool) $this->option('dry-run');
        $disk = Storage::disk((string) config('predictily.imports.disk', 'local'));
        $grace = (int) config('predictily.privacy.deletion_grace_days', 30);

        $expired = Tenant::withTrashed()->where('status', 'pending_deletion')->where('deletion_requested_at', '<', now()->subDays($grace))->get();
        foreach ($expired as $tenant) {
            $this->line("Empresa a eliminar: #{$tenant->id}");
            if (! $dry) {
                $disk->deleteDirectory("tenants/{$tenant->id}");
                $audit->log('tenant.purged', null, ['tenant_id' => $tenant->id], null);
                DB::table('tenants')->where('id', $tenant->id)->delete();
            }
        }

        $salesDeleted = 0;
        foreach (Tenant::query()->where('status', 'active')->get(['id', 'data_retention_months', 'timezone']) as $tenant) {
            $limit = now($tenant->timezone)->subMonths((int) $tenant->data_retention_months)->toDateString();
            $query = DB::table('sales')->where('tenant_id', $tenant->id)->where('sale_date', '<', $limit);
            $salesDeleted += $dry ? $query->count() : $query->delete();
        }
        $this->line("Ventas fuera del período de retención: {$salesDeleted}");

        $auditQuery = DB::table('audit_logs')->where('created_at', '<', now()->subDays((int) config('predictily.privacy.audit_retention_days', 730)));
        $this->line('Registros de auditoría vencidos: '.($dry ? $auditQuery->count() : $auditQuery->delete()));

        $exports = DB::table('tenant_exports')->where('status', 'completed')->where('expires_at', '<', now())->get(['id', 'path']);
        foreach ($exports as $export) {
            if (! $dry) {
                if ($export->path) {
                    $disk->delete($export->path);
                }
                DB::table('tenant_exports')->where('id', $export->id)->update(['status' => 'expired', 'path' => null, 'updated_at' => now()]);
            }
        }
        $this->line('Exportaciones vencidas: '.$exports->count());

        return self::SUCCESS;
    }
}
