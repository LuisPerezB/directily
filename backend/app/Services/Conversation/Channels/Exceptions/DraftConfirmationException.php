<?php

declare(strict_types=1);

namespace App\Services\Conversation\Channels\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** La confirmación humana de un borrador no procede. `reason`: forbidden | draft_expired | content_changed | already_confirmed | not_found. */
final class DraftConfirmationException extends RuntimeException
{
    private const STATUS = ['forbidden' => 403, 'draft_expired' => 410, 'content_changed' => 422, 'already_confirmed' => 409, 'not_found' => 404];

    private const MESSAGES = [
        'forbidden' => 'No tiene permiso para confirmar el envío de este mensaje.',
        'draft_expired' => 'El borrador caducó; cree uno nuevo.',
        'content_changed' => 'El contenido confirmado no coincide con el del borrador; revise el texto y confirme de nuevo.',
        'already_confirmed' => 'Este borrador ya fue confirmado.',
        'not_found' => 'No se encontró el borrador.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? 'La confirmación no procede.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'draft_'.$this->reason], self::STATUS[$this->reason] ?? 422);
    }
}
