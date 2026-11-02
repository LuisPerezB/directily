<?php

declare(strict_types=1);

namespace App\Console\Commands\Radar;

use App\Models\Tenant;
use App\Services\Customer\CustomerRunService;
use App\Services\Radar\CommercialOpportunityService;
use App\Services\Radar\Exceptions\RadarException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Propone al Decision Engine las oportunidades del Revenue Radar de la predicción vigente de cada empresa (V2.5 · W5-A). Idempotente: con la misma predicción no
 * crea nada nuevo. Se omite la empresa cuyo plan no incluye Customer o que aún no tiene una predicción completada. Nunca envía mensajes ni escribe en el ERP.
 */
class MaterializeRadarOpportunitiesCommand extends Command
{
    protected $signature = 'predictily:radar-materialize
        {--tenant= : Id de una empresa (por defecto, todas las activas)}
        {--horizon=* : Solo estos horizontes (por defecto, todos los de la predicción que el plan permite)}
        {--dry-run : Solo cuenta lo que se propondría}';

    protected $description = 'Propone al Decision Engine las oportunidades del Revenue Radar de la predicción vigente (idempotente)';

    public function handle(CommercialOpportunityService $service, CustomerRunService $runs, TenantContext $context): int
    {
        $horizons = array_values(array_filter(array_map('intval', (array) $this->option('horizon')))) ?: null;
        $failed = false;
        Tenant::query()->where('status', 'active')->when($this->option('tenant'), fn ($q, $id) => $q->whereKey((int) $id))->orderBy('id')
            ->each(function (Tenant $tenant) use ($service, $runs, $context, $horizons, &$failed): bool {
                if ($runs->allowedHorizons($tenant) === []) {
                    $this->line("· {$tenant->name}: el plan no incluye comportamiento de compra");

                    return true;
                }
                try {
                    $context->runAs($tenant, function () use ($tenant, $service, $horizons, &$failed): void {
                        $run = $service->currentRun();
                        if ($run === null) {
                            $this->line("· {$tenant->name}: sin predicción de comportamiento completada");

                            return;
                        }
                        if ($this->option('dry-run')) {
                            $byKind = [];
                            foreach ($service->build($run, $horizons) as $candidate) {
                                $byKind[$candidate->kind] = ($byKind[$candidate->kind] ?? 0) + 1;
                            }
                            $this->info("✓ {$tenant->name}: se propondrían ".array_sum($byKind).' ('.json_encode($byKind).')');

                            return;
                        }
                        $result = $service->propose($run, $horizons);
                        $this->info("✓ {$tenant->name}: {$result->candidates} candidatas, {$result->created} nuevas, {$result->existing} ya existentes, {$result->failed} con error");
                        if ($result->failed > 0) {
                            $failed = true;   // el planificador y quien opera deben enterarse: código de salida distinto de cero
                        }
                        foreach ($result->errors as $error) {
                            $this->warn("  ! {$error}");
                        }
                    });
                } catch (RadarException $e) {
                    $this->error("✗ {$e->getMessage()}");
                    $failed = true;

                    return false;   // sin Decision Engine no tiene sentido seguir con las demás empresas
                } catch (Throwable $e) {
                    $this->warn("✗ {$tenant->name}: {$e->getMessage()}");
                    $failed = true;
                }

                return true;
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
