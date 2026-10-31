<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation\Support;

use App\Services\Conversation\ConversationManager;

trait UsesFakeProvider
{
    /** @param list<mixed> $script */
    protected function fakeProvider(array $script = [], bool $available = true): FakeConversationProvider
    {
        $provider = new FakeConversationProvider($script, $available);
        config(['conversation.provider' => 'fake', 'conversation.enabled' => true]);
        app(ConversationManager::class)->extend('fake', fn () => $provider);

        return $provider;
    }
}
