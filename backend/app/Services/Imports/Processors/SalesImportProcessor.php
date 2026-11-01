<?php

declare(strict_types=1);

namespace App\Services\Imports\Processors;

use App\Services\Catalog\CatalogResolver;
use App\Services\DataCore\Quality\BronzeReprocessor;
use App\Services\DataCore\Quality\DataQualityCatalog;
use App\Services\DataCore\Quality\QuarantineService;
use App\Services\DataCore\Quality\SalesFigures;
use App\Services\DataCore\Quality\SalesLoadTally;
use App\Services\Imports\Parsing\ValueParser;
use App\Services\Sales\IngestionModeResolver;
use App\Services\Sales\SalesDocumentAssembler;
use App\Services\Sales\SalesDocumentWriter;
use App\Services\Sales\SalesLineNormalizer;
use Carbon\CarbonImmutable;

/**
 * Importación de ventas línea a línea.
 * Pasada A: ubica la última fila de cada documento (permite archivos no ordenados con
 * memoria acotada). Pasada B: normaliza, valida y ensambla; cada documento completo se
 * clasifica (nuevo/actualizado/sin cambios/versión anterior descartada) y, si commit=true, se escribe por lotes con el
 * modo de ingestión resuelto por ImportService (ADR-V25-23).
 *
 * W2-A (ADR-V25-24): cada documento escrito lleva su linaje (`bronze_object_id`, `source_row` = primera fila del documento en el origen);
 * con `skip_invalid_rows` un documento con hallazgos REVIEW_REQUIRED / QUARANTINED se RETIENE en `quarantine_records` en lugar de
 * descartarse; y la carga lleva las cuentas fuente → Silver de la reconciliación (`SalesLoadTally`). Sin consultas por documento.
 */
final class SalesImportProcessor implements ImportProcessor
{
    public function __construct(
        private readonly SalesDocumentWriter $writer,
        private readonly IngestionModeResolver $modes,
        private readonly QuarantineService $quarantine,
    ) {}

