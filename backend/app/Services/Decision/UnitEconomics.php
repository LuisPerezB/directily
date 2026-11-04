<?php

declare(strict_types=1);

namespace App\Services\Decision;

/**
 * Economía unitaria conocida de un producto a una fecha de corte (lectura as-of), con la confiabilidad de su costo.
 * Sin costo confiable `cost` es NULL y `costReasons` dice por qué: la contribución NO se calcula (nunca un cero ni un costo supuesto).
 */
final class UnitEconomics
{
    /**
     * @param  list<string>  $costReasons  códigos de por qué el costo no es confiable (vacío si lo es)
     * @param  array<string, mixed>  $stats  observaciones, cobertura y dispersión usadas, para la incertidumbre y la explicación
     */
    public function __construct(
        public readonly int $productId,
        public readonly ?float $price,
        public readonly ?float $cost,
        public readonly ?string $costSource,
        public readonly bool $costReliable,
        public readonly array $costReasons,
        public readonly array $stats,
    ) {}

    /** (precio − costo) / precio, solo con costo confiable y precio positivo. */
    public function marginRatio(): ?float
    {
        if (! $this->costReliable || $this->cost === null || $this->price === null || $this->price <= 0) {
            return null;
        }

        return ($this->price - $this->cost) / $this->price;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'price' => $this->price, 'cost' => $this->cost, 'cost_source' => $this->costSource, 'cost_reliable' => $this->costReliable,
            'cost_reasons' => $this->costReasons, 'stats' => $this->stats,
        ];
    }
}
