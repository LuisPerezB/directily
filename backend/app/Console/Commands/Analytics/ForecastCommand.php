<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use App\Jobs\Forecasting\RunForecastPipeline;
use App\Models\ForecastRun;
use App\Models\Tenant;
use App\Services\Forecasting\ForecastRunService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/** Solicita (o ejecuta en primer plano con --sync) un pronóstico para una empresa. */
class ForecastCommand extends Command
{
    protected $signature = 'predictily:forecast {tenant : UUID de la empresa}
        {--horizon=90 : Horizonte en días (7, 30 o 90)}
        {--cutoff= : Fecha de corte AAAA-MM-DD (crea una ejecución histórica)}
        {--sync : Ejecuta el pipeline en este proceso en lugar de encolarlo}';

    protected $description = 'Genera un pronóstico para una empresa invocando al motor predictivo';

    public function handle(ForecastRunService $service, TenantContext $context): int
    {
        $tenant = Tenant::query()->where('uuid', (string) $this->argument('tenant'))->first();
        if ($tenant === null) {
            $this->error('Empresa no encontrada.');

            return self::FAILURE;
        }

        $sync = (bool) $this->option('sync');
        try {
            $run = $service->request($tenant, null, (int) $this->option('horizon'), $this->option('cutoff') ?: null, 'system', dispatch: ! $sync);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Ejecución v{$run->version} (id {$run->id}) creada para {$tenant->name}.");

        if (! $sync) {
            $this->line('Encolada en "'.config('engine.queue').'". Siga el progreso en GET /api/v1/jobs/'.$run->trainingJob?->id);

            return self::SUCCESS;
        }

        try {
            RunForecastPipeline::dispatchSync($tenant->id, $run->id, (int) $run->trainingJob?->id);
        } catch (Throwable $e) {
            $this->error('El pipeline falló: '.$e->getMessage());
        }

        $run = $context->runAs($tenant, fn () => ForecastRun::query()->find($run->id));
        $this->table(['id', 'versión', 'estado', 'corte', 'series', 'error'], [[
            $run->id, $run->version, $run->status, $run->cutoff_date?->toDateString(),
            $run->summary['series_count'] ?? '-', $run->error_message ?? '',
        ]]);

        return $run->status === ForecastRun::STATUS_COMPLETED ? self::SUCCESS : self::FAILURE;
    }
}
