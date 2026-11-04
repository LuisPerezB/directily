<?php

declare(strict_types=1);

namespace App\Services\Conversation\Channels;

use Illuminate\Container\Attributes\Bind;

/** Almacén temporal de borradores, aislado por empresa. La implementación por defecto usa el caché con claves prefijadas por empresa. */
#[Bind(CacheDraftStore::class)]
interface DraftStore
{
    public function put(DraftMessage $draft): void;

    /** El borrador de ESA empresa o null (un id de otra empresa no existe para esta). */
    public function find(int $tenantId, string $id): ?DraftMessage;

    /** Reclama el borrador para confirmarlo: true UNA sola vez (confirmación de un solo uso, idempotente frente a repeticiones y carreras). */
    public function claim(int $tenantId, string $id): bool;

    /** ¿Ya se reclamó? (para distinguir «confirmado» de «inexistente»). */
    public function wasClaimed(int $tenantId, string $id): bool;

    /** Descarta el texto del borrador (se confirmó o caducó). */
    public function discard(int $tenantId, string $id): void;
}
