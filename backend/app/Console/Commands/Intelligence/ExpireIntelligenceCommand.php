<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use App\Services\Intelligence\ExternalEventService;
use App\Services\Intelligence\SharedGrants\SharedForecastGrantService;
use Illuminate\Console\Command;

/**
 * Marca como EXPIRED los eventos externos cuya vigencia terminó y como `expired` las concesiones de pronóstico vencidas (ADR-V25-25).
 * Las API ya calculan el estado efectivo al leer, así que este proceso solo persiste el estado; es idempotente.
 */
final class ExpireIntelligenceCommand extends Command
{
    protected $signature = 'predictily:intelligence-expire';

    protected $description = 'Marca como vencidos los eventos externos y las concesiones de pronóstico compartido cuya vigencia terminó';

    public function handle(ExternalEventService $events, SharedForecastGrantService $grants): int
    {
        $expiredEvents = $events->expireDue();
        $expiredGrants = $grants->expireDue();
        $this->info("Eventos vencidos: {$expiredEvents}. Concesiones vencidas: {$expiredGrants}.");

        return self::SUCCESS;
    }
}
