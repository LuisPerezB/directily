<?php

declare(strict_types=1);

namespace App\Services\Conversation\Channels;

use App\Services\Conversation\Channels\Contracts\MessagingChannel;
use App\Services\Conversation\Channels\Exceptions\ChannelNotAvailableException;
use Closure;
use Illuminate\Container\Attributes\Singleton;

/**
 * Registro de canales de mensajería (V2.5 · W6-D). Un canal EXISTE solo si está habilitado por configuración (`conversation.channels.<nombre>.enabled`) Y hay un proveedor
 * registrado con extend(). Predictily no registra ninguno: por defecto todo canal responde 404 (ChannelNotAvailableException) y nada puede enviarse.
 */
#[Singleton]
final class ChannelRegistry
{
    /** @var array<string, Closure(): MessagingChannel> */
    private array $factories = [];

    /** @param Closure(): MessagingChannel $factory */
    public function extend(string $name, Closure $factory): void
    {
        $this->factories[$name] = $factory;
    }

    /** @throws ChannelNotAvailableException */
    public function channel(string $name): MessagingChannel
    {
        $enabled = preg_match('/^[a-z_]{1,20}$/', $name) === 1 && (bool) config("conversation.channels.{$name}.enabled", false);
        if (! $enabled || ! isset($this->factories[$name])) {
            throw new ChannelNotAvailableException;
        }
        $channel = ($this->factories[$name])();
        if ($channel->name() !== $name) {
            throw new ChannelNotAvailableException;   // un proveedor mal registrado no se usa
        }

        return $channel;
    }

    public function exists(string $name): bool
    {
        try {
            $this->channel($name);

            return true;
        } catch (ChannelNotAvailableException) {
            return false;
        }
    }
}
