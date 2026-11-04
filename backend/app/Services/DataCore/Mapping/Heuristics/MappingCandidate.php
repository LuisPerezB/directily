<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Heuristics;

/** Una columna candidata para un campo canónico, con su puntaje heurístico (0..1; NO es una probabilidad) y la evidencia que lo explica. */
final class MappingCandidate
{
    /** @param list<string> $evidence */
    public function __construct(
        public readonly string $field,
        public readonly string $tableKey,
        public readonly string $column,
        public float $score,
        public array $evidence,
        public readonly int $hops = 0,
    ) {}

    public function ref(): string
    {
        return $this->tableKey.'.'.$this->column;
    }
}
