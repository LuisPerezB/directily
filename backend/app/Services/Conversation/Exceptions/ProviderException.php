<?php

declare(strict_types=1);

namespace App\Services\Conversation\Exceptions;

use RuntimeException;

/**
 * Fallo del proveedor LLM (red, tiempo, estado HTTP, respuesta inválida). El mensaje es seguro para registrar: nunca
 * contiene el cuerpo de la respuesta, la clave ni el contenido de la conversación.
 */
final class ProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'provider_error', public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
