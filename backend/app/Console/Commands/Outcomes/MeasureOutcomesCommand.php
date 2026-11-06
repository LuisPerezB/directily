<?php

declare(strict_types=1);

namespace App\Console\Commands\Outcomes;

use App\Jobs\Pipeline\RunOutcomesStage;
use App\Models\Tenant;
use App\Services\Outcomes\OutcomeMeasurementService;
use App\Services\Pipeline\OutcomesStageRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mide los outcomes de las recomendaciones del Decision Engine de cada empresa activa (V2.5 · W5-C; ADR-V25-14). IDEMPOTENTE y por empresa: una recomendación con
 * resultado no se vuelve a medir (el resultado es inmutable y su huella de ventas queda en la evidencia); solo se miden las que ya cerraron su ventana MÁS el margen
 * de espera (`settling_lag_days`), leyendo las ventas tal como se conocían a ese corte (as-of). Se programa DESPUÉS de `predictily:decisions-generate`. No escribe
 * en el ERP ni envía mensajes. Una empresa o un grupo con error no detiene a las demás, pero el comando termina con código distinto de cero (1; 2 si --tenant no es un
 * entero positivo) para que la programación lo detecte; la salida lleva un código estable y nunca el texto de la excepción.
 */
final class MeasureOutcomesCommand extends Command
{
    /** Código estable del error de una empresa (la causa queda en el registro de la aplicación). */
    public const ERROR_TENANT_FAILED = 'outcomes_tenant_failed';

    protected $signature = 'predictily:outcomes-measure {--tenant= : Id de una sola empresa} {--dry-run : Solo cuenta lo que mediría, sin escribir} {--lag= : Margen de espera en días (por omisión, el de la empresa)} {--queue : Encola un trabajo por empresa en vez de medir aquí}';

    protected $description = 'Mide los outcomes (acción → evento observable posterior) de las recomendaciones cuya ventana ya cerró, con clasificación honesta (idempotente)';

    public function handle(OutcomeMeasurementService $service, OutcomesStageRunner $runner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $lag = $this->option('lag');
        if ($lag !== null && (! ctype_digit((string) $lag) || (int) $lag > 90)) {
            $this->error('--lag debe ser un entero entre 0 y 90.');

            return self::FAILURE;
        }
        $only = $this->option('tenant');
        if ($only !== null && (! ctype_digit((string) $only) || (int) $only < 1)) {
            $this->error('--tenant debe ser el id numérico de una empresa (entero positivo).');

            return self::INVALID;
        }
        $total = 0;
        $failed = 0;
        $query = Tenant::query()->where('status', 'active')->orderBy('id');
        if ($only !== null) {
            $query->whereKey((int) $only);
        }
        // ADR-V26-01 · F4: como lo lanza el planificador. No mide aquí: encola un trabajo por empresa (RunOutcomesStage), de modo que una
        // empresa lenta no retrasa a las demás y la que falla se reintenta sola. El margen (--lag) es el de cada empresa.
        if ($this->option('queue')) {
            if ($dryRun || $lag !== null) {
                $this->error('--queue no se puede combinar con --dry-run ni con --lag.');

                return self::INVALID;
            }
            $queued = 0;
            $query->each(function (Tenant $tenant) use (&$queued): void {
                RunOutcomesStage::dispatch((int) $tenant->getKey());
                $queued++;
            });
            $this->info("Empresas encoladas: {$queued}");

            return self::SUCCESS;
        }
        $query->each(function (Tenant $tenant) use ($service, $runner, $dryRun, $lag, &$total, &$failed): void {
            try {
                // Sin --dry-run pasa por OutcomesStageRunner, que además deja constancia de la etapa en `pipeline_stages`.
                $report = $dryRun
                    ? $service->measure($tenant, CarbonImmutable::now(), true, $lag === null ? null : (int) $lag)
                    : $runner->run($tenant, $lag === null ? null : (int) $lag);
            } catch (Throwable $e) {
                // El detalle (puede traer SQL o datos) queda en el registro de la aplicación; la salida lleva solo un código estable.
                Log::error('Outcomes: empresa con error', ['tenant_id' => $tenant->getKey(), 'exception' => $e::class, 'error' => $e->getMessage()]);
                $this->warn("✗ {$tenant->name}: error=".self::ERROR_TENANT_FAILED);
                $failed++;

                return;
            }
            $total += $report['measured'];
            $this->info(sprintf(
                '%s %s: %d %s (margen %d d), %d con ventana abierta, %d en espera de documentos tardíos%s',
                $report['dry_run'] ? '·' : '✓', $tenant->name, $report['measured'], $report['dry_run'] ? 'se medirían' : 'medidas', $report['settling_lag_days'],
                $report['pending_window_open'], $report['pending_settling'],
                $report['transitioned_to_measured'] > 0 ? ", {$report['transitioned_to_measured']} pasaron a «measured»" : '',
            ));
            foreach (['by_classification' => 'clasificación', 'by_action_kind' => 'acción'] as $bucket => $label) {
                foreach ($report[$bucket] as $name => $n) {
                    $this->line("    {$label} {$name}: {$n}");
                }
            }
            foreach ($report['experiments'] as $cohort => $info) {
                $this->line("    experimento {$cohort}: {$info['status']}");
            }
            if ($report['finalized_late_execution'] > 0) {
                $this->line('    ejecutadas después de medir, '.($report['dry_run'] ? 'se cerrarían' : 'cerradas').' como medidas: '.$report['finalized_late_execution']);
            }
            if ($report['already_measured_concurrently'] > 0) {
                $this->line("    ya medidas por otro proceso: {$report['already_measured_concurrently']}");
            }
            foreach ($report['errors'] as $error) {
                $this->warn("    error={$error['code']} as_of={$error['as_of']} horizonte={$error['horizon_days']} (detalle en el registro de la aplicación)");
                $failed++;
            }
        });
        $this->info("Recomendaciones medidas: {$total}");
        if ($failed > 0) {
            $this->error("Hubo {$failed} error(es): las recomendaciones de los grupos fallidos NO se midieron y se reintentan en la siguiente ejecución.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
