<?php

declare(strict_types=1);

namespace App\Services\Conversation\Channels;

/**
 * Verificación de la firma de un webhook entrante (formato `X-Hub-Signature-256: sha256=<hex>` de WhatsApp/Meta): HMAC-SHA256 del cuerpo CRUDO con un secreto por entorno,
 * comparación en tiempo constante. Es una utilidad del contrato: Predictily no expone hoy ninguna ruta de webhook (DISEÑADO). El secreto nunca se registra.
 */
final class WebhookSignature
{
    public static function sign(string $secret, string $rawBody): string
    {
        return 'sha256='.hash_hmac('sha256', $rawBody, $secret);
    }

    public static function verify(string $secret, string $rawBody, ?string $header): bool
    {
        if ($secret === '' || $header === null || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(self::sign($secret, $rawBody), $header);
    }
}
