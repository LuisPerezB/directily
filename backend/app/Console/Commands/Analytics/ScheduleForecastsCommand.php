<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use App\Models\ForecastRun;
use App\Models\Tenant;
use App\Services\Analytics\Support\SalesQuery;
use App\Services\Forecasting\ForecastRunService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Programa pronósticos según settings.forecast_schedule (daily | weekly | manual).
 * Evita entrenamientos innecesarios: no ejecuta si no hay ventas nuevas desde el último
 * corte ni si ya hay una ejecución activa.
 */
class ScheduleForecastsCommand extends Command
{
    protected $signature = 'predictily:schedule-forecasts {--dry-run : Solo muestra qué empresas se programarían}';

    protected $description = 'Solicita pronósticos programados para las empresas activas';

    public function handle(ForecastRunService $service, TenantContext $context): int
    {
        $scheduled = 0;
        Tenant::query()->where('status', 'active')->orderBy('id')->each(function (Tenant $tenant) use ($service, $context, &$scheduled) {
            $reason = $context->runAs($tenant, fn () => $this->skipReason($tenant));
            if ($reason !== null) {
                $this->line("· {$tenant->name}: {$reason}");

                return;
            }
            if ($this->option('dry-run')) {
                $this->info("✓ {$tenant->name}: se programaría");

                return;
            }
            try {
                // Horizonte máximo: una ejecución de 90 días sirve también a las vistas de 7 y 30.
                $run = $service->request($tenant, null, 90, null, 'schedule');
                $scheduled++;
                $this->info("✓ {$tenant->name}: ejecución v{$run->version} encolada");
            } catch (Throwable $e) {
                $this->warn("✗ {$tenant->name}: {$e->getMessage()}");
            }
        });
        $this->info("Ejecuciones programadas: {$scheduled}");

        return self::SUCCESS;
    }

    private function skipReason(Tenant $tenant): ?string
    {
        $schedule = (string) ($tenant->effectiveSettings()['forecast_schedule'] ?? 'weekly');
        if ($schedule === 'manual') {
            return 'programación manual';
        }
        $lastSale = SalesQuery::lastSaleDate((int) $tenant->id);
        if ($lastSale === null) {
            return 'sin ventas';
        }
        if (app(ForecastRunService::class)->activeRun() !== null) {
            return 'ya hay una ejecución activa';
        }
        $last = ForecastRun::query()->completed()->where('run_type', ForecastRun::TYPE_PRODUCTION)->latest('completed_at')->first();
        if ($last === null) {
            return null;
        }
        if ($last->cutoff_date !== null && $last->cutoff_date->toDateString() >= $lastSale) {
            return 'sin ventas nuevas desde el último pronóstico';
        }
        $minDays = $schedule === 'daily' ? 1 : 7;
        $age = CarbonImmutable::parse($last->completed_at ?? $last->created_at)->diffInDays(CarbonImmutable::now(), true);

        return $age < $minDays - 0.01 ? "último pronóstico hace menos de {$minDays} día(s)" : null;
    }
}
