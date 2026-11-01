<?php

declare(strict_types=1);

namespace App\Services\DataCore\Quality;

use App\Services\Imports\Support\Issue;
use App\Support\Privacy\PiiMasker;
use DateTimeInterface;

/**
 * Acumula los hallazgos de una carga para `data_quality_issues` (ADR-V25-24; W2-A).
 *
 * - Un hallazgo por observación (fila + código), con clase del catálogo, entidad, referencia del registro SIN PII, fila de origen, campo,
 *   valor original ACOTADO (200 caracteres) y enmascarado (nombres e identificaciones de clientes nunca se guardan), propuesta y motivo.
 * - TOPE de filas por informe (`data_quality.max_issue_rows_per_report`, 2000 por defecto): al llegar al tope se conservan los hallazgos
 *   de MAYOR prioridad (un BLOCKER desplaza a un WARNING) y el exceso se cuenta en `issue_counts` con la marca `truncated`.
 * - AUTO_FIX_SAFE nunca es una fila por celda: se agrega por (código, campo) con conteo y muestra, `applied = true`. Los agregados (y los
 *   hallazgos de resumen sin fila, p. ej. `stale_document_skipped`) no consumen el tope: están acotados por códigos × campos.
 * - Memoria acotada: guarda a lo sumo el tope de filas, sin importar el tamaño del archivo.
 */
final class FindingCollector
{
    /** Columnas cuyo valor identifica a una persona: jamás se guardan, ni siquiera acotadas. */
    private const PII_COLUMNS = ['customer_code', 'customer_name', 'customer_email', 'customer_phone', 'customer_address', 'tax_id', 'cedula', 'ruc', 'name_encrypted'];

    /**
     * Columnas que son un CÓDIGO REAL de negocio y no se enmascaran (un número de documento, SKU o sucursal de 10 dígitos no es una cédula).
     * Solo estas: `document_type`, `status` y todo lo demás pueden traer una columna mal mapeada (p. ej. una cédula como tipo de documento),
     * así que su valor —y el motivo, que lo cita— pasan por `PiiMasker`. `sku` es el equivalente de `product_sku` en la importación de productos.
     */
    private const CODE_COLUMNS = ['document_number', 'product_sku', 'sku', 'branch_code'];

    /** @var array<string, list<array<string, mixed>>> */
    private array $buckets = [];

    /** @var array<string, int> clase => hallazgos observados (todos, no solo los guardados) */
    private array $counts = [];

    private int $rowLevelObserved = 0;

    private int $stored = 0;

    /** @var array<string, array<string, array{count: int, samples: list<array{0: string, 1: string}>, first_row: int}>> código => campo => agregado */
    private array $autoFixes = [];

    /** @var list<array<string, mixed>> */
    private array $aggregates = [];

    public function __construct(private readonly string $entity, private readonly int $cap = 2000) {}

    public function entity(): string
    {
        return $this->entity;
    }

    public function observe(int $row, Issue $issue, ?string $recordRef = null): void
    {
        [$code, $class] = DataQualityCatalog::resolve($issue);
        $this->counts[$class] = ($this->counts[$class] ?? 0) + 1;
        $this->rowLevelObserved++;
        $finding = [
            'dq_class' => $class,
            'code' => mb_substr($code, 0, 60),
            'entity' => $this->entity,
            'record_ref' => $recordRef !== null ? mb_substr($recordRef, 0, 200) : null,
            'source_row' => $row > 0 ? $row : null,
            'field' => $issue->column !== null ? mb_substr($issue->column, 0, 60) : null,
            'original_value' => self::safeValue($issue->column, $issue->rawValue),
            'proposed_value' => null,
            'applied' => false,
            'reason' => self::safeReason($issue->column, $issue->message),
        ];
        $this->keep($class, $finding);
    }

    /**
     * Normalización aplicada a UNA celda: se agrega por (código, campo). Las seguras son AUTO_FIX_SAFE; una convención no inequívoca
     * (`date_format_ambiguous`) se registra con la clase que le da el catálogo (WARNING).
     */
    public function autoFix(string $code, string $field, int $row, mixed $original, mixed $proposed): void
    {
        $entry = &$this->autoFixes[$code][$field];
        if ($entry === null) {
            $entry = ['count' => 0, 'samples' => [], 'first_row' => $row];
        }
        $entry['count']++;
        if (count($entry['samples']) < (int) config('data_quality.auto_fix_samples', 3)) {
            $entry['samples'][] = [(string) self::safeValue($field, $original), (string) self::safeValue($field, $proposed)];
        }
        unset($entry);
    }

    /**
     * Hallazgo de RESUMEN (sin fila): lo que el procesador informa al final de la carga (documentos descartados por versión anterior,
     * días sin ventas…). Se agrega con su conteo.
     */
    public function aggregate(string $code, string $severity, int $count, string $message, ?string $field = null): void
    {
        $this->aggregates[] = [
            'dq_class' => DataQualityCatalog::classFor($code, $severity),
            'code' => mb_substr($code, 0, 60),
            'entity' => $this->entity,
            'record_ref' => null,
            'source_row' => null,
            'field' => $field,
            'original_value' => null,
            'proposed_value' => null,
            'applied' => false,
            'reason' => self::safeReason($field, $message),
            'count' => max(1, $count),
        ];
    }

