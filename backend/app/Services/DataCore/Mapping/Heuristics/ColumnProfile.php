<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Heuristics;

/**
 * Perfil estadístico de una columna a partir de una muestra acotada. Solo proporciones y, para columnas categóricas de vocabulario (tipo y estado
 * de documento), los valores distintos ENMASCARADOS y acotados. Nunca valores de columnas con nombre de dato personal: esas no se leen.
 */
final readonly class ColumnProfile
{
    /**
     * @param  ?list<array{value: string, count: int}>  $distinct  valores distintos enmascarados (null = no categórica, demasiados o no se pidió)
     */
    public function __construct(
        public string $column,
        public int $sampledRows,
        public float $nonNullRatio,
        public float $dateRatio = 0.0,
        public float $numericRatio = 0.0,
        public float $booleanRatio = 0.0,
        public ?array $distinct = null,
        public bool $distinctComplete = true,
        public ?string $note = null,
        public float $emailRatio = 0.0,
        public float $phoneRatio = 0.0,
        public float $freeTextRatio = 0.0,
        public int $suppressedRare = 0,
    ) {}

    /**
     * ¿La forma de los valores de la columna es la de datos personales o texto libre (correos, teléfonos con separadores, fechas, frases)? Con ello un campo que identifica registros
     * (código de cliente, SKU, sucursal…) NO se propone, aunque su nombre lo sugiera. Solo proporciones: ningún valor sale de la muestra.
     */
    public function looksPersonalOrFreeText(): bool
    {
        return max($this->emailRatio, $this->phoneRatio, $this->freeTextRatio, $this->dateRatio) >= 0.5;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['column' => $this->column, 'sampled_rows' => $this->sampledRows, 'non_null_ratio' => round($this->nonNullRatio, 3), 'date_ratio' => round($this->dateRatio, 3),
            'numeric_ratio' => round($this->numericRatio, 3), 'boolean_ratio' => round($this->booleanRatio, 3), 'email_ratio' => round($this->emailRatio, 3),
            'phone_ratio' => round($this->phoneRatio, 3), 'free_text_ratio' => round($this->freeTextRatio, 3), 'suppressed_rare_values' => $this->suppressedRare,
            'distinct_values' => $this->distinct === null ? null : count($this->distinct), 'distinct_complete' => $this->distinctComplete, 'note' => $this->note];
    }
}
