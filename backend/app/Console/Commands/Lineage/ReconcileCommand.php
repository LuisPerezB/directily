<?php

declare(strict_types=1);

namespace App\Console\Commands\Lineage;

use App\Models\Tenant;
use App\Services\DataCore\Reconciliation\WindowReconciler;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Reconciliación por ventana fuente → Bronze → Silver → Gold (ADR-V25-24):
 *   php artisan predictily:reconcile {tenant_uuid} --entity=sales --from=AAAA-MM-DD --to=AAAA-MM-DD
 * Escribe un `reconciliation_reports` con scope `window`. Códigos de salida: 0 = balanceado o explicado; 1 = argumentos
 * inválidos; 2 = diferencias SIN explicar (útil para programación y alertas).
 */
final class ReconcileCommand extends Command
{
    private const FIGURES = ['documents', 'lines', 'units', 'net_amount', 'rows', 'objects', 'loads', 'voided', 'credit_notes'];

    protected $signature = 'predictily:reconcile {tenant_uuid : UUID de la empresa} {--entity=sales : Entidad (solo sales está implementada)} {--from= : Primer día de la ventana (AAAA-MM-DD)} {--to= : Último día de la ventana (AAAA-MM-DD)}';

    protected $description = 'Reconcilia una ventana de fechas entre las capas fuente, Bronze, Silver y Gold y guarda el informe';

    public function handle(WindowReconciler $reconciler): int
    {
        $tenant = Tenant::query()->where('uuid', (string) $this->argument('tenant_uuid'))->first();
        if ($tenant === null) {
            $this->error('No existe una empresa con ese UUID.');

            return self::FAILURE;
        }
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        if ($from === '' || $to === '') {
            $this->error('Indique --from y --to (AAAA-MM-DD).');

            return self::FAILURE;
        }
        try {
            $report = $reconciler->reconcile($tenant, (string) $this->option('entity'), $from, $to);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Informe #{$report->id} · {$report->entity} {$report->window_from->toDateString()} → {$report->window_to->toDateString()} · estado {$report->status}");
        $rows = [];
        foreach ((array) $report->layers as $name => $layer) {
            $available = ($layer['available'] ?? true) !== false;
            $compared = ($layer['compared'] ?? true) !== false && ($layer['comparable'] ?? true) !== false;
            $figures = array_filter((array) $layer, fn ($v, $k) => is_scalar($v) && in_array($k, self::FIGURES, true), ARRAY_FILTER_USE_BOTH);
            $state = ! $available ? 'NO DISPONIBLE' : (! $compared ? 'NO COMPARADA' : 'disponible');
            $rows[] = [$name, $state, ($available ? implode(' · ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($figures), $figures)) : '')
                .(! $available || ! $compared ? ' '.(string) ($layer['reason'] ?? '') : '')];
        }
        $this->table(['Capa', 'Estado', 'Detalle'], $rows);
        foreach ((array) $report->discrepancies as $d) {
            $this->line(sprintf('  [%s] %s → %s · %s: esperado %s, observado %s · %s', $d['explained'] ? 'explicada' : 'SIN EXPLICAR', $d['from'], $d['to'], $d['metric'], $d['expected'], $d['observed'], $d['explanation']));
        }

        return $report->status === 'unexplained' ? 2 : self::SUCCESS;
    }
}
