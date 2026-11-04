<?php

declare(strict_types=1);

namespace App\Services\Conversation\Channels;

use Carbon\CarbonImmutable;
use LogicException;

/**
 * Mensaje CONFIRMADO por una persona: lo único que un canal acepta enviar. El constructor es privado y solo HumanConfirmation::confirm() lo emite (una prueba de arquitectura
 * verifica que ningún otro código de la aplicación lo construye). Lleva quién lo confirmó, cuándo y la huella del texto exacto confirmado.
 */
final class ConfirmedMessage
{
    private function __construct(
        public readonly string $draftId,
        public readonly int $tenantId,
        public readonly string $channel,
        public readonly string $templateKey,
        public readonly int $customerId,
        public readonly string $body,
        public readonly string $bodySha256,
        public readonly int $confirmedBy,
        public readonly CarbonImmutable $confirmedAt,
    ) {}

    /**
     * @internal Solo HumanConfirmation::confirm() la invoca, después de verificar al humano, la huella, la caducidad y el uso único. PHP no tiene «friend»: se comprueba en
     * tiempo de ejecución que quien llama es HumanConfirmation y una prueba de arquitectura analiza el código de la aplicación con el analizador léxico de PHP (no por texto).
     * No es una barrera contra la reflexión: protege contra uso accidental o descuidado, no contra código malicioso dentro de la aplicación.
     *
     * @throws LogicException si no la llama HumanConfirmation
     */
    public static function issue(DraftMessage $draft, int $confirmedBy, CarbonImmutable $at): self
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['class'] ?? null;
        if ($caller !== HumanConfirmation::class) {
            throw new LogicException('Un mensaje confirmado solo lo emite HumanConfirmation.');
        }

        return new self($draft->id, $draft->tenantId, $draft->channel, $draft->templateKey, $draft->customerId, $draft->body, $draft->bodySha256, $confirmedBy, $at);
    }
}
