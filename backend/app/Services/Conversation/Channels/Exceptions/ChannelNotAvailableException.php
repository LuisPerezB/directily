<?php

declare(strict_types=1);

namespace App\Services\Conversation\Channels\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** El canal no existe: no está habilitado o no hay ningún proveedor registrado. Se renderiza como 404 (el canal «no existe», no «está prohibido»). */
final class ChannelNotAvailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El canal solicitado no está disponible.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'channel_not_available'], 404);
    }
}
