<?php

declare(strict_types=1);

namespace App\Services\Conversation\Providers;

use App\Services\Conversation\Contracts\ConversationProvider;
use App\Services\Conversation\Exceptions\AssistantDisabledException;
use App\Services\Conversation\ProviderResponse;

/** Proveedor nulo: la capa conversacional está desactivada. Nunca sale a la red. */
final class NullProvider implements ConversationProvider
{
    public function __construct(private readonly string $reason = 'provider_disabled') {}

    public function name(): string
    {
        return 'none';
    }

    public function model(): ?string
    {
        return null;
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function unavailableReason(): ?string
    {
        return $this->reason;
    }

    public function chat(array $messages, array $tools): ProviderResponse
    {
        throw new AssistantDisabledException($this->reason);
    }
}
