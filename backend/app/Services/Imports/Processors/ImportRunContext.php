<?php

declare(strict_types=1);

namespace App\Services\Imports\Processors;

use App\Models\ImportJob;
use App\Models\Tenant;
use App\Services\Imports\Parsing\ValueParser;
use App\Services\Imports\Reading\TabularReader;
use App\Services\Imports\Support\IssueCollector;
use Generator;
use Illuminate\Support\Facades\DB;

/** Estado compartido de una ejecución de validación/importación. */
final class ImportRunContext
{
    public readonly ValueParser $parser;
    public readonly IssueCollector $issues;
    /** @var array<string, mixed> */
    public readonly array $options;
    private int $lastProgress = -1;

    /** @param array<string, string|null> $mapping */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly ImportJob $job,
        public readonly TabularReader $reader,
        public readonly array $mapping,
        public readonly bool $commit,
    ) {
        $this->options = array_merge(self::defaultOptions(), $job->options ?? []);
        $this->parser = new ValueParser($tenant->timezone ?: 'America/Guayaquil', $this->options);
        $this->issues = new IssueCollector($tenant->id, $job->id, (int) config('predictily.imports.max_stored_errors', 10_000));
    }

    /** @return array<string, mixed> */
    public static function defaultOptions(): array
    {
        return [
            'date_format' => 'auto',
            'decimal_separator' => 'auto',
            'thousands_separator' => null,
            'csv_delimiter' => 'auto',
            'default_branch_code' => null,
            'create_missing_products' => true,
            'create_missing_branches' => false,
            'create_missing_categories' => true,
            'skip_invalid_rows' => false,
        ];
    }

    /**
     * Filas como arreglos campo-canónico => valor crudo.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function canonicalRows(): Generator
    {
        $headers = $this->reader->headers();
        $index = [];
        foreach ($this->mapping as $field => $header) {
            if ($header === null || $header === '') {
                continue;
            }
            $position = array_search($header, $headers, true);
            if ($position !== false) {
                $index[$field] = $position;
            }
        }
        foreach ($this->reader->rows() as $rowNumber => $values) {
            $row = [];
            foreach ($index as $field => $position) {
                $row[$field] = $values[$position] ?? null;
            }
            yield $rowNumber => $row;
        }
    }

    public function countRows(): int
    {
        $count = 0;
        foreach ($this->reader->rows() as $rowNumber => $_) {
            $count = $rowNumber;
        }

        return $count;
    }

    /** Actualiza el progreso (0-100) como máximo cada 2 puntos porcentuales. */
    public function progress(int $done, int $total, int $from = 0, int $to = 100): void
    {
        $pct = $total > 0 ? (int) floor($from + ($to - $from) * min(1, $done / $total)) : $to;
        if ($pct >= $this->lastProgress + 2 || $pct === $to) {
            $this->lastProgress = $pct;
            DB::table('import_jobs')->where('id', $this->job->id)->update(['progress' => min(99, $pct)]);
        }
    }
}
