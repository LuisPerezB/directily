<?php

declare(strict_types=1);

namespace App\Services\Imports\Reading;

use Generator;

/** Lector en streaming de archivos tabulares (CSV/XLSX). */
interface TabularReader
{
    /** @return list<string> encabezados originales (primera fila no vacía) */
    public function headers(): array;

    /**
     * Filas de datos (sin encabezado). Clave = número de fila de datos (1 = primera fila).
     * Valores alineados con headers(); tipos: string|int|float|bool|\DateTimeInterface|null.
     *
     * @return Generator<int, list<mixed>>
     */
    public function rows(): Generator;

    /** @return array<string, mixed> metadatos detectados (delimitador, codificación, hoja) */
    public function dialect(): array;
}
