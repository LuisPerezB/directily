<?php

declare(strict_types=1);

namespace App\Console\Commands\Customer;

use App\Models\CoreRun;
use App\Models\Tenant;
use App\Services\Customer\CustomerRunService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Predicción diaria del Customer Core. La recencia cambia cada día aunque no haya ventas nuevas, por eso NO se omite por falta
 * de ventas; se omite si la empresa no tiene modelos en producción, su plan no incluye horizontes, ya hay una corrida activa,
 * ya existe la predicción de hoy o la programación es manual (settings.customer_schedule = manual).
 * Solo predice (no entrena): usa los horizontes del plan ∩ {7, 30, 90}.
 */
class ScheduleCustomerPredictionsCommand extends Command
{
    protected $signature = 'predictily:customer-predict {--dry-run : Solo muestra qué empresas se programarían}';

    protected $description = 'Solicita la predicción diaria de comportamiento de compra para las empresas activas con modelo en producción';

    public function handle(CustomerRunService $service, TenantContext $context): int
    {
        $scheduled = 0;
        Tenant::query()->where('status', 'active')->orderBy('id')->each(function (Tenant $tenant) use ($service, $context, &$scheduled) {
            $reason = $context->runAs($tenant, fn () => $this->skipReason($tenant, $service));
            if ($reason !== null) {
                $this->line("· {$tenant->name}: {$reason}");

                return;
            }
            if ($this->option('dry-run')) {
                $this->info("✓ {$tenant->name}: se programaría");

                return;
            }
            try {
                $run = $service->request($tenant, null, 'predict', null, null, 'schedule');
                $scheduled++;
                $this->info("✓ {$tenant->name}: predicción v{$run->version} (".implode('/', (array) $run->horizons).' días) encolada');
            } catch (Throwable $e) {
                $this->warn("✗ {$tenant->name}: {$e->getMessage()}");
            }
        });
        $this->info("Predicciones programadas: {$scheduled}");

        return self::SUCCESS;
    }

    private function skipReason(Tenant $tenant, CustomerRunService $service): ?string
    {
        if (($tenant->effectiveSettings()['customer_schedule'] ?? 'auto') === 'manual') {
            return 'programación manual';
        }
        if ($service->allowedHorizons($tenant) === []) {
            return 'el plan no incluye horizontes de comportamiento de compra';
        }
        if (! $service->hasProductionModels()) {
            return 'sin modelo de comportamiento en producción (entrene primero)';
        }
        if ($service->activeRun('predict') !== null) {
            return 'ya hay una predicción activa';
        }
        $today = CarbonImmutable::now($tenant->timezone ?: 'America/Guayaquil')->startOfDay();
        $already = CoreRun::query()->where('core', 'customer')->where('operation', 'predict')->whereIn('status', ['queued', 'running', 'completed'])
            ->where('run_type', 'production')->where('created_at', '>=', $today->utc())->exists();

        return $already ? 'ya existe la predicción de hoy' : null;
    }
}
