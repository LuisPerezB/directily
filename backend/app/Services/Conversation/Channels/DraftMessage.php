<?php

declare(strict_types=1);

namespace App\Services\Conversation\Channels;

use Carbon\CarbonImmutable;

/**
 * Borrador de un mensaje saliente: texto ya renderizado desde una plantilla, para UN cliente de la empresa, pendiente de la confirmación explícita de una persona. Un borrador
 * NO se envía nunca por sí mismo; el texto exacto que se confirma se identifica por su SHA-256 (si cambia, hay que confirmar de nuevo). Vive en un almacén temporal con
 * caducidad: el texto puede contener el nombre de pila del contacto, así que se descarta al confirmarse o al caducar.
 */
final class DraftMessage
{
    public const STATUS_PENDING = 'pending_confirmation';

    public function __construct(
        public readonly string $id,
        public readonly int $tenantId,
        public readonly string $channel,
        public readonly string $templateKey,
        public readonly int $templateVersion,
        public readonly int $customerId,
        public readonly string $body,
        public readonly string $bodySha256,
        public readonly int $createdBy,
        public readonly CarbonImmutable $createdAt,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->greaterThanOrEqualTo($this->expiresAt);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'channel' => $this->channel, 'status' => self::STATUS_PENDING, 'requires_confirmation' => true,
            'template' => ['key' => $this->templateKey, 'version' => $this->templateVersion], 'customer_id' => $this->customerId,
            'body' => $this->body, 'body_sha256' => $this->bodySha256,
            'created_at' => $this->createdAt->toIso8601String(), 'expires_at' => $this->expiresAt->toIso8601String(),
            'notice' => 'Borrador: no se ha enviado nada. Revise el texto y confírmelo explícitamente; sin confirmación humana no se envía.',
        ];
    }

    /** @return array<string, mixed> */
    public function toStorage(): array
    {
        return [
            'id' => $this->id, 'tenant_id' => $this->tenantId, 'channel' => $this->channel, 'template_key' => $this->templateKey, 'template_version' => $this->templateVersion,
            'customer_id' => $this->customerId, 'body' => $this->body, 'body_sha256' => $this->bodySha256, 'created_by' => $this->createdBy,
            'created_at' => $this->createdAt->toIso8601String(), 'expires_at' => $this->expiresAt->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $d */
    public static function fromStorage(array $d): self
    {
        return new self(
            (string) $d['id'], (int) $d['tenant_id'], (string) $d['channel'], (string) $d['template_key'], (int) $d['template_version'], (int) $d['customer_id'],
            (string) $d['body'], (string) $d['body_sha256'], (int) $d['created_by'], CarbonImmutable::parse((string) $d['created_at']), CarbonImmutable::parse((string) $d['expires_at']),
        );
    }
}
