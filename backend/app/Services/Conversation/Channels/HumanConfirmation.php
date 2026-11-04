<?php

declare(strict_types=1);

namespace App\Services\Conversation\Channels;

use App\Enums\TenantRole;
use App\Models\User;
use App\Services\Conversation\Channels\Exceptions\DraftConfirmationException;
use Carbon\CarbonImmutable;

/**
 * Paso de confirmación humana explícita (V25-GWY-023): la ÚNICA vía que convierte un borrador en un ConfirmedMessage. Exige, en este orden: una persona (usuario) de la empresa del
 * borrador con la habilidad `decisions.manage`; que el borrador no haya caducado; que la huella SHA-256 del texto confirmado coincida con la del borrador; y que sea la primera
 * confirmación (uso único, también frente a carreras). El asistente no tiene ninguna herramienta que cree ni confirme borradores.
 */
final class HumanConfirmation
{
    public const ABILITY = 'decisions.manage';

    public function __construct(private readonly DraftStore $drafts) {}

    /** @throws DraftConfirmationException */
    public function confirm(DraftMessage $draft, User $human, ?TenantRole $role, string $bodySha256, ?CarbonImmutable $now = null): ConfirmedMessage
    {
        $now ??= CarbonImmutable::now();
        if ($role === null || ! $role->can(self::ABILITY)) {
            throw new DraftConfirmationException('forbidden');
        }
        if ($draft->isExpired($now)) {
            $this->drafts->discard($draft->tenantId, $draft->id);

            throw new DraftConfirmationException('draft_expired');
        }
        if (! hash_equals($draft->bodySha256, strtolower($bodySha256)) || ! hash_equals($draft->bodySha256, hash('sha256', $draft->body))) {
            throw new DraftConfirmationException('content_changed');
        }
        if (! $this->drafts->claim($draft->tenantId, $draft->id)) {
            throw new DraftConfirmationException('already_confirmed');
        }

        return ConfirmedMessage::issue($draft, (int) $human->getKey(), $now);
    }
}
