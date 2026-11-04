<?php

declare(strict_types=1);

namespace Tests\Feature\Conversation\Gateway\Support;

use App\Services\Conversation\Channels\ChannelIdentity;
use App\Services\Conversation\Channels\ConfirmedMessage;
use App\Services\Conversation\Channels\Contracts\MessagingChannel;
use App\Services\Conversation\Channels\DeliveryReceipt;
use App\Services\Conversation\Channels\WebhookSignature;
use RuntimeException;

/** Canal simulado («mock», sin red) que cumple el contrato MessagingChannel y registra lo que se le entrega. NO es un proveedor: solo existe en las pruebas. */
final class FakeMessagingChannel implements MessagingChannel
{
    /** @var list<ConfirmedMessage> */
    public array $sent = [];

    public function __construct(private readonly string $channelName = 'whatsapp', public bool $failOnSend = false) {}

    public function name(): string
    {
        return $this->channelName;
    }

    public function verifyWebhook(string $rawBody, array $headers, array $query): bool
    {
        return WebhookSignature::verify('secreto-de-prueba', $rawBody, $headers['x-hub-signature-256'] ?? null);
    }

    public function resolveIdentity(string $senderReference): ?ChannelIdentity
    {
        return null;
    }

    public function send(ConfirmedMessage $message): DeliveryReceipt
    {
        if ($this->failOnSend) {
            throw new RuntimeException('El proveedor simulado rechazó el mensaje.');
        }
        $this->sent[] = $message;

        return new DeliveryReceipt('mock-'.count($this->sent));
    }
}
