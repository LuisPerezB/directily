<?php

declare(strict_types=1);

namespace App\Services\Imports\Reading;

use Generator;
use RuntimeException;

/**
 * CSV en streaming: detecta BOM, codificación (UTF-8 o Windows-1252) y delimitador
 * (, ; tab |) a partir de una muestra del archivo.
 */
final class CsvReader implements TabularReader
{
    private string $delimiter;

    private string $encoding;

    /** @var list<string>|null */
    private ?array $headers = null;

    public function __construct(private readonly string $path, string $delimiter = 'auto')
    {
        if (! is_readable($path)) {
            throw new RuntimeException('No se puede leer el archivo.');
        }
        $sample = (string) file_get_contents($path, false, null, 0, 65536);
        $sample = $this->stripBom($sample);
        $this->encoding = mb_check_encoding($this->trimPartialUtf8($sample), 'UTF-8') ? 'UTF-8' : 'Windows-1252';
        $this->delimiter = $delimiter === 'auto' || $delimiter === '' ? $this->detectDelimiter($sample) : ($delimiter === '\t' ? "\t" : $delimiter);
    }

    public function dialect(): array
    {
        return ['format' => 'csv', 'delimiter' => $this->delimiter === "\t" ? '\t' : $this->delimiter, 'encoding' => $this->encoding];
    }

    public function headers(): array
    {
        if ($this->headers === null) {
            foreach ($this->rawRows() as $row) {
                $this->headers = array_map(fn ($h) => trim((string) $h), $row);
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
        foreach ($this->rawRows() as $row) {
            if ($first) {
                $first = false;

                continue;
            }
            yield ++$n => $row;
        }
    }

    /** @return Generator<int, list<string|null>> filas no vacías */
    private function rawRows(): Generator
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se puede abrir el archivo.');
        }
        try {
            $isFirst = true;
            while (($row = fgetcsv($handle, null, $this->delimiter, '"', '')) !== false) {
                if ($row === [null] || $row === []) {
                    continue;
                }
                if ($isFirst && isset($row[0])) {
                    $row[0] = $this->stripBom((string) $row[0]);
                    $isFirst = false;
                }
                if ($this->encoding !== 'UTF-8') {
                    $row = array_map(fn ($v) => $v === null ? null : mb_convert_encoding($v, 'UTF-8', 'Windows-1252'), $row);
                }
                if (count(array_filter($row, fn ($v) => $v !== null && trim($v) !== '')) === 0) {
                    continue;
                }
                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }

    private function stripBom(string $text): string
    {
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }

    /** Evita falsos negativos de UTF-8 por un carácter multibyte cortado al final de la muestra. */
    private function trimPartialUtf8(string $sample): string
    {
        $cut = strrpos($sample, "\n");

        return $cut !== false ? substr($sample, 0, $cut) : $sample;
    }

    private function detectDelimiter(string $sample): string
    {
        $lines = array_slice(array_filter(preg_split('/\r\n|\n|\r/', $sample) ?: [], fn ($l) => trim($l) !== ''), 0, 20);
        if ($lines === []) {
            return ',';
        }
        $best = ',';
        $bestScore = -1.0;
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts = array_map(fn ($l) => count(str_getcsv($l, $candidate, '"', '')), $lines);
            $min = min($counts);
            if ($min < 2) {
                continue;
            }
            // Preferir el delimitador que produce más columnas de forma consistente.
            $consistency = count(array_filter($counts, fn ($c) => $c === $counts[0])) / count($counts);
            $score = $counts[0] * $consistency;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }
}
