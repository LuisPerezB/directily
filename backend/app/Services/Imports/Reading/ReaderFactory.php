<?php

declare(strict_types=1);

namespace App\Services\Imports\Reading;

use InvalidArgumentException;

final class ReaderFactory
{
    /**
     * @param  array<string, mixed>  $options  `csv_delimiter`; `xlsx_interactive` (por defecto true): false en la lectura en cola
     *                                         (validación e importación), que no está sujeta a la ventana interactiva de XlsxReader
     */
    public function make(string $path, string $format, array $options = []): TabularReader
    {
        return match ($format) {
            'csv' => new CsvReader($path, (string) ($options['csv_delimiter'] ?? 'auto')),
            'xlsx' => new XlsxReader($path, null, (bool) ($options['xlsx_interactive'] ?? true)),
            default => throw new InvalidArgumentException("Formato no soportado: {$format}"),
        };
    }

    public static function formatFromExtension(string $extension): ?string
    {
        return match (strtolower($extension)) {
            'csv', 'txt', 'tsv' => 'csv',
            'xlsx' => 'xlsx',
            default => null,
        };
    }
}
