<?php

declare(strict_types=1);

namespace Tests\Feature\Radar\Support;

use App\Contracts\Decision\RecommendationSink;
use App\Models\DecisionRecommendation;
use RuntimeException;

/**
 * Doble de prueba del Decision Engine (W5-B) para la lógica del Radar: guarda lo que se le propone en `decision_recommendations`, con la clave de
 * idempotencia del contrato (SHA-256 de tipo, corte, horizonte, entidad y fuente) y un score/prioridad deterministas. No es la implementación real: sirve
 * para probar que el Radar propone lo correcto y que sus listas leen lo propuesto.
 */
final class FakeRecommendationSink implements RecommendationSink
{
    /** Columnas de `decision_recommendations` que acepta del proponente (`expected_units` y `unit_price` solo informan el valor: el doble los ignora). */
    private const COLUMNS = [
        'kind', 'as_of', 'horizon_days', 'entity_type', 'customer_id', 'product_id', 'branch_id', 'source_core_run_id', 'source_forecast_run_id', 'data_snapshot_id',
        'model_versions', 'probability', 'interval', 'assumptions', 'constraints', 'availability', 'action_cost', 'expected_gross_value', 'expected_contribution',
        'uncertainty', 'explanation', 'policy', 'next_step',
    ];

    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** Si no es null, propose() lanza para las llamadas cuyo kind sea este. */
    public ?string $failKind = null;

    public function propose(array $attributes): DecisionRecommendation
    {
        $this->calls[] = $attributes;
        if ($this->failKind !== null && $attributes['kind'] === $this->failKind) {
            throw new RuntimeException('El motor de decisiones rechazó la oportunidad (prueba).');
        }
        $key = hash('sha256', implode('|', [
            $attributes['kind'], $attributes['as_of'], $attributes['horizon_days'], $attributes['entity_type'], $attributes['customer_id'],
            $attributes['product_id'] ?? '', $attributes['source_core_run_id'] ?? '',
        ]));
        $existing = DecisionRecommendation::query()->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $score = round(((float) ($attributes['expected_gross_value'] ?? 0)) * ((float) ($attributes['probability'] ?? 0.5)), 6);

        return DecisionRecommendation::query()->create(array_intersect_key($attributes, array_flip(self::COLUMNS)) + [
            'idempotency_key' => $key, 'score_formula_version' => str_repeat('f', 64), 'score' => $score, 'priority' => $score >= 5 ? 1 : 3,
            'attribution_label' => 'potential_unproven', 'status' => 'suggested', 'proposed_by_kind' => 'system',
            'next_step' => $attributes['next_step'] ?? 'Contactar al cliente (siguiente paso del doble de prueba).',
        ]);
    }
}
