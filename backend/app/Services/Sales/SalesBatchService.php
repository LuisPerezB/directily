<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Exceptions\DomainStateException;
use App\Models\BronzeObject;
use App\Models\Tenant;
use App\Services\Catalog\CatalogResolver;
use App\Services\DataCore\Quality\AutoFixDetector;
use App\Services\DataCore\Quality\BronzeCaptureException;
use App\Services\DataCore\Quality\BronzeReprocessor;
use App\Services\DataCore\Quality\FindingCollector;
use App\Services\DataCore\Quality\IngestionBronze;
use App\Services\DataCore\Quality\IngestionQuality;
use App\Services\DataCore\Quality\QuarantineService;
use App\Services\Imports\Fields\ImportFieldRegistry;
use App\Services\Imports\ImportEntity;
use App\Services\Imports\Parsing\ValueParser;
use App\Services\Plans\PlanLimitService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ingesta de ventas por API REST (conector). Reutiliza EXACTAMENTE la normalización,
 * ensamblado y escritura del importador de archivos: mismo resultado para el mismo dato.
 *
 * W2-A (ADR-V25-24): el cuerpo del lote se guarda en Bronze (`api_batch`) ANTES de normalizar; cada documento escrito lleva su linaje
 * (`bronze_object_id`, `source_row` = posición del documento en el lote, `schema_version`); el informe del lote
 * (`data_quality_reports`, alcance `sales_batch`) y sus hallazgos (`data_quality_issues`) se persisten sin consultas por documento.
 * Los documentos rechazados se devuelven con su detalle (el integrador es dueño del origen y reenvía); el cuerpo íntegro queda en Bronze.
 */
final class SalesBatchService
{
    public function __construct(
        private readonly SalesDocumentWriter $writer,
        private readonly PlanLimitService $plans,
        private readonly IngestionModeResolver $modes,
        private readonly IngestionBronze $bronze,
        private readonly IngestionQuality $dq,
        private readonly ImportFieldRegistry $fields,
        private readonly QuarantineService $quarantine,
    ) {}

