<?php

declare(strict_types=1);

namespace App\Services\DataCore\Units;

/** Conversión encontrada: cantidad_destino = cantidad_origen × factor. */
final readonly class ConversionResult
{
    public function __construct(
        public float $factor,
        public string $scope,      // product | general
        public bool $inverse,      // true si se derivó 1/factor de la conversión declarada en sentido contrario
        public ?int $conversionId,
    ) {}

    public function convert(float $quantity): float
    {
        return $quantity * $this->factor;
    }
}
