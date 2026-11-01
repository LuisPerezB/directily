<?php

declare(strict_types=1);

namespace App\Console\Commands\Customer;

use App\Models\CoreRun;
use App\Models\Tenant;
use App\Services\Analytics\Support\SalesQuery;
use App\Services\Customer\CustomerRunService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reentrenamiento semanal del Customer Core. Se omite si no hay ventas, el plan no incluye horizontes, hay una corrida activa,
 * el último entrenamiento tiene menos de 7 días, no hay ventas nuevas desde él o la programación es manual. Un candidato solo se
 * promueve si supera el protocolo y la regla versionada (el motor decide; aquí solo se solicita).
 */
class ScheduleCustomerTrainingCommand extends Command
{
    protected $signature = 'predictily:customer-train {--dry-run : Solo muestra qué empresas se programarían}';

    protected $description = 'Solicita el entrenamiento semanal de comportamiento de compra para las empresas activas';

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
                $run = $service->request($tenant, null, 'train', null, null, 'schedule');
                $scheduled++;
                $this->info("✓ {$tenant->name}: entrenamiento v{$run->version} (".implode('/', (array) $run->horizons).' días) encolado');
            } catch (Throwable $e) {
                $this->warn("✗ {$tenant->name}: {$e->getMessage()}");
            }
        });
        $this->info("Entrenamientos programados: {$scheduled}");

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
        $lastSale = SalesQuery::lastSaleDate((int) $tenant->id);
        if ($lastSale === null) {
            return 'sin ventas';
        }
        if ($service->activeRun('train') !== null) {
            return 'ya hay un entrenamiento activo';
        }
        $last = CoreRun::query()->where('core', 'customer')->where('operation', 'train')->where('status', 'completed')
            ->where('run_type', 'production')->latest('completed_at')->first();
        if ($last === null) {
            return null;
        }
        if ($last->as_of !== null && $last->as_of->toDateString() >= $lastSale) {
            return 'sin ventas nuevas desde el último entrenamiento';
        }
        $age = CarbonImmutable::parse($last->completed_at ?? $last->created_at)->diffInDays(CarbonImmutable::now(), true);

        return $age < 6.99 ? 'último entrenamiento hace menos de 7 días' : null;
    }
}
