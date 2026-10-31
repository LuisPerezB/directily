<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\Services\Conversation\Exceptions\AssistantRateLimitedException;
use App\Services\Conversation\Tools\ToolContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/** Tasa por usuario y empresa (por minuto) y tope diario por empresa. Claves con prefijo de empresa (docs/07 §2, capa 7). */
final class AssistantLimiter
{
    /** Cuenta un mensaje; lanza 429 si se excede la tasa o el tope diario. */
    public function hit(ToolContext $context): void
    {
        $tenantId = (int) $context->tenant->getKey();
        $perMinute = (int) config('conversation.limits.rate_per_minute', 10);
        if ($perMinute > 0) {
            $key = "tenant:{$tenantId}:assistant:user:".($context->user?->getKey() ?? 'anon');
            if (RateLimiter::tooManyAttempts($key, $perMinute)) {
                throw new AssistantRateLimitedException('minute', RateLimiter::availableIn($key));
            }
            RateLimiter::hit($key, 60);
        }

        $daily = (int) config('conversation.limits.daily_limit_per_tenant', 0);
        if ($daily > 0) {
            $key = $this->dailyKey($context);
            Cache::add($key, 0, now()->addDays(2));
            if ((int) Cache::get($key, 0) >= $daily) {
                throw new AssistantRateLimitedException('daily', max(1, (int) now($context->tenant->timezone ?: 'America/Guayaquil')->diffInSeconds(now($context->tenant->timezone ?: 'America/Guayaquil')->endOfDay(), true)));
            }
            Cache::increment($key);
        }
    }

    /** @return array{count: int, limit: int|null} */
    public function usageToday(ToolContext $context): array
    {
        $limit = (int) config('conversation.limits.daily_limit_per_tenant', 0);

        return ['count' => (int) Cache::get($this->dailyKey($context), 0), 'limit' => $limit > 0 ? $limit : null];
    }

    private function dailyKey(ToolContext $context): string
    {
        $tz = $context->tenant->timezone ?: 'America/Guayaquil';

        return 'tenant:'.$context->tenant->getKey().':assistant:day:'.now($tz)->toDateString();
    }
}
