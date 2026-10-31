<?php

declare(strict_types=1);

namespace App\Services\Conversation;

use App\Services\Conversation\Contracts\ConversationProvider;
use App\Services\Conversation\Providers\NullProvider;
use App\Services\Conversation\Providers\OllamaCloudProvider;
use Closure;
use Illuminate\Container\Attributes\Singleton;

/**
 * Resuelve el proveedor conversacional según config/conversation.php. Nunca lanza por configuración ausente: devuelve un
 * NullProvider con el motivo (desactivado por configuración, sin clave, proveedor desconocido). Se pueden incorporar otros
 * proveedores con extend() sin modificar el orquestador (ADR-V2-12).
 */
#[Singleton]
final class ConversationManager
{
    /** @var array<string, Closure(): ConversationProvider> */
    private array $custom = [];

    /** @param Closure(): ConversationProvider $factory */
    public function extend(string $name, Closure $factory): void
    {
        $this->custom[$name] = $factory;
    }

    public function provider(): ConversationProvider
    {
        if (! (bool) config('conversation.enabled', true)) {
            return new NullProvider('disabled_by_config');
        }
        $name = strtolower(trim((string) config('conversation.provider', 'ollama_cloud')));
        if (isset($this->custom[$name])) {
            return ($this->custom[$name])();
        }

        return match ($name) {
            'ollama_cloud', 'ollama' => new OllamaCloudProvider((array) config('conversation.ollama', [])),
            'none', '' => new NullProvider('provider_disabled'),
            default => new NullProvider('unknown_provider'),
        };
    }

    public function isEnabled(): bool
    {
        return $this->provider()->isAvailable();
    }
}
