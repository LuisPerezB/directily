<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Models\DecisionEvent;
use App\Models\DecisionRecommendation;

/** Resultado de una transición: la recomendación ya actualizada, su evento de auditoría y si fue una repetición idempotente (sin efectos nuevos). */
final class TransitionResult
{
    public function __construct(
        public readonly DecisionRecommendation $recommendation,
        public readonly DecisionEvent $event,
        public readonly bool $replayed,
    ) {}
}
