<?php

declare(strict_types=1);

namespace App\Services\Sales;

/**
 * Resultado de resolver el modo de ingestión (ADR-V25-23) de una escritura de ventas: el modo y la base con la que se
 * decidió (declarado por quien escribe o inferido por la regla de primera carga). Es de solo lectura.
 */
final readonly class IngestionResolution
{
    public function __construct(public string $mode, public string $basis) {}

    /** @return array{ingestion_mode: string, ingestion_mode_basis: string} */
    public function toArray(): array
    {
        return ['ingestion_mode' => $this->mode, 'ingestion_mode_basis' => $this->basis];
    }
}