    public function run(ImportRunContext $ctx): array
    {
        $tenant = $ctx->tenant;
        $ingestion = $ctx->ingestion ?? $this->modes->forImport($tenant, $ctx->options['ingestion_mode'] ?? null);
        $normalizer = new SalesLineNormalizer($ctx->parser, $tenant->currency ?: 'USD', $tenant->timezone ?: 'America/Guayaquil');
        $catalog = new CatalogResolver($tenant, $ctx->options);
        $assembler = new SalesDocumentAssembler;
        $batchSize = (int) config('predictily.imports.batch_documents', 1000);

        // Pasada A. Además de la última fila de cada documento, anota los números que traen filas de tipo NO reconocido: un documento
        // reconocido con ese mismo número se escribe igual, pero con un aviso (ver SalesLineNormalizer::sharedNumberWarning).
        $lastRow = [];
        $unknownTypeNumbers = [];
        $sharedNumberWarned = [];
        $rowsTotal = 0;
        $tally = new SalesLoadTally;
        foreach ($ctx->canonicalRows(false) as $n => $raw) {
            $rowsTotal = $n;
            if (($key = $normalizer->documentKey($raw)) !== null) {
                if (! isset($lastRow[$key])) {
                    // Capa FUENTE de la reconciliación: tipo y estado LEÍDOS del origen, sin validar.
                    $tally->sourceDocument(
                        ValueParser::documentType($ctx->parser->string($raw['document_type'] ?? null, 50)) ?? 'other',
                        ValueParser::documentStatus($ctx->parser->string($raw['status'] ?? null, 50)) ?? 'unknown',
                    );
                }
                $lastRow[$key] = $n;
                if (SalesLineNormalizer::isUnknownTypeKey($key)) {
                    $unknownTypeNumbers[SalesLineNormalizer::numberOfKey($key)] = true;
                }
            }
        }

        $stats = [
            'rows_total' => $rowsTotal, 'rows_valid' => 0, 'rows_error' => 0, 'rows_warning' => 0, 'duplicates_in_file' => 0,
            'documents_total' => count($lastRow), 'documents_valid' => 0, 'documents_invalid' => 0,
            'documents_new' => 0, 'documents_updated' => 0, 'documents_unchanged' => 0,
            'stale_skipped' => 0, 'revisions_archived' => 0, 'updated_without_source_order' => 0, 'unchanged_mode_mismatch' => 0,
            'future_recorded_at' => 0, 'recorded_at_unreadable' => 0, 'document_number_shared_with_unknown_type' => 0,
            'ingestion_mode' => $ingestion->mode, 'ingestion_mode_basis' => $ingestion->basis,
            'voided_documents' => 0, 'credit_notes' => 0, 'return_lines' => 0, 'lines_valid' => 0,
            'date_from' => null, 'date_to' => null, 'missing_days' => 0,
            'products_new' => 0, 'branches_new' => 0, 'products_created' => 0, 'branches_created' => 0,
            'units_total' => 0.0, 'net_amount_total' => 0.0, 'lines_written' => 0,
            'documents_quarantined' => 0,
        ];
        $saleDates = [];
        $batch = [];
        $normalizedPreview = [];
        $previewLimit = (int) config('predictily.imports.preview_rows', 20);

        $flush = function () use (&$batch, &$stats, $ctx, $tenant, $catalog, $ingestion, $tally): void {
            if ($batch === []) {
                return;
            }
            $states = [];
            if ($ctx->commit) {
                $result = $this->writer->write($tenant, $batch, $catalog, [
                    'import_job_id' => $ctx->job->id, 'data_source_id' => $ctx->job->data_source_id, 'ingestion_mode' => $ingestion->mode,
                    'bronze_object_id' => $ctx->bronze?->id,
                ]);
                $states = $result['states'];
                // Los documentos que acaban de quedar bien en Silver superan a sus registros PENDIENTES de cuarentena (liberarlos sobrescribiría
                // una versión más nueva): una sentencia por lote.
                $this->quarantine->supersedePending(
                    $tenant->id,
                    array_keys(array_filter($states, fn (string $state) => in_array($state, [SalesDocumentWriter::STATE_NEW, SalesDocumentWriter::STATE_UPDATED, SalesDocumentWriter::STATE_UNCHANGED], true))),
                    'later_load',
                );
                $stats['documents_new'] += $result['new'];
                $stats['documents_updated'] += $result['updated'];
                $stats['documents_unchanged'] += $result['unchanged'];
                $stats['stale_skipped'] += $result['stale_skipped'];
                $stats['revisions_archived'] += $result['revisions_archived'];
                $stats['updated_without_source_order'] += $result['updated_without_source_order'];
                $stats['unchanged_mode_mismatch'] += $result['unchanged_mode_mismatch'];
                $stats['products_created'] += $result['products_created'];
                $stats['branches_created'] += $result['branches_created'];
                $stats['lines_written'] += $result['lines_written'];
            } else {
                // Validación: proyección de lo que hará la confirmación (cada documento actualizado archiva una versión).
                $classified = $this->writer->classify($tenant, $batch, $ingestion->mode);
                foreach ($classified as $docKey => $class) {
                    $states[$docKey] = $class['state'];
                    $stats[match ($class['state']) {
                        SalesDocumentWriter::STATE_NEW => 'documents_new',
                        SalesDocumentWriter::STATE_UPDATED => 'documents_updated',
                        SalesDocumentWriter::STATE_UNCHANGED => 'documents_unchanged',
                        SalesDocumentWriter::STATE_STALE_SKIPPED => 'stale_skipped',
                    }]++;
                    if ($class['state'] === SalesDocumentWriter::STATE_UPDATED) {
                        $stats['revisions_archived']++;
                    }
                    if ($class['without_order']) {
                        $stats['updated_without_source_order']++;
                    }
                    if ($class['mode_mismatch']) {
                        $stats['unchanged_mode_mismatch']++;
                    }
                }
                // Reprocesamiento desde Bronze (ADR-V25-24): documentos que se actualizarían sin orden de fuente aunque ya se escribieron DESPUÉS del original.
                if (isset($ctx->options['reprocess_of_bronze_object_id']) && $ctx->bronze !== null) {
                    $stats['updated_newer_than_original'] = ($stats['updated_newer_than_original'] ?? 0) + BronzeReprocessor::newerThanOriginal($classified, $ctx->bronze);
                }
            }
            // Destino de cada documento para la reconciliación (Silver o categoría explicada).
            foreach ($batch as $doc) {
                $tally->documentState($states[$doc['doc_key']], $doc['status'], $doc['document_type'], $doc['_fig']);
            }
            $batch = [];
        };

        $acceptDoc = function (array $doc) use (&$batch, &$stats, &$saleDates, $batchSize, $flush): void {
            $stats['documents_valid']++;
            if ($doc['status'] === 'voided') {
                $stats['voided_documents']++;
            }
            if ($doc['document_type'] === 'credit_note') {
                $stats['credit_notes']++;
            }
            if ($doc['status'] === 'completed' && $doc['document_type'] !== 'credit_note') {
                $saleDates[$doc['sale_date']] = true;
            }
            $batch[] = $doc;
            if (count($batch) >= $batchSize) {
                $flush();
            }
        };

        // Pasada B. Cuentas por documento ABIERTO (se liberan al cerrarlo): cifras del origen de todas sus filas, de las repetidas y de las de
        // cantidad cero, y los números de fila (para el linaje y la cuarentena). Nada de esto consulta la base.
        $open = [];
        $docRows = [];
        $bad = [];
        $quarantineGroups = [];
        $skipInvalid = $ctx->skipInvalidRows();
        $quarantinable = fn (array $classes): bool => $classes !== [] && array_diff(array_keys($classes), DataQualityCatalog::QUARANTINE_CLASSES) === [];
        $closeDropped = function (?string $key, array $acc, array $bad, array $rows) use ($tally, $skipInvalid, $quarantinable, &$quarantineGroups, &$stats): void {
            if ($skipInvalid && $quarantinable($bad['classes'])) {
                $quarantineGroups[$key ?? 'row:'.($rows[0] ?? 0)] = ['rows' => $rows, 'codes' => array_keys($bad['codes']), 'numbered' => $key !== null];
                $stats['documents_quarantined']++;
                $category = 'quarantine';
            } else {
                $category = 'rejected_rows';
            }
            if ($key === null) {
                $tally->unnumberedRow($category, $acc['u'], $acc['n']);
            } else {
                $tally->droppedDocument($category, $acc['r'], $acc['u'], $acc['n']);
            }
        };

        foreach ($ctx->canonicalRows() as $n => $raw) {
            $key = $normalizer->documentKey($raw);
            [$line, $issues] = $normalizer->normalize($raw);
            $skipped = false;

            if ($line !== null) {
                [$branchCode, $branchIssues] = $catalog->checkBranch($line['branch_code']);
                $issues = array_merge($issues, $branchIssues, $branchCode !== null ? $catalog->checkProduct($line['product_sku'], 'product_sku', $line['sale_date']) : []);
                $line['branch_code'] = $branchCode;
                $line['source_row'] = $n; // linaje: la fila del origen (el documento conserva la de su primera línea)
                $hasError = count(array_filter($issues, fn ($i) => $i->isError())) > 0;
                if (! $hasError) {
                    $assemblerIssues = $assembler->add($line);
                    $skipped = count(array_filter($assemblerIssues, fn ($i) => $i->code === 'duplicate_in_file' && ! $i->isError())) > 0;
                    $issues = array_merge($issues, $assemblerIssues);
                }
            }
            foreach ($issues as $issue) {
                if (($anomaly = SalesLineNormalizer::recordedAtAnomaly($issue)) !== null) {
                    $stats['future_recorded_at']++; // futuras e ilegibles (como en la sincronización); la ilegible, además, aparte
                    $stats['recorded_at_unreadable'] += $anomaly === 'unreadable' ? 1 : 0;
                }
            }
            // El documento reconocido que comparte número con filas de tipo desconocido SE ESCRIBE (su numeración puede ser por tipo o
            // serie); solo se avisa, una vez por documento, para que el caso quede visible en los errores, el reporte y las estadísticas.
            if ($key !== null && ! isset($sharedNumberWarned[$key]) && ! SalesLineNormalizer::isUnknownTypeKey($key)
                && isset($unknownTypeNumbers[SalesLineNormalizer::numberOfKey($key)])) {
                $sharedNumberWarned[$key] = true;
                $stats['document_number_shared_with_unknown_type']++;
                $issues[] = SalesLineNormalizer::sharedNumberWarning(SalesLineNormalizer::numberOfKey($key));
            }
            $ctx->issues->addMany($n, $issues, $key);

            $rowError = $ctx->issues->rowHasError($n);
            if ($rowError && $key !== null) {
                $assembler->markInvalid($key);
            }

            // Cuentas de la reconciliación: la fila se cuenta SIEMPRE en la fuente (también si es inválida); su destino se decide al cerrar el documento.
            $figures = $line !== null ? SalesFigures::fromLine($line) : SalesFigures::fromRaw($raw, $ctx->parser);
            $tally->sourceRow($figures['units'], $figures['net'], $figures['amount_known']);
            $zero = false;
            foreach ($issues as $issue) {
                if ($issue->code === 'zero_quantity' && ! $issue->isError()) {
                    $zero = true;
                }
            }
            if ($key !== null) {
                $acc = &$open[$key];
                $acc ??= ['r' => 0, 'u' => 0.0, 'n' => 0.0, 'dr' => 0, 'du' => 0.0, 'dn' => 0.0, 'zr' => 0, 'zn' => 0.0];
                $acc['r']++;
                $acc['u'] += $figures['units'];
                $acc['n'] += $figures['net'];
                if ($skipped) {
                    $acc['dr']++;
                    $acc['du'] += $figures['units'];
                    $acc['dn'] += $figures['net'];
                } elseif ($zero) {
                    $acc['zr']++;
                    $acc['zn'] += $figures['net'];
                }
                unset($acc);
                $docRows[$key][] = $n;
                if ($rowError) {
                    foreach ($issues as $issue) {
                        if ($issue->isError()) {
                            $bad[$key]['classes'][DataQualityCatalog::classFor($issue->code, 'error')] = true;
                            $bad[$key]['codes'][$issue->code] = true;
                        }
                    }
                }
            } elseif ($rowError) {
                $badRow = ['classes' => [], 'codes' => []];
                foreach ($issues as $issue) {
                    if ($issue->isError()) {
                        $badRow['classes'][DataQualityCatalog::classFor($issue->code, 'error')] = true;
                        $badRow['codes'][$issue->code] = true;
                    }
                }
                $closeDropped(null, ['r' => 1, 'u' => $figures['units'], 'n' => $figures['net']], $badRow, [$n]);
            }

            if ($line !== null && ! $rowError && ! $skipped) {
                $stats['lines_valid']++;
                if ($line['is_return']) {
                    $stats['return_lines']++;
                }
                $stats['units_total'] += $line['is_return'] ? 0 : $line['quantity'];
                $stats['net_amount_total'] += $line['is_return'] ? -$line['net_amount'] : $line['net_amount'];
                $stats['date_from'] = $stats['date_from'] === null || $line['sale_date'] < $stats['date_from'] ? $line['sale_date'] : $stats['date_from'];
                $stats['date_to'] = $stats['date_to'] === null || $line['sale_date'] > $stats['date_to'] ? $line['sale_date'] : $stats['date_to'];
                if (count($normalizedPreview) < $previewLimit) {
                    $normalizedPreview[] = array_intersect_key($line, array_flip([
                        'document_type', 'document_number', 'status', 'sale_date', 'branch_code', 'product_sku', 'product_name',
                        'quantity', 'unit_price', 'discount', 'net_amount', 'tax', 'unit_cost', 'is_return', 'channel',
                    ]));
                }
            }

            if ($key !== null && ($lastRow[$key] ?? null) === $n) {
                $acc = $open[$key];
                $rows = $docRows[$key];
                $badDoc = $bad[$key] ?? ['classes' => [], 'codes' => []];
                unset($open[$key], $docRows[$key], $bad[$key]);
                $doc = $assembler->complete($key);
                if ($doc !== null) {
                    $doc['source_row'] = $rows[0]; // linaje: la PRIMERA fila del documento en el origen (también si fue una línea de cantidad cero o repetida)
                    $doc['_fig'] = SalesFigures::ofDocument($doc);
                    $tally->duplicateRows($acc['dr'], $acc['du'], $acc['dn']);
                    $tally->zeroQuantityRows($acc['zr'], $acc['zn']);
                    $acceptDoc($doc);
                } elseif ($badDoc['classes'] !== []) {
                    $closeDropped($key, $acc, $badDoc, $rows);
                } else {
                    // Documento válido sin líneas (todas de cantidad cero o repetidas).
                    $tally->duplicateRows($acc['dr'], $acc['du'], $acc['dn']);
                    $tally->zeroQuantityRows($acc['zr'], $acc['zn']);
                    $tally->emptyDocument();
                }
            }
            $ctx->progress($n, $rowsTotal, 5, $ctx->commit ? 98 : 95);
        }
        foreach ($assembler->drain() as $doc) {
            $doc['_fig'] = SalesFigures::ofDocument($doc);
            $acceptDoc($doc);
        }
        $flush();
        $ctx->issues->flush();

        // Cuarentena: con `skip_invalid_rows`, los documentos con hallazgos de revisión se RETIENEN (no se descartan). Solo al confirmar.
        if ($ctx->commit && $quarantineGroups !== []) {
            $this->quarantine->retain($ctx, $quarantineGroups);
        }

        $stats['documents_invalid'] = max(0, $stats['documents_total'] - $stats['documents_valid']);
        $stats['rows_error'] = $ctx->issues->rowsWithErrors();
        $stats['rows_warning'] = $ctx->issues->rowsWithWarnings();
        $stats['rows_valid'] = max(0, $rowsTotal - $stats['rows_error']);
        $stats['duplicates_in_file'] = $ctx->issues->count('duplicate_in_file');
        $stats['products_new'] = $catalog->productsToCreate();
        $stats['branches_new'] = $catalog->branchesToCreate();
        $stats['units_total'] = round($stats['units_total'], 4);
        $stats['net_amount_total'] = round($stats['net_amount_total'], 2);
        $stats['normalized_preview'] = $normalizedPreview;

        $issues = $ctx->issues->summary();
        if ($stats['documents_quarantined'] > 0) {
            $issues[] = ['code' => 'documents_quarantined', 'severity' => 'warning', 'count' => $stats['documents_quarantined'],
                'message' => "{$stats['documents_quarantined']} documentos con hallazgos de revisión ".($ctx->commit ? 'quedaron en cuarentena' : 'quedarán en cuarentena')
                    .' (no se descartan): se liberan tras agregar el mapeo de valores o el dato que falta, o se descartan con un motivo.'];
        }
        if ($stats['date_from'] !== null) {
            $span = CarbonImmutable::parse($stats['date_from'])->diffInDays(CarbonImmutable::parse($stats['date_to'])) + 1;
            $stats['days_with_sales'] = count($saleDates);
            $stats['missing_days'] = max(0, (int) $span - count($saleDates));
            if ($stats['missing_days'] > 0) {
                $issues[] = ['code' => 'missing_dates', 'severity' => 'warning', 'count' => $stats['missing_days'],
                    'message' => "Hay {$stats['missing_days']} días sin ventas registradas entre {$stats['date_from']} y {$stats['date_to']} (feriados, cierres o datos faltantes)."];
            }
        }
        if ($stats['voided_documents'] > 0) {
            $issues[] = ['code' => 'voided_document', 'severity' => 'info', 'count' => $stats['voided_documents'],
                'message' => "{$stats['voided_documents']} documentos anulados: se guardan para trazabilidad y se excluyen de la demanda."];
        }
        if ($stats['documents_updated'] > 0) {
            $issues[] = ['code' => 'existing_document_changed', 'severity' => 'warning', 'count' => $stats['documents_updated'],
                'message' => "{$stats['documents_updated']} documentos ya existían con contenido distinto y se actualizarán."];
        }
        if ($stats['stale_skipped'] > 0) {
            $issues[] = ['code' => 'stale_document_skipped', 'severity' => 'warning', 'count' => $stats['stale_skipped'],
                'message' => "{$stats['stale_skipped']} documentos traen una versión ANTERIOR (fecha de registro en la fuente) a la ya guardada y no se aplicarán."];
        }
        if ($stats['unchanged_mode_mismatch'] > 0) {
            $issues[] = ['code' => 'unchanged_mode_mismatch', 'severity' => 'info', 'count' => $stats['unchanged_mode_mismatch'],
                'message' => "{$stats['unchanged_mode_mismatch']} documentos no cambiaron (mismo contenido) y conservan el modo de ingestión con que se cargaron, distinto del declarado en esta carga ({$stats['ingestion_mode']}): declarar otro modo no reescribe un documento sin cambios."];
        }
        if ($stats['updated_without_source_order'] > 0) {
            $issues[] = ['code' => 'updated_without_source_order', 'severity' => 'info', 'count' => $stats['updated_without_source_order'],
                'message' => "{$stats['updated_without_source_order']} documentos se actualizarán sin poder comparar el orden de las versiones (falta la fecha de registro en la fuente): gana la última carga."];
        }

        return ['stats' => $stats, 'issues' => $issues, 'tally' => $tally, 'rows_read' => $rowsTotal];
    }
}
