<?php

declare(strict_types=1);

namespace App\Models\Builders;

use App\Support\Tenancy\TenantContext;
use LogicException;

/**
 * Bitácora de solo inserción (`shared_forecast_grant_accesses`): por el modelo no se actualiza ni se borra NADA, ni fila a fila ni en masa
 * (update, delete, forceDelete, increment, decrement, touch, upsert, truncate). Solo se inserta, y con empresa en contexto cada fila debe registrar
 * como autora a esa misma empresa (nadie falsifica el acceso de otra).
 */
final class AppendOnlyBuilder extends GuardedWritesBuilder
{
    protected function restrictWrites(): void
    {
        throw new LogicException('La bitácora de accesos es de solo inserción.');
    }

    protected function assertInsertable(array $rows): void
    {
        $context = app(TenantContext::class);
        foreach ($rows as $row) {
            if ($context->has() && (int) ($row['accessed_by_tenant_id'] ?? 0) !== $context->id()) {
                throw new LogicException('Un acceso solo se registra a nombre de la empresa que accede.');
            }
        }
    }

    protected function assertUpsertSafe(array $rows, array $uniqueBy, ?array $update): void
    {
        throw new LogicException('La bitácora de accesos es de solo inserción: no admite upsert.');
    }
}
