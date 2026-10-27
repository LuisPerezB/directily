<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use App\Jobs\Forecasting\EvaluateActuals;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Encola la evaluación de pronósticos pasados contra ventas reales (detección de drift). */
class EvaluateActualsCommand extends Command
{
    protected $signature = 'predictily:evaluate-actuals {tenant? : UUID de una empresa concreta} {--lookback=10 : Ejecuciones a evaluar}';

    protected $description = 'Compara pronósticos pasados con ventas reales y detecta deterioro';

    public function handle(): int
    {
        $query = Tenant::query()->where('status', 'active')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('forecast_runs')
                ->whereColumn('forecast_runs.tenant_id', 'tenants.id')
                ->where('forecast_runs.status', 'completed'));
        if ($uuid = $this->argument('tenant')) {
            if (! Str::isUuid((string) $uuid)) {
                $this->error('UUID de empresa inválido.');

                return self::FAILURE;
            }
            $query->where('uuid', (string) $uuid);
        }

        $count = 0;
        $query->orderBy('id')->each(function (Tenant $tenant) use (&$count) {
            EvaluateActuals::dispatch((int) $tenant->id, (int) $this->option('lookback'), 'schedule');
            $count++;
        });
        $this->info("Evaluaciones encoladas: {$count}");

        return self::SUCCESS;
    }
}
