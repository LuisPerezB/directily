<?php

declare(strict_types=1);

namespace App\Services\Imports\Reading;

use Generator;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/** XLSX en streaming (OpenSpout). Usa la primera hoja; la primera fila no vacía es el encabezado. */
final class XlsxReader implements TabularReader
{
    /** @var list<string>|null */
    private ?array $headers = null;

    private ?string $sheetName = null;

    public function __construct(private readonly string $path) {}

    public function dialect(): array
    {
        $this->headers();

        return ['format' => 'xlsx', 'sheet' => $this->sheetName];
    }

    public function headers(): array
    {
        if ($this->headers === null) {
            foreach ($this->rawRows() as $row) {
                $this->headers = array_map(fn ($h) => trim($this->stringify($h)), $row);
                break;
            }
            $this->headers ??= [];
        }

        return $this->headers;
    }

    public function rows(): Generator
    {
        $first = true;
        $n = 0;
        $width = count($this->headers());
        foreach ($this->rawRows() as $row) {
            if ($first) {
                $first = false;

                continue;
            }
            yield ++$n => array_pad($row, $width, null);
        }
    }

    /** @return Generator<int, list<mixed>> */
    private function rawRows(): Generator
    {
        $reader = new Reader(new Options(SHOULD_FORMAT_DATES: false, SHOULD_PRESERVE_EMPTY_ROWS: false));
        $reader->open($this->path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $this->sheetName = $sheet->getName();
                foreach ($sheet->getRowIterator() as $row) {
                    $values = $row->toArray();
                    if (count(array_filter($values, fn ($v) => $v !== null && $v !== '')) === 0) {
                        continue;
                    }
                    yield $values;
                }
                break; // solo la primera hoja
            }
        } finally {
            $reader->close();
        }
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }
}
