<?php

declare(strict_types=1);

namespace App\Console\Commands\DataCore;

use App\Jobs\DataCore\SyncDataSource;
use App\Models\DataSource;
use App\Models\SourceMapping;
use App\Models\SourceSync;
use App\Models\Tenant;
use App\Services\DataCore\Sync\SourceSyncService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sincronización incremental programada de las fuentes SQL con `config.sync_schedule` = hourly | daily.
 * Una sincronización por fuente y entidad con mapeo aprobado; cada trabajo lleva la empresa explícita.
 */
final class SyncSourcesCommand extends Command
{
    protected $signature = 'predictily:sync-sources {--tenant= : uuid de una empresa} {--force : ignora el intervalo mínimo}';

    protected $description = 'Encola la sincronización incremental de las fuentes SQL programadas (hourly/daily).';

    public function handle(TenantContext $context, SourceSyncService $syncs): int
    {
        $queued = 0;
        $tenants = Tenant::query()->where('status', 'active')->when($this->option('tenant'), fn ($q, $uuid) => $q->where('uuid', $uuid))->get();
        foreach ($tenants as $tenant) {
            $queued += $context->runAs($tenant, function () use ($tenant, $syncs): int {
                $count = 0;
                foreach (DataSource::query()->where('type', 'connector')->where('status', '!=', 'inactive')->get() as $source) {
                    $schedule = (string) ($source->config['sync_schedule'] ?? 'manual');
                    if (! in_array($schedule, ['hourly', 'daily'], true)) {
                        continue;
                    }
                    $minHours = $schedule === 'hourly' ? 0.9 : 20;
                    foreach (SourceMapping::query()->where('data_source_id', $source->id)->where('is_active', true)->whereNotNull('approved_at')->get() as $mapping) {
                        $last = SourceSync::query()->where('data_source_id', $source->id)->where('entity', $mapping->entity)->latest('id')->first();
                        if (! $this->option('force') && $last?->created_at !== null && $last->created_at->diffInMinutes(now()) < $minHours * 60) {
                            continue;
                        }
                        try {
                            $sync = $syncs->request($source, $mapping->entity, 'incremental', null, 'schedule');
                            SyncDataSource::dispatch($tenant->id, $sync->id)->onQueue('imports');
                            $count++;
                        } catch (Throwable $e) {
                            $this->warn("Fuente {$source->id}/{$mapping->entity}: {$e->getMessage()}");
                        }
                    }
                }

                return $count;
            });
        }
        $this->info("Sincronizaciones encoladas: {$queued}");

        return self::SUCCESS;
    }
}
