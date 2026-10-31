<?php

declare(strict_types=1);

namespace App\Services\Conversation\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** El servicio conversacional no está disponible (sin clave, desactivado o proveedor desconocido). Se renderiza como 503. */
final class AssistantDisabledException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('El asistente conversacional no está disponible. Los resultados de Predictily siguen disponibles en el resto de la aplicación.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'assistant_disabled', 'reason' => $this->reason], 503);
    }
}
