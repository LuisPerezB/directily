<?php

declare(strict_types=1);

namespace App\Services\Imports\Support;

use App\Support\Privacy\PiiMasker;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Acumula errores/advertencias de una importación: cuenta por código y persiste
 * (en lotes, con tope) en import_errors. Los valores crudos se truncan y enmascaran.
 */
final class IssueCollector
{
    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    /** @var array<string, array{code: string, severity: string, count: int, message: string}> */
    private array $counts = [];

    /** @var array<int, string> fila => peor severidad */
    private array $rows = [];

    private int $stored = 0;

    public function __construct(
        private readonly ?int $tenantId = null,
        private readonly ?int $importJobId = null,
        private readonly int $maxStored = 10_000,
    ) {}

    public function add(int $rowNumber, Issue $issue): void
    {
        $key = $issue->severity.':'.$issue->code;
        if (! isset($this->counts[$key])) {
            $this->counts[$key] = ['code' => $issue->code, 'severity' => $issue->severity, 'count' => 0, 'message' => $issue->message];
        }
        $this->counts[$key]['count']++;

        $current = $this->rows[$rowNumber] ?? null;
        if ($issue->severity === 'error' || $current === null) {
            $this->rows[$rowNumber] = $issue->severity === 'error' ? 'error' : ($current ?? $issue->severity);
        }

        if ($this->importJobId !== null && $this->stored < $this->maxStored) {
            $this->buffer[] = [
                'tenant_id' => $this->tenantId,
                'import_job_id' => $this->importJobId,
                'row_number' => $rowNumber,
                'column' => $issue->column !== null ? mb_substr($issue->column, 0, 100) : null,
                'error_code' => $issue->code,
                'severity' => $issue->severity === 'info' ? 'warning' : $issue->severity,
                'message' => $issue->message,
                'raw_value' => $this->raw($issue->rawValue, $issue->column),
                'created_at' => now(),
            ];
            $this->stored++;
            if (count($this->buffer) >= 500) {
                $this->flush();
            }
        }
    }

    /** @param iterable<Issue> $issues */
    public function addMany(int $rowNumber, iterable $issues): void
    {
        foreach ($issues as $issue) {
            $this->add($rowNumber, $issue);
        }
    }

    public function flush(): void
    {
        if ($this->buffer !== []) {
            DB::table('import_errors')->insert($this->buffer);
            $this->buffer = [];
        }
    }

    public function rowHasError(int $rowNumber): bool
    {
        return ($this->rows[$rowNumber] ?? null) === 'error';
    }

    public function rowsWithErrors(): int
    {
        return count(array_filter($this->rows, fn ($s) => $s === 'error'));
    }

    public function rowsWithWarnings(): int
    {
        return count(array_filter($this->rows, fn ($s) => $s !== 'error'));
    }

    public function count(string $code, ?string $severity = null): int
    {
        $total = 0;
        foreach ($this->counts as $item) {
            if ($item['code'] === $code && ($severity === null || $item['severity'] === $severity)) {
                $total += $item['count'];
            }
        }

        return $total;
    }

    public function errorCount(): int
    {
        return array_sum(array_map(fn ($c) => $c['severity'] === 'error' ? $c['count'] : 0, $this->counts));
    }

    public function storedCount(): int
    {
        return $this->stored;
    }

    /** @return list<array{code: string, severity: string, count: int, message: string}> */
    public function summary(): array
    {
        $items = array_values($this->counts);
        usort($items, fn ($a, $b) => [$a['severity'] === 'error' ? 0 : 1, -$a['count']] <=> [$b['severity'] === 'error' ? 0 : 1, -$b['count']]);

        return $items;
    }

    /** Los códigos de cliente (cédula/RUC) y correos se enmascaran; el resto solo se trunca. */
    private function raw(mixed $value, ?string $column): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value) ?: '',
        };

        $sensitive = in_array($column, ['customer_code', 'customer_name'], true) || str_contains($text, '@');

        return mb_substr($sensitive ? PiiMasker::mask($text) : $text, 0, 255);
    }
}
