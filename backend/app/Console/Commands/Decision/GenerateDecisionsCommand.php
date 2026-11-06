<?php

declare(strict_types=1);

namespace App\Console\Commands\Decision;

use App\Jobs\Pipeline\RunDecisionsStage;
use App\Models\Tenant;
use App\Services\Decision\DecisionGenerator;
use App\Services\Pipeline\DecisionsStageRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Genera las recomendaciones del Decision Engine de cada empresa activa (V2.5 · W5-B): contacto, recuperación, riesgo de cliente, reposición,
 * inventario-demanda y presupuesto, a partir de lo que ya está persistido (Customer, Demand/inventario y Supply). IDEMPOTENTE y por empresa: con los
 * mismos insumos no crea nada; con insumos nuevos crea la recomendación nueva y expira la anterior sin decidir. No entrena, no recalcula, no escribe
 * en el ERP ni envía mensajes. Una empresa con error no detiene a las demás.
 *
 * ADR-V26-01 · F1 y F4: cada empresa pasa por DecisionsStageRunner, que deja constancia en `pipeline_stages`. Con `--queue` (como lo lanza el
 * planificador) no genera aquí: encola un trabajo por empresa, de modo que una empresa lenta no retrasa a las demás y la que falla se reintenta sola.
 * Sale con código distinto de cero si alguna empresa o fuente falló, para que la programación lo detecte.
 */
final class GenerateDecisionsCommand extends Command
{
    protected $signature = 'predictily:decisions-generate
        {--tenant= : Id de una sola empresa}
        {--dry-run : Solo cuenta lo que se generaría, sin escribir}
        {--queue : Encola un trabajo por empresa en vez de generar aquí}';

    protected $description = 'Genera las recomendaciones del Decision Engine de las empresas activas a partir de las salidas ya persistidas (idempotente)';

    public function handle(DecisionGenerator $generator, DecisionsStageRunner $runner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $query = Tenant::query()->where('status', 'active')->orderBy('id');
        if ($this->option('tenant')) {
            $query->whereKey((int) $this->option('tenant'));
        }

        if ($this->option('queue')) {
            if ($dryRun) {
                $this->error('--queue y --dry-run no se pueden combinar.');

                return self::INVALID;
            }
            $queued = 0;
            $query->each(function (Tenant $tenant) use (&$queued): void {
                RunDecisionsStage::dispatch((int) $tenant->getKey());
                $queued++;
            });
            $this->info("Empresas encoladas: {$queued}");

            return self::SUCCESS;
        }

        $created = 0;
        $failed = false;
        $query->each(function (Tenant $tenant) use ($generator, $runner, $dryRun, &$created, &$failed): void {
            $predictionNotReady = null;
            try {
                if ($dryRun) {
                    $report = $generator->generate($tenant, CarbonImmutable::now($tenant->timezone ?: 'America/Guayaquil'), true);
                } else {
                    $outcome = $runner->run($tenant);
                    $report = $outcome['report'];
                    $predictionNotReady = $outcome['prediction_not_ready'] ? $outcome['prediction'] : null;
                }
            } catch (Throwable $e) {
                $this->warn("✗ {$tenant->name}: {$e->getMessage()}");
                $failed = true;

                return;
            }
            if ($report['errors'] !== []) {
                $failed = true;
            }
            $new = array_sum($report['created']);
            $created += $new;
            $this->info(sprintf('%s %s: %d %s, %d ya existían%s', $report['dry_run'] ? '·' : '✓', $tenant->name, $new, $report['dry_run'] ? 'se generarían' : 'nuevas',
                array_sum($report['existing']), $report['invalid'] > 0 ? ", {$report['invalid']} inválidas" : ''));
            if ($predictionNotReady !== null) {
                $this->warn("    aviso: la predicción de clientes de hoy no está lista ({$predictionNotReady}); las recomendaciones de cliente usan la última disponible");
            }
            foreach ($report['created'] as $kind => $n) {
                $this->line("    {$kind}: {$n}");
            }
            foreach ($report['skipped'] as $what => $why) {
                $this->line("    omitido {$what}: {$why}");
            }
            foreach ($report['errors'] as $error) {
                $this->warn("    error: {$error}");
            }
        });
        $this->info("Recomendaciones nuevas: {$created}");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
