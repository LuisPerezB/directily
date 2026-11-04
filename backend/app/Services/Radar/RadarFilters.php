<?php

declare(strict_types=1);

namespace App\Services\Radar;

/**
 * Filtros compartidos de /radar/summary, /radar/opportunities y /radar/agenda (V25-AGD-008 y -009): cliente, SKU, horizonte, vendedor, segmento, zona,
 * categoría y estado. «Vendedor» es el usuario asignado a la recomendación (`assigned_user_id`). Es un FILTRO: el alcance por cartera del rol comercial vendedor (W6-A) lo aplica
 * `PredictiveSalesAgenda` con el CommercialScope de la persona, y el filtro se compone con él (no lo amplía).
 */
final class RadarFilters
{
    /** Ordenamientos permitidos → columna. */
    public const ORDER = [
        'score' => 'score', 'priority' => 'priority', 'expected_gross_value' => 'expected_gross_value', 'expected_contribution' => 'expected_contribution',
        'probability' => 'probability', 'as_of' => 'as_of', 'horizon_days' => 'horizon_days',
    ];

    public function __construct(
        public readonly ?int $customerId = null,
        public readonly ?int $productId = null,
        public readonly ?int $horizon = null,
        public readonly ?int $sellerId = null,
        public readonly ?int $segmentId = null,
        public readonly ?string $modelSegment = null,
        public readonly ?string $zone = null,
        public readonly ?int $categoryId = null,
        public readonly ?string $state = null,
        public readonly ?string $status = null,
        public readonly ?string $kind = null,
        public readonly ?string $asOf = null,
        public readonly ?string $orderBy = null,
        public readonly ?string $direction = null,
        /** El usuario puede aprobar (decisions.approve): solo él ve la pertenencia a un experimento y el conteo de lo retenido en control. Lo fija el controlador, no la petición. */
        public readonly bool $privileged = false,
    ) {}

    /** @param array<string, mixed> $v datos ya validados por la petición */
    public static function fromValidated(array $v): self
    {
        $int = fn (string $k): ?int => isset($v[$k]) && $v[$k] !== '' ? (int) $v[$k] : null;
        $str = fn (string $k): ?string => isset($v[$k]) && $v[$k] !== '' ? (string) $v[$k] : null;

        return new self(
            $int('customer_id'), $int('product_id'), $int('horizon'), $int('seller_id'), $int('segment_id'), $str('segment'), $str('zone'), $int('category_id'),
            $str('state'), $str('status'), $str('kind'), $str('as_of'), $str('order_by'), $str('direction'),
        );
    }

    /** Copia sin el filtro de estado heredado (para el desglose por estado del resumen). */
    public function withoutState(): self
    {
        return new self($this->customerId, $this->productId, $this->horizon, $this->sellerId, $this->segmentId, $this->modelSegment, $this->zone, $this->categoryId,
            null, $this->status, $this->kind, $this->asOf, $this->orderBy, $this->direction, $this->privileged);
    }

    public function withPrivileged(bool $privileged): self
    {
        return new self($this->customerId, $this->productId, $this->horizon, $this->sellerId, $this->segmentId, $this->modelSegment, $this->zone, $this->categoryId,
            $this->state, $this->status, $this->kind, $this->asOf, $this->orderBy, $this->direction, $privileged);
    }

    /** ¿Hay un filtro de entidad (cliente, SKU, segmento, zona, categoría, vendedor, segmento del modelo)? Con uno, un conteo de control delataría la pertenencia de esa entidad. */
    public function hasEntityFilter(): bool
    {
        return $this->customerId !== null || $this->productId !== null || $this->sellerId !== null || $this->segmentId !== null || $this->modelSegment !== null
            || $this->zone !== null || $this->categoryId !== null;
    }
}
