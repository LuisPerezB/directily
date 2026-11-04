<?php

declare(strict_types=1);

namespace App\Services\Decision;

/**
 * Recomendación ya normalizada, enriquecida y puntuada pero SIN escribir (V2.5 · W5-B). Permite al generador puntuar un lote entero,
 * persistirlo sin reordenar prioridades fila por fila y ordenar el lote al final; también es lo que cuenta el modo `--dry-run`.
 */
final class PreparedProposal
{
    /**
     * @param  array<string, mixed>  $columns  columnas de `decision_recommendations` sin tenant_id ni experiment_group (lo fija persist)
     * @param  array<string, mixed>  $eventPayload  evidencia del evento inicial
     */
    public function __construct(
        public readonly array $columns,
        public readonly string $key,
        public readonly ?float $score,
        public readonly ?int $proposerId,
        public readonly array $eventPayload,
    ) {}

    public function kind(): string
    {
        return (string) $this->columns['kind'];
    }

    public function horizon(): int
    {
        return (int) $this->columns['horizon_days'];
    }
}
