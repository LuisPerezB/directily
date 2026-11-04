<?php

declare(strict_types=1);

namespace App\Jobs\Bridge;

use App\Jobs\Middleware\SetTenantContext;
use App\Services\Bridge\BridgeBatchProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Procesa un lote Bridge ya guardado en Bronze (cola «imports»). Transporta la empresa de forma EXPLÍCITA: el middleware
 * SetTenantContext la restablece. Idempotente: el procesador ignora un lote ya completado o en curso; un fallo queda registrado en el
 * propio objeto Bronze y el trabajo no reintenta por su cuenta (el agente reenvía el lote y el servidor lo vuelve a encolar).
 */
final class ProcessBridgeBatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    /** Un solo trabajo por lote y empresa en cola a la vez (B-8): reenvíos y declaraciones repetidas no apilan trabajos idénticos. */
    public int $uniqueFor = 3600;

    public function __construct(public int $tenantId, public int $bronzeObjectId) {}

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->bronzeObjectId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }

    public function handle(BridgeBatchProcessor $processor): void
    {
        $processor->process($this->bronzeObjectId);
    }
}