    /**
     * `$ingestionMode` (backfill | incremental) es opcional: sin él, el lote es `incremental` (conocido desde que Predictily lo recibe).
     *
     * @param  list<array<string, mixed>>  $documents
     * @param  array<string, mixed>  $options
     * @param  ?BronzeObject  $replay  objeto Bronze ya guardado (reprocesamiento): no se vuelve a capturar
     * @param  bool  $forceReplay  en un reprocesamiento, escribe aunque haya documentos más nuevos sin orden de fuente (ver BronzeReprocessor)
     * @return array{created: int, updated: int, unchanged: int, stale_skipped: int, revisions_archived: int, updated_without_source_order: int, unchanged_mode_mismatch: int, future_recorded_at: int, recorded_at_unreadable: int, document_number_shared_with_unknown_type: int, rejected: int, ingestion_mode: string, ingestion_mode_basis: string, bronze_object_id: ?int, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
     *
     * @throws DomainStateException si el original no pudo guardarse en Bronze (BLOCKER: no se procesa nada)
     */
    public function ingest(Tenant $tenant, array $documents, ?int $dataSourceId = null, array $options = [], ?string $ingestionMode = null, ?BronzeObject $replay = null, bool $forceReplay = false): array
    {
        $ingestion = $this->modes->forBatch($ingestionMode);
        // Bronze ANTES de normalizar: sin original no hay linaje ni reprocesamiento, así que la carga se detiene.
        $bronze = $replay;
        if ($bronze === null) {
            try {
                $bronze = $this->bronze->captureBatch($tenant, auth()->id(), $dataSourceId, ['documents' => $documents, 'options' => $options, 'ingestion_mode' => $ingestionMode])['object'];
            } catch (BronzeCaptureException $e) {
                Log::error('No se pudo guardar el lote en Bronze', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
                try {
                    $this->dq->recordBlocker($tenant->id, 'sales', 'bronze_capture_failed', 'El lote no pudo guardarse en Bronze antes de normalizar; no se procesó.');
                } catch (Throwable) {
                    // El informe es secundario: el bloqueo de la carga no depende de él.
                }
                throw new DomainStateException('No se pudo conservar el lote original (Bronze): no se procesó nada. Reintente.', 'bronze_unavailable');
            }
        }
        $findings = new FindingCollector('sales', (int) config('data_quality.max_issue_rows_per_report', 2000));
        $fieldTypes = [];
        foreach ($this->fields->fields(ImportEntity::Sales) as $field) {
            $fieldTypes[$field->key] = $field->type;
        }
        $autoFix = new AutoFixDetector;
        $options = array_merge(['create_missing_products' => true, 'create_missing_branches' => false, 'create_missing_categories' => true], $options);
        $parser = new ValueParser($tenant->timezone ?: 'America/Guayaquil', $options);
        $normalizer = new SalesLineNormalizer($parser, $tenant->currency ?: 'USD', $tenant->timezone ?: 'America/Guayaquil');
        $catalog = new CatalogResolver($tenant, $options);
        $errors = [];
        $warnings = [];
        $valid = [];
        $seen = [];
        $lines = 0;
        $lineErrors = 0;
        $recordedAnomalies = ['future' => 0, 'unreadable' => 0];
        $headerOf = fn (array $document): array => array_intersect_key($document, array_flip([
            'document_number', 'document_type', 'status', 'issued_at', 'branch_code', 'currency', 'channel',
            'customer_code', 'customer_segment', 'customer_province', 'source_recorded_at',
        ]));
        // Números que traen algún documento de tipo NO reconocido (error REVIEW_REQUIRED, no se escribe): un documento reconocido con
        // el mismo número en el lote SÍ se escribe (la numeración suele ser por tipo o serie) y recibe un aviso (SalesLineNormalizer::sharedNumberWarning).
        $sharedNumberDocuments = 0;
        $unknownTypeNumbers = [];
        foreach ($documents as $document) {
            $documentKey = $normalizer->documentKey($headerOf($document));
            if ($documentKey !== null && SalesLineNormalizer::isUnknownTypeKey($documentKey)) {
                $unknownTypeNumbers[SalesLineNormalizer::numberOfKey($documentKey)] = true;
            }
        }

        foreach ($documents as $index => $document) {
            $assembler = new SalesDocumentAssembler;
            $docErrors = [];
            $header = $headerOf($document);
            foreach (($document['items'] ?? []) as $itemIndex => $item) {
                $raw = $header + array_intersect_key($item, array_flip([
                    'product_sku', 'product_name', 'category', 'quantity', 'unit_price', 'discount', 'tax', 'net_amount', 'unit_cost', 'line_number',
                ]));
                [$line, $issues] = $normalizer->normalize($raw);
                if ($line !== null) {
                    [$branchCode, $branchIssues] = $catalog->checkBranch($line['branch_code']);
                    $issues = array_merge($issues, $branchIssues, $branchCode !== null ? $catalog->checkProduct($line['product_sku'], 'product_sku', $line['sale_date']) : []);
                    $line['branch_code'] = $branchCode;
                    $line['source_row'] = $index + 1; // linaje: posición del documento en el lote
                    if (count(array_filter($issues, fn ($i) => $i->isError())) === 0) {
                        $issues = array_merge($issues, $assembler->add($line));
                    }
                }
                $rowHasError = false;
                foreach ($issues as $issue) {
                    $rowHasError = $rowHasError || $issue->isError();
                    $findings->observe($index + 1, $issue, $normalizer->documentKey($header));
                    if (($anomaly = SalesLineNormalizer::recordedAtAnomaly($issue)) !== null) {
                        $recordedAnomalies[$anomaly]++;
                    }
                    $entry = ['index' => $index, 'item' => $itemIndex] + $issue->toArray();
                    if ($issue->isError()) {
                        $docErrors[] = $entry;
                    } else {
                        $warnings[] = $entry;
                    }
                }
                if ($rowHasError) {
                    $lineErrors++;
                } else {
                    foreach ($autoFix->detect($raw, $fieldTypes, false, $parser) as [$code, $field, $original, $proposed]) {
                        $findings->autoFix($code, $field, $index + 1, $original, $proposed);
                    }
                }
                $lines++;
            }
            $key = $normalizer->documentKey($header);
            if ($key !== null && isset($seen[$key])) {
                $docErrors[] = ['index' => $index, 'field' => 'document_number', 'code' => 'duplicate_in_file', 'severity' => 'error', 'message' => 'Documento repetido en el mismo lote.'];
            }
            if ($docErrors !== []) {
                $errors = array_merge($errors, $docErrors);

                continue;
            }
            $docs = $assembler->drain();
            if ($docs === []) {
                $warnings[] = ['index' => $index, 'code' => 'empty_document', 'severity' => 'warning', 'message' => 'El documento no tiene líneas válidas.'];

                continue;
            }
            $seen[$key] = true;
            $valid[] = $docs[0];
            if ($key !== null && ! SalesLineNormalizer::isUnknownTypeKey($key) && isset($unknownTypeNumbers[SalesLineNormalizer::numberOfKey($key)])) {
                $sharedNumberDocuments++;
                $shared = SalesLineNormalizer::sharedNumberWarning(SalesLineNormalizer::numberOfKey($key));
                $findings->observe($index + 1, $shared, $key);
                $warnings[] = ['index' => $index] + $shared->toArray();
            }
        }

        $result = [
            'created' => 0, 'updated' => 0, 'unchanged' => 0, 'stale_skipped' => 0, 'revisions_archived' => 0, 'updated_without_source_order' => 0,
            'unchanged_mode_mismatch' => 0,
            // Marcas `source_recorded_at` descartadas (posteriores a ahora + tolerancia, o ilegibles): la fila se escribe sin orden de versión.
            'future_recorded_at' => $recordedAnomalies['future'] + $recordedAnomalies['unreadable'], 'recorded_at_unreadable' => $recordedAnomalies['unreadable'],
            'document_number_shared_with_unknown_type' => $sharedNumberDocuments,
            'rejected' => count($documents) - count($valid), ...$ingestion->toArray(), 'bronze_object_id' => $bronze->id,
            'errors' => $errors, 'warnings' => array_slice($warnings, 0, 200),
        ];
        if ($valid === []) {
            $this->report($tenant, $bronze, $result, $findings, count($documents), $lines, $lineErrors);

            return $result;
        }
        if ($replay === null) {
            if ($catalog->productsToCreate() > 0) {
                $this->plans->assertCan($tenant, 'max_active_products', $catalog->productsToCreate());
            }
            $this->plans->assertCan($tenant, 'max_import_rows_per_month', $lines);
        }

        if ($replay !== null && ! $forceReplay) {
            // Reprocesar un lote VIEJO sin `source_recorded_at` ganaría por «última ingestión» y revertiría versiones más nuevas: se avisa y se detiene.
            $newer = BronzeReprocessor::newerThanOriginal($this->writer->classify($tenant, $valid, $ingestion->mode), $replay);
            if ($newer > 0) {
                throw new DomainStateException(
                    "{$newer} documento(s) ya están en Silver con otro contenido y se escribieron DESPUÉS de este original, y el lote no trae orden de fuente (source_recorded_at): reprocesarlo los sobrescribiría. Use --force para confirmarlo.",
                    'reprocess_would_overwrite_newer',
                    ['documents' => $newer],
                );
            }
        }
        $written = $this->writer->write($tenant, $valid, $catalog, ['data_source_id' => $dataSourceId, 'ingestion_mode' => $ingestion->mode, 'bronze_object_id' => $bronze->id]);
        $this->quarantine->supersedePending(
            $tenant->id,
            array_keys(array_filter($written['states'], fn (string $state) => in_array($state, [SalesDocumentWriter::STATE_NEW, SalesDocumentWriter::STATE_UPDATED, SalesDocumentWriter::STATE_UNCHANGED], true))),
            'later_load',
        );
        if ($replay === null) { // un reprocesamiento desde Bronze no es una petición nueva ni consume la cuota de filas
            $this->plans->recordUsage($tenant, 'import_rows', $written['lines_written']);
            $this->plans->recordUsage($tenant, 'api_requests');
        }
        $result['created'] = $written['new'];
        $result['updated'] = $written['updated'];
        $result['unchanged'] = $written['unchanged'];
        $result['stale_skipped'] = $written['stale_skipped'];
        $result['revisions_archived'] = $written['revisions_archived'];
        $result['updated_without_source_order'] = $written['updated_without_source_order'];
        $result['unchanged_mode_mismatch'] = $written['unchanged_mode_mismatch'];
        $this->report($tenant, $bronze, $result, $findings, count($documents), $lines, $lineErrors);

        return $result;
    }

    /**
     * Informe de calidad del lote: hallazgos de resumen (los que no tienen fila) + persistencia por lotes.
     *
     * @param  array<string, mixed>  $result
     */
    private function report(Tenant $tenant, BronzeObject $bronze, array $result, FindingCollector $findings, int $documents, int $lines, int $lineErrors): void
    {
        $summary = $result['stale_skipped'] > 0 ? ['code' => 'stale_document_skipped', 'count' => $result['stale_skipped'], 'message' => "{$result['stale_skipped']} documentos traen una versión ANTERIOR (fecha de registro en la fuente) a la ya guardada y no se aplicaron."] : null;
        if ($summary !== null) {
            $findings->aggregate($summary['code'], 'warning', $summary['count'], $summary['message']);
        }
        if ($result['updated_without_source_order'] > 0) {
            $findings->aggregate('updated_without_source_order', 'info', $result['updated_without_source_order'], "{$result['updated_without_source_order']} documentos se actualizaron sin poder comparar el orden de las versiones (falta la fecha de registro en la fuente): gana la última carga.");
        }
        if ($result['unchanged_mode_mismatch'] > 0) {
            $findings->aggregate('unchanged_mode_mismatch', 'info', $result['unchanged_mode_mismatch'], "{$result['unchanged_mode_mismatch']} documentos no cambiaron y conservan el modo de ingestión con que se cargaron, distinto del declarado ({$result['ingestion_mode']}).");
        }
        try {
            $this->dq->persistBatchReport($tenant->id, $bronze->id, $bronze, [
                // `rows_*` (líneas del lote) con el mismo nombre que el resumen de una importación: el % de filas inválidas de los gates los suma.
                'rows_total' => $lines, 'rows_error' => $lineErrors, 'rows_valid' => max(0, $lines - $lineErrors),
                'documents' => $documents, 'created' => $result['created'], 'updated' => $result['updated'], 'unchanged' => $result['unchanged'],
                'stale_skipped' => $result['stale_skipped'], 'revisions_archived' => $result['revisions_archived'], 'rejected' => $result['rejected'],
                'updated_without_source_order' => $result['updated_without_source_order'], 'unchanged_mode_mismatch' => $result['unchanged_mode_mismatch'],
                'future_recorded_at' => $result['future_recorded_at'], 'recorded_at_unreadable' => $result['recorded_at_unreadable'],
                'document_number_shared_with_unknown_type' => $result['document_number_shared_with_unknown_type'], 'ingestion_mode' => $result['ingestion_mode'],
            ], $findings);
        } catch (Throwable $e) {
            // El informe no debe deshacer lo ya escrito: el lote es idempotente y el original está en Bronze.
            Log::error('No se pudo persistir el informe de calidad del lote', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
        }
    }
}
