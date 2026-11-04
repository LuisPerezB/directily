<?php

declare(strict_types=1);

namespace App\Services\Conversation\Channels;

use Illuminate\Support\Facades\Cache;

/** DraftStore sobre el caché de Laravel; claves `tenant:{id}:gateway:draft:{uuid}` (prefijo de empresa, docs/07 §2 capa 7). */
final class CacheDraftStore implements DraftStore
{
    public function put(DraftMessage $draft): void
    {
        Cache::put($this->key($draft->tenantId, $draft->id), $draft->toStorage(), $draft->expiresAt);
    }

    public function find(int $tenantId, string $id): ?DraftMessage
    {
        $data = Cache::get($this->key($tenantId, $id));

        return is_array($data) && (int) ($data['tenant_id'] ?? 0) === $tenantId ? DraftMessage::fromStorage($data) : null;
    }

    public function claim(int $tenantId, string $id): bool
    {
        return Cache::add($this->key($tenantId, $id).':claimed', 1, now()->addDays(2));
    }

    public function wasClaimed(int $tenantId, string $id): bool
    {
        return Cache::has($this->key($tenantId, $id).':claimed');
    }

    public function discard(int $tenantId, string $id): void
    {
        Cache::forget($this->key($tenantId, $id));
    }

    private function key(int $tenantId, string $id): string
    {
        return "tenant:{$tenantId}:gateway:draft:{$id}";
    }
}
