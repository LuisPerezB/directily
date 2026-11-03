<?php

declare(strict_types=1);

namespace App\Services\Outcomes;

use Carbon\CarbonImmutable;

/** Lo que pasó con una recomendación antes de medirla: la acción (`action_kind`), cuándo se registró y de dónde se sabe. */
final class ActionRecord
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $kind,
        public readonly ?CarbonImmutable $at,
        public readonly ?string $source,
        public readonly ?int $sourceEventId,
        public readonly array $details,
    ) {}

    public function acted(): bool
    {
        return in_array($this->kind, OutcomeVocabulary::ACTED, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'at' => $this->at?->toIso8601String(), 'source' => $this->source, 'source_event_id' => $this->sourceEventId] + $this->details;
    }
}
