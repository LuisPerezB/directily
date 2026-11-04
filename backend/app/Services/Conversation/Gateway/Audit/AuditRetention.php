<?php

declare(strict_types=1);

namespace App\Services\Conversation\Gateway\Audit;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Única ruta de BORRADO de `gateway_tool_calls` (retención o supresión), explícita y acotada: dentro de una transacción fija la variable local `predictily.audit_purge = on`
 * que el disparador de la base de datos (migración 2026_03_14_000500) exige para permitir el DELETE, borra solo lo indicado y la vuelve a apagar.
 *
 * LÍMITE: la variable la puede fijar cualquier código con acceso a la base de datos; protege contra accidentes y rutas de código descuidadas, no contra SQL arbitrario.
 * La política de retención (plazo) es una decisión del piloto: DISEÑADO; este servicio es el mecanismo, no programa nada.
 */
final class AuditRetention
{
    /** Borra las llamadas anteriores a `$before`, de una empresa o de todas si `$tenantId` es null. @return int filas borradas */
    public function purge(CarbonInterface $before, ?int $tenantId = null): int
    {
        return DB::transaction(function () use ($before, $tenantId): int {
            DB::statement("SELECT set_config('predictily.audit_purge', 'on', true)");
            try {
                return DB::table('gateway_tool_calls')
                    ->where('created_at', '<', $before)
                    ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                    ->delete();
            } finally {
                DB::statement("SELECT set_config('predictily.audit_purge', 'off', true)");   // también si la transacción es anidada (savepoint)
            }
        });
    }
}
