<?php

declare(strict_types=1);

namespace App\Console\Commands\Decision;

use App\Models\Tenant;
use App\Services\Decision\DecisionGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Genera las recomendaciones del Decision Engine de cada empresa activa (V2.5 · W5-B): contacto, recuperación, riesgo de cliente, reposición,
 * inventario-demanda y presupuesto, a partir de lo que ya está persistido (Customer, Demand/inventario y Supply). IDEMPOTENTE y por empresa: con los
 * mismos insumos no crea nada; con insumos nuevos crea la recomendación nueva y expira la anterior sin decidir. No entrena, no recalcula, no escribe
 * en el ERP ni envía mensajes. Una empresa con error no detiene a las demás.
 */
final class GenerateDecisionsCommand extends Command
{
    protected $signature = 'predictily:decisions-generate {--tenant= : Id de una sola empresa} {--dry-run : Solo cuenta lo que se generaría, sin escribir}';

    protected $description = 'Genera las recomendaciones del Decision Engine de las empresas activas a partir de las salidas ya persistidas (idempotente)';

    public function handle(DecisionGenerator $generator): int
    {
        $created = 0;
        $query = Tenant::query()->where('status', 'active')->orderBy('id');
        if ($this->option('tenant')) {
            $query->whereKey((int) $this->option('tenant'));
        }
        $query->each(function (Tenant $tenant) use ($generator, &$created): void {
            try {
                $report = $generator->generate($tenant, CarbonImmutable::now($tenant->timezone ?: 'America/Guayaquil'), (bool) $this->option('dry-run'));
            } catch (Throwable $e) {
                $this->warn("✗ {$tenant->name}: {$e->getMessage()}");

                return;
            }
            $new = array_sum($report['created']);
            $created += $new;
            $this->info(sprintf('%s %s: %d %s, %d ya existían%s', $report['dry_run'] ? '·' : '✓', $tenant->name, $new, $report['dry_run'] ? 'se generarían' : 'nuevas',
                array_sum($report['existing']), $report['invalid'] > 0 ? ", {$report['invalid']} inválidas" : ''));
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

        return self::SUCCESS;
    }
}
