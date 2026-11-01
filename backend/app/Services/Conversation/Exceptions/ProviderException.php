<?php

declare(strict_types=1);

namespace App\Services\Conversation\Exceptions;

use Illuminate\Http\JsonResponse;
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

    /** 502: el proveedor externo falló; el resto de la aplicación no se ve afectada. */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'El proveedor del asistente no respondió correctamente. Intente más tarde; los resultados de Predictily siguen disponibles.',
            'code' => 'assistant_provider_error',
            'reason' => $this->reason,
        ], 502);
    }
}
