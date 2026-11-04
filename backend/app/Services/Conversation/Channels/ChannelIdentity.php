<?php

declare(strict_types=1);

namespace App\Services\Conversation\Channels;

/** Identidad vinculada de un remitente: la empresa y el usuario a los que se ligó el canal (por verificación previa desde la sesión web). */
final class ChannelIdentity
{
    public function __construct(public readonly int $tenantId, public readonly int $userId, public readonly string $channel) {}
}