    /** Totales por clase, con los agregados y los AUTO_FIX_SAFE (por celda) incluidos. @return array<string, int> */
    public function classCounts(): array
    {
        $counts = array_fill_keys(DataQualityCatalog::CLASSES, 0);
        foreach ($this->counts as $class => $n) {
            $counts[$class] += $n;
        }
        foreach ($this->aggregates as $aggregate) {
            $counts[$aggregate['dq_class']] += $aggregate['count'];
        }
        foreach ($this->autoFixes as $code => $fields) {
            foreach ($fields as $entry) {
                $counts[DataQualityCatalog::classFor($code, 'warning')] += $entry['count'];
            }
        }

        return $counts;
    }

    /** ¿Se omitió algún hallazgo por el tope? */
    public function truncated(): bool
    {
        return $this->rowLevelObserved > $this->stored;
    }

    public function omitted(): int
    {
        return max(0, $this->rowLevelObserved - $this->stored);
    }

    /** `issue_counts` del informe: conteo por clase y, solo si se aplicó el tope, la marca `truncated`. @return array<string, mixed> */
    public function issueCounts(): array
    {
        $counts = $this->classCounts();
        if ($this->truncated()) {
            $counts['truncated'] = true;
            $counts['issue_rows_stored'] = $this->stored;
            $counts['issue_rows_omitted'] = $this->omitted();
        }

        return $counts;
    }

    /** @return array<string, array<string, int>> código => campo => celdas normalizadas */
    public function autoFixTotals(): array
    {
        $out = [];
        foreach ($this->autoFixes as $code => $fields) {
            foreach ($fields as $field => $entry) {
                $out[$code][$field] = $entry['count'];
            }
        }

        return $out;
    }

    /**
     * Filas listas para insertar (sin tenant_id ni report_id), por prioridad de clase.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $rows = [];
        foreach (DataQualityCatalog::CLASSES as $class) {
            foreach ($this->buckets[$class] ?? [] as $finding) {
                $rows[] = $finding;
            }
        }
        foreach ($this->aggregates as $aggregate) {
            $count = $aggregate['count'];
            unset($aggregate['count']);
            $aggregate['reason'] = mb_substr(($count > 1 ? "{$count} × " : '').$aggregate['reason'], 0, 1000);
            $rows[] = $aggregate;
        }
        foreach ($this->autoFixes as $code => $fields) {
            foreach ($fields as $field => $entry) {
                $examples = implode('; ', array_map(fn (array $s) => "«{$s[0]}» → «{$s[1]}»", $entry['samples']));
                $rows[] = [
                    'dq_class' => DataQualityCatalog::classFor($code, 'warning'),
                    'code' => $code,
                    'entity' => $this->entity,
                    'record_ref' => null,
                    'source_row' => $entry['first_row'] > 0 ? $entry['first_row'] : null,
                    'field' => $field,
                    'original_value' => $entry['samples'][0][0] ?? null,
                    'proposed_value' => $entry['samples'][0][1] ?? null,
                    'applied' => true,
                    'reason' => mb_substr("{$entry['count']} valores ".(DataQualityCatalog::classFor($code, 'warning') === DataQualityCatalog::AUTO_FIX_SAFE ? 'normalizados automáticamente' : 'interpretados con una convención')
                        .' ('.(DataQualityCatalog::rationale($code) ?? $code).')'.($examples !== '' ? " Ejemplos: {$examples}." : ''), 0, 1000),
                ];
            }
        }

        return $rows;
    }

    /**
     * Motivo guardado en un hallazgo: el mensaje del importador cita el valor crudo («Tipo de documento no reconocido ('1710034065')»), así que
     * pasa por `PiiMasker` salvo que el campo sea un código real de negocio (ver CODE_COLUMNS).
     */
    public static function safeReason(?string $column, string $message): string
    {
        $text = $column !== null && in_array(strtolower($column), self::CODE_COLUMNS, true) ? $message : PiiMasker::mask($message);

        return mb_substr($text, 0, 1000);
    }

    /** Valor guardado en un hallazgo: acotado, sin nombres ni identificaciones de clientes. */
    public static function safeValue(?string $column, mixed $value): ?string
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
        $column = $column !== null ? strtolower($column) : null;
        if ($column !== null && (in_array($column, self::PII_COLUMNS, true) || str_starts_with($column, 'customer_name'))) {
            return '[CLIENTE]';
        }
        if ($column === null || ! in_array($column, self::CODE_COLUMNS, true) || str_contains($text, '@')) {
            $text = PiiMasker::mask($text);
        }

        return mb_substr($text, 0, (int) config('data_quality.original_value_max_chars', 200));
    }

    /** @param array<string, mixed> $finding */
    private function keep(string $class, array $finding): void
    {
        if ($this->stored < $this->cap) {
            $this->buckets[$class][] = $finding;
            $this->stored++;

            return;
        }
        // Tope alcanzado: un hallazgo de mayor prioridad desplaza al de menor prioridad guardado más reciente.
        $priority = DataQualityCatalog::priority($class);
        foreach (array_reverse(DataQualityCatalog::CLASSES) as $victimClass) {
            if (DataQualityCatalog::priority($victimClass) <= $priority) {
                break;
            }
            if (! empty($this->buckets[$victimClass])) {
                array_pop($this->buckets[$victimClass]);
                $this->buckets[$class][] = $finding;

                return;
            }
        }
        // Sin víctima de menor prioridad: el hallazgo se cuenta pero no se guarda (marca `truncated`).
    }
}
