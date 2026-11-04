<?php

declare(strict_types=1);

namespace App\Services\Radar;

use App\Contracts\Decision\RecommendationSink;

/**
 * Oportunidad calculada por el Radar y todavía NO propuesta: lo que {@see CommercialOpportunityService::build()} devuelve. Se convierte en
 * recomendación solo cuando alguien la entrega al Decision Engine por {@see RecommendationSink::propose()}.
 */
final class OpportunityCandidate
{
    /** @param array<string, mixed> $attributes campos de §11 que aporta la fachada (ver CommercialOpportunityService::SINK_FIELDS) */
    public function __construct(
        public readonly string $kind,
        public readonly int $horizonDays,
        public readonly int $customerId,
        public readonly ?int $productId,
        public readonly array $attributes,
    ) {}

    /** Carga para RecommendationSink::propose(): sin tenant_id (lo fija TenantContext) ni idempotency_key (la calcula la implementación). */
    public function toSinkAttributes(): array
    {
        return $this->attributes;
    }
}
