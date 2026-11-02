<?php

declare(strict_types=1);

namespace App\Console\Commands\Decision;

use App\Models\Tenant;
use App\Services\Decision\DecisionExpirer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Vence las recomendaciones del Decision Engine cuya vigencia terminó sin una acción en curso (V2.5 · W5-B), por empresa y con su evento de auditoría
 * del sistema. IDEMPOTENTE: lo ya vencido no se repite. Las vencidas se siguen midiendo en outcomes (se registran también las caducadas, no solo los éxitos).
 */
final class ExpireDecisionsCommand extends Command
{
    protected $signature = 'predictily:decisions-expire {--tenant= : Id de una sola empresa} {--dry-run : Solo cuenta lo que vencería}';

    protected $description = 'Marca como vencidas las recomendaciones del Decision Engine cuya vigencia terminó sin una acción en curso (idempotente)';

    public function handle(DecisionExpirer $expirer): int
    {
        $total = 0;
        $query = Tenant::query()->where('status', 'active')->orderBy('id');
        if ($this->option('tenant')) {
            $query->whereKey((int) $this->option('tenant'));
        }
        $query->each(function (Tenant $tenant) use ($expirer, &$total): void {
            try {
                $n = $expirer->expireDue($tenant, CarbonImmutable::now(), (bool) $this->option('dry-run'));
            } catch (Throwable $e) {
                $this->warn("✗ {$tenant->name}: {$e->getMessage()}");

                return;
            }
            $total += $n;
            if ($n > 0) {
                $this->info(sprintf('%s %s: %d %s', $this->option('dry-run') ? '·' : '✓', $tenant->name, $n, $this->option('dry-run') ? 'vencerían' : 'vencidas'));
            }
        });
        $this->info("Recomendaciones vencidas: {$total}");

        return self::SUCCESS;
    }
}
