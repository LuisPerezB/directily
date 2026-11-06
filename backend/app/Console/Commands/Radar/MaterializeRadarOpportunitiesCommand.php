<?php

declare(strict_types=1);

namespace App\Console\Commands\Radar;

use App\Jobs\Pipeline\RunRadarStage;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Services\Customer\CustomerRunService;
use App\Services\Pipeline\RadarStageRunner;
use App\Services\Radar\CommercialOpportunityService;
use App\Services\Radar\Exceptions\RadarException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Propone al Decision Engine las oportunidades del Revenue Radar de la predicción vigente de cada empresa (V2.5 · W5-A). Idempotente: con la misma predicción no
 * crea nada nuevo. Se omite la empresa cuyo plan no incluye Customer o que aún no tiene una predicción completada. Nunca envía mensajes ni escribe en el ERP.
 *
 * ADR-V26-01 · F1: cada empresa pasa por RadarStageRunner, que comprueba la predicción de clientes de HOY antes de proponer. Si sigue en curso, la empresa queda en
 * espera (el radar se lanza solo al terminar la predicción); si falló, queda bloqueada con el motivo. Nunca propone en silencio con la predicción de ayer. El resultado
 * de cada empresa queda en `pipeline_stages`. `--force` propone con la predicción vigente sin esa comprobación (uso manual de quien opera).
 */
class MaterializeRadarOpportunitiesCommand extends Command
{
    protected $signature = 'predictily:radar-materialize
        {--tenant= : Id de una empresa (por defecto, todas las activas)}
        {--horizon=* : Solo estos horizontes (por defecto, todos los de la predicción que el plan permite)}
        {--dry-run : Solo cuenta lo que se propondría}
        {--force : Propone con la predicción vigente aunque la de hoy no haya terminado}
        {--queue : Encola un trabajo por empresa en vez de proponer aquí}';

    protected $description = 'Propone al Decision Engine las oportunidades del Revenue Radar de la predicción vigente (idempotente)';

    public function handle(RadarStageRunner $runner, CommercialOpportunityService $service, CustomerRunService $runs, TenantContext $context): int
    {
        $horizons = array_values(array_filter(array_map('intval', (array) $this->option('horizon')))) ?: null;
        $tenants = Tenant::query()->where('status', 'active')->when($this->option('tenant'), fn ($q, $id) => $q->whereKey((int) $id))->orderBy('id');

        // ADR-V26-01 · F4: como lo lanza el planificador. No propone aquí: encola un trabajo por empresa (RunRadarStage), de modo que una
        // empresa lenta no retrasa a las demás y la que falla se reintenta sola. Es el barrido de las empresas cuyo radar no se lanzó ya
        // al terminar su predicción (el trabajo es único por empresa mientras está en cola e idempotente).
        if ($this->option('queue')) {
            if ($this->option('dry-run') || $this->option('force') || $horizons !== null) {
                $this->error('--queue no se puede combinar con --dry-run, --force ni --horizon.');

                return self::INVALID;
            }
            $queued = 0;
            $tenants->each(function (Tenant $tenant) use (&$queued): void {
                RunRadarStage::dispatch((int) $tenant->getKey());
                $queued++;
            });
            $this->info("Empresas encoladas: {$queued}");

            return self::SUCCESS;
        }

        $failed = false;
        $tenants->each(function (Tenant $tenant) use ($runner, $service, $runs, $context, $horizons, &$failed): bool {
                try {
                    if ($this->option('dry-run')) {
                        $this->dryRun($tenant, $service, $runs, $context, $horizons);

                        return true;
                    }
                    $outcome = $runner->run($tenant, $horizons, (bool) $this->option('force'));
                } catch (RadarException $e) {
                    $this->error("✗ {$e->getMessage()}");
                    $failed = true;

                    return false;   // sin Decision Engine no tiene sentido seguir con las demás empresas
                } catch (Throwable $e) {
                    $this->warn("✗ {$tenant->name}: {$e->getMessage()}");
                    $failed = true;

                    return true;
                }

                $result = $outcome['result'];
                match (true) {
                    $outcome['reason'] === 'plan_without_customer' => $this->line("· {$tenant->name}: el plan no incluye comportamiento de compra"),
                    $outcome['reason'] === 'no_prediction_run' => $this->line("· {$tenant->name}: sin predicción de comportamiento completada"),
                    $outcome['status'] === PipelineStage::WAITING => $this->line("… {$tenant->name}: la predicción de hoy sigue en curso; el radar se lanzará al terminar"),
                    $outcome['status'] === PipelineStage::BLOCKED => $this->warn("✗ {$tenant->name}: bloqueado, la predicción de hoy falló (no se propone con la de ayer)"),
                    $result !== null => $this->info("✓ {$tenant->name}: {$result->candidates} candidatas, {$result->created} nuevas, {$result->existing} ya existentes, {$result->failed} con error"),
                    default => $this->line("· {$tenant->name}: {$outcome['status']}"),
                };
                if ($outcome['status'] === PipelineStage::BLOCKED || ($result !== null && $result->failed > 0)) {
                    $failed = true;   // el planificador y quien opera deben enterarse: código de salida distinto de cero
                }
                foreach ($result?->errors ?? [] as $error) {
                    $this->warn("  ! {$error}");
                }

                return true;
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Solo lectura: cuenta lo que se propondría con la predicción vigente. No comprueba el ciclo diario ni deja constancia.
     *
     * @param  list<int>|null  $horizons
     */
    private function dryRun(Tenant $tenant, CommercialOpportunityService $service, CustomerRunService $runs, TenantContext $context, ?array $horizons): void
    {
        if ($runs->allowedHorizons($tenant) === []) {
            $this->line("· {$tenant->name}: el plan no incluye comportamiento de compra");

            return;
        }
        $context->runAs($tenant, function () use ($tenant, $service, $horizons): void {
            $run = $service->currentRun();
            if ($run === null) {
                $this->line("· {$tenant->name}: sin predicción de comportamiento completada");

                return;
            }
            $byKind = [];
            foreach ($service->build($run, $horizons) as $candidate) {
                $byKind[$candidate->kind] = ($byKind[$candidate->kind] ?? 0) + 1;
            }
            $this->info("✓ {$tenant->name}: se propondrían ".array_sum($byKind).' ('.json_encode($byKind).')');
        });
    }
}
