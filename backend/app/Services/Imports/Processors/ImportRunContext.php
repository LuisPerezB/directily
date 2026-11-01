<?php

declare(strict_types=1);

namespace App\Services\Imports\Processors;

use App\Models\BronzeObject;
use App\Models\ImportJob;
use App\Models\SourceSync;
use App\Models\Tenant;
use App\Services\DataCore\Quality\AutoFixDetector;
use App\Services\DataCore\Quality\FindingCollector;
use App\Services\DataCore\Quality\ValueMaps;
use App\Services\Imports\Fields\ImportFieldRegistry;
use App\Services\Imports\ImportEntity;
use App\Services\Imports\Parsing\ValueParser;
use App\Services\Imports\Reading\TabularReader;
use App\Services\Imports\Support\IssueCollector;
use App\Services\Sales\IngestionResolution;
use Generator;
use Illuminate\Support\Facades\DB;

/** Estado compartido de una ejecución de validación/importación. */
final class ImportRunContext
{
    public readonly ValueParser $parser;

    public readonly IssueCollector $issues;

    /** Hallazgos de calidad de la carga (`data_quality_issues`, ADR-V25-24): los alimenta `IssueCollector` y los procesadores. */
    public readonly FindingCollector $findings;

    /** Mapeos de valores por fuente (`options.value_maps`), aplicados a las filas canónicas ANTES de reconocer el vocabulario. */
    public readonly ValueMaps $valueMaps;

    /** @var array<string, mixed> */
    public readonly array $options;

    /** @var array<string, string> campo => tipo del importador */
    private array $fieldTypes = [];

    private readonly AutoFixDetector $autoFix;

    private int $lastProgress = -1;

    /**
     * @param  array<string, string|null>  $mapping
     * @param  ?IngestionResolution  $ingestion  modo de ingestión resuelto (solo importaciones de ventas; ADR-V25-23)
     * @param  ?BronzeObject  $bronze  objeto Bronze del original (linaje `bronze_object_id` de lo que se escriba)
     * @param  ?SourceSync  $sync  sincronización SQL que originó esta importación (null = archivo o API)
     */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly ImportJob $job,
        public readonly TabularReader $reader,
        public readonly array $mapping,
        public readonly bool $commit,
        public readonly ?IngestionResolution $ingestion = null,
        public readonly ?BronzeObject $bronze = null,
        public readonly ?SourceSync $sync = null,
    ) {
        $this->options = array_merge(self::defaultOptions(), $job->options ?? []);
        $this->parser = new ValueParser($tenant->timezone ?: 'America/Guayaquil', $this->options);
        $this->findings = new FindingCollector($job->entity_type, (int) config('data_quality.max_issue_rows_per_report', 2000));
        $this->issues = new IssueCollector($tenant->id, $job->id, (int) config('predictily.imports.max_stored_errors', 10_000), $this->findings);
        $this->valueMaps = new ValueMaps((array) ($this->options['value_maps'] ?? []));
        $this->autoFix = new AutoFixDetector;
        if (($entity = ImportEntity::tryFrom($job->entity_type)) !== null) {
            foreach (app(ImportFieldRegistry::class)->fields($entity) as $field) {
                $this->fieldTypes[$field->key] = $field->type;
            }
        }
    }

    /** ¿La carga omite las filas inválidas (y por tanto retiene en cuarentena los documentos con hallazgos de revisión)? */
    public function skipInvalidRows(): bool
    {
        return (bool) ($this->options['skip_invalid_rows'] ?? false);
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
     * Filas como arreglos campo-canónico => valor crudo, con los mapeos de valores ya aplicados.
     *
     * `$observe` (por omisión) cuenta las normalizaciones seguras (AUTO_FIX_SAFE) y las aplicaciones de mapeos de valores de la carga.
     * Una pasada auxiliar (p. ej. la que ubica la última fila de cada documento) debe pasar `false` para no contarlas dos veces. Las
     * normalizaciones de una fila se registran SOLO si la fila no terminó con error (se decide al reanudar el generador, cuando el
     * procesador ya cargó sus hallazgos).
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function canonicalRows(bool $observe = true): Generator
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
        $explicitDateFormat = ! in_array((string) ($this->options['date_format'] ?? 'auto'), ['auto', ''], true);
        $pending = [];
        $previous = null;
        foreach ($this->reader->rows() as $rowNumber => $values) {
            if ($observe && $previous !== null) {
                $this->settleFixes($previous, $pending);
            }
            $row = [];
            foreach ($index as $field => $position) {
                $row[$field] = $values[$position] ?? null;
            }
            if (! $this->valueMaps->isEmpty()) {
                foreach ($this->valueMaps->fields() as $field) {
                    if (array_key_exists($field, $row)) {
                        $row[$field] = $this->valueMaps->apply($field, $row[$field], $observe);
                    }
                }
            }
            $pending = $observe ? $this->autoFix->detect($row, $this->fieldTypes, $explicitDateFormat, $this->parser) : [];
            $previous = $rowNumber;
            yield $rowNumber => $row;
        }
        if ($observe && $previous !== null) {
            $this->settleFixes($previous, $pending);
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $pending
     */
    private function settleFixes(int $row, array $pending): void
    {
        if ($pending === [] || $this->issues->rowHasError($row)) {
            return;
        }
        foreach ($pending as [$code, $field, $original, $proposed]) {
            $this->findings->autoFix($code, $field, $row, $original, $proposed);
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
