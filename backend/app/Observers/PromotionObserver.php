<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Promotion;
use App\Services\Promotions\PromotionRevisionRecorder;

/**
 * Mantiene `known_at` y el historial de versiones de las promociones (hallazgo F5).
 * Las escrituras directas con DB::table no pasan por aquí: deben llamar al recorder.
 */
final class PromotionObserver
{
    public function __construct(private readonly PromotionRevisionRecorder $recorder) {}

    /** known_at por defecto = momento de alta en Predictily (idéntico a created_at). */
    public function creating(Promotion $promotion): void
    {
        if ($promotion->getAttribute('known_at') !== null) {
            return;
        }
        if ($promotion->usesTimestamps() && $promotion->getAttribute($promotion->getCreatedAtColumn()) === null) {
            $promotion->setCreatedAt($promotion->freshTimestamp());
        }
        $promotion->setAttribute('known_at', $promotion->getAttribute($promotion->getCreatedAtColumn()) ?? $promotion->freshTimestamp());
    }

    public function created(Promotion $promotion): void
    {
        $this->record($promotion);
    }

    /** Cambio de un campo versionado o restauración (deleted_at vuelve a NULL). */
    public function updated(Promotion $promotion): void
    {
        if ($promotion->wasChanged([...PromotionRevisionRecorder::TRACKED, $promotion->getDeletedAtColumn()])) {
            $this->record($promotion);
        }
    }

    /** Baja lógica: queda como versión `deleted` (lo planificado sigue visible en cortes anteriores). */
    public function deleted(Promotion $promotion): void
    {
        if (! $promotion->isForceDeleting()) {
            $this->record($promotion);
        }
    }

    private function record(Promotion $promotion): void
    {
        $this->recorder->record((int) $promotion->getAttribute('tenant_id'), (int) $promotion->getKey(), $promotion->revisionSource);
    }
}
