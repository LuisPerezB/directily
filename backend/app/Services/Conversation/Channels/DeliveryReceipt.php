<?php

declare(strict_types=1);

namespace App\Services\Conversation\Channels;

/** Acuse de un canal al aceptar un mensaje confirmado (identificador del proveedor y estado inicial). */
final class DeliveryReceipt
{
    public function __construct(public readonly string $providerMessageId, public readonly string $status = 'accepted') {}

    /** @return array{provider_message_id: string, status: string} */
    public function toArray(): array
    {
        return ['provider_message_id' => $this->providerMessageId, 'status' => $this->status];
    }
}
