<?php

declare(strict_types=1);

namespace App\Services\DataCore\Sync;

use Carbon\CarbonImmutable;

final class StagingResult
{
    /**
     * `maxRecordedAt`: máximo de `source_recorded_at` LEGIBLE y no posterior a la cota (inicio de la sincronización + tolerancia; candidato a marca de agua).
     * `futureRecordedAt` / `unreadableRecordedAt`: filas cuya fecha de registro en la fuente es futura o ilegible; son anomalías y
     * NUNCA adelantan la marca de agua.
     */
    public function __construct(
        public int $rows = 0,
        public int $pages = 0,
        public ?CarbonImmutable $maxRecordedAt = null,
        public ?string $minEventDate = null,
        public ?string $maxEventDate = null,
        public int $futureRecordedAt = 0,
        public int $unreadableRecordedAt = 0,
        /** @var array<string, ?string> columna de la consulta => tipo observado (null = solo NULL en la extracción): huella de esquema */
        public array $columns = [],
        /** @var array<string, list<array{from: string, to: mixed, count: int}>> aplicaciones de mapeos de valores (transformaciones) */
        public array $valueMapHits = [],
        /** ¿Se capturó la extracción cruda (NDJSON comprimido) para Bronze? Ruta del archivo temporal. */
        public ?string $rawPath = null,
    ) {}
}
