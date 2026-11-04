<?php

declare(strict_types=1);

namespace App\Services\Imports\Reading;

/** Resultado de una inspección ZIP superada (para trazas, pruebas y para la guarda de lectura). */
final readonly class ZipInspection
{
    /**
     * @param  array<string, int>  $entrySizes  tamaño descomprimido (ya verificado contra los bytes reales) de cada parte
     * @param  list<string>  $sheetParts  partes que el lector abre como hojas de cálculo (resueltas por workbook.xml + workbook.xml.rels)
     */
    public function __construct(
        public int $entries,
        public int $compressedBytes,
        public int $uncompressedBytes,
        /** Mayor razón descomprimido/comprimido entre las entradas evaluadas. */
        public float $maxEntryRatio,
        public int $sharedStringsBytes,
        public array $entrySizes = [],
        public array $sheetParts = [],
        public ?string $sharedStringsPart = null,
        public ?string $stylesPart = null,
    ) {}
}
