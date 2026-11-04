<?php

declare(strict_types=1);

namespace App\Services\Conversation\Channels\Contracts;

use App\Services\Conversation\Channels\ChannelIdentity;
use App\Services\Conversation\Channels\ConfirmedMessage;
use App\Services\Conversation\Channels\DeliveryReceipt;

/**
 * Contrato de un canal de mensajería (WhatsApp Business Platform) — V2.5 · W6-D; ADR-V25-17; V25-GWY-019..024. SOLO CONTRATO: Predictily no incluye ningún proveedor ni
 * envía nada. Quien implemente esta interfaz (el propietario, con su cuenta y plantillas aprobadas) la registra en el ChannelRegistry; sin eso el canal no existe (404).
 *
 * Garantías que el contrato impone por tipos y por pruebas:
 *  - `send()` SOLO acepta un ConfirmedMessage, que únicamente HumanConfirmation emite tras la confirmación explícita de una persona de la empresa sobre el texto exacto;
 *  - la identidad del remitente se resuelve por una vinculación previa (usuario ↔ canal, por empresa), nunca por lo que diga el mensaje;
 *  - el webhook entrante se verifica (firma HMAC-SHA256 con un secreto por entorno) antes de tratar su contenido.
 */
interface MessagingChannel
{
    /** Nombre estable del canal (p. ej. «whatsapp»). */
    public function name(): string;

    /**
     * Verifica un webhook entrante: firma HMAC-SHA256 del cuerpo crudo (véase WebhookSignature) y desafío de suscripción. Un webhook que no se verifica no se procesa.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $query
     */
    public function verifyWebhook(string $rawBody, array $headers, array $query): bool;

    /** Usuario de UNA empresa vinculado a esa referencia del remitente (teléfono con hash, etc.), o null si no está vinculado. */
    public function resolveIdentity(string $senderReference): ?ChannelIdentity;

    /** Envía un mensaje ya confirmado por una persona. */
    public function send(ConfirmedMessage $message): DeliveryReceipt;
}
