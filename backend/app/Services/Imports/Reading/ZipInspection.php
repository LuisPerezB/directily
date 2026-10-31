<?php

declare(strict_types=1);

namespace App\Services\Imports\Reading;

/** Resultado de una inspección ZIP superada (para trazas y pruebas). */
final readonly class ZipInspection
{
    public function __construct(
        public int $entries,
        public int $compressedBytes,
        public int $uncompressedBytes,
        /** Mayor razón descomprimido/comprimido entre las entradas evaluadas. */
        public float $maxEntryRatio,
        public int $sharedStringsBytes,
    ) {}
}
