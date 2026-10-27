<?php

declare(strict_types=1);

namespace App\Services\Imports\Processors;

use App\Services\Catalog\CatalogResolver;
use App\Services\Sales\SalesDocumentAssembler;
use App\Services\Sales\SalesDocumentWriter;
use App\Services\Sales\SalesLineNormalizer;
use Carbon\CarbonImmutable;

/**
 * Importación de ventas línea a línea.
 * Pasada A: ubica la última fila de cada documento (permite archivos no ordenados con
 * memoria acotada). Pasada B: normaliza, valida y ensambla; cada documento completo se
 * clasifica (nuevo/actualizado/sin cambios) y, si commit=true, se escribe por lotes.
 */
final class SalesImportProcessor implements ImportProcessor
{
    public function __construct(private readonly SalesDocumentWriter $writer) {}

    public function run(ImportRunContext $ctx): array
    {
        $tenant = $ctx->tenant;
        $normalizer = new SalesLineNormalizer($ctx->parser, $tenant->currency ?: 'USD', $tenant->timezone ?: 'America/Guayaquil');
        $catalog = new CatalogResolver($tenant, $ctx->options);
        $assembler = new SalesDocumentAssembler;
        $batchSize = (int) config('predictily.imports.batch_documents', 1000);

        // Pasada A
        $lastRow = [];
        $rowsTotal = 0;
        foreach ($ctx->canonicalRows() as $n => $raw) {
            $rowsTotal = $n;
            if (($key = $normalizer->documentKey($raw)) !== null) {
                $lastRow[$key] = $n;
            }
        }

        $stats = [
            'rows_total' => $rowsTotal, 'rows_valid' => 0, 'rows_error' => 0, 'rows_warning' => 0, 'duplicates_in_file' => 0,
            'documents_total' => count($lastRow), 'documents_valid' => 0, 'documents_invalid' => 0,
            'documents_new' => 0, 'documents_updated' => 0, 'documents_unchanged' => 0,
            'voided_documents' => 0, 'credit_notes' => 0, 'return_lines' => 0, 'lines_valid' => 0,
            'date_from' => null, 'date_to' => null, 'missing_days' => 0,
            'products_new' => 0, 'branches_new' => 0, 'products_created' => 0, 'branches_created' => 0,
            'units_total' => 0.0, 'net_amount_total' => 0.0, 'lines_written' => 0,
        ];
        $saleDates = [];
        $batch = [];
        $normalizedPreview = [];
        $previewLimit = (int) config('predictily.imports.preview_rows', 20);

        $flush = function () use (&$batch, &$stats, $ctx, $tenant, $catalog): void {
            if ($batch === []) {
                return;
            }
            if ($ctx->commit) {
                $result = $this->writer->write($tenant, $batch, $catalog, ['import_job_id' => $ctx->job->id, 'data_source_id' => $ctx->job->data_source_id]);
                $stats['documents_new'] += $result['new'];
                $stats['documents_updated'] += $result['updated'];
                $stats['documents_unchanged'] += $result['unchanged'];
                $stats['products_created'] += $result['products_created'];
                $stats['branches_created'] += $result['branches_created'];
                $stats['lines_written'] += $result['lines_written'];
            } else {
                foreach ($this->writer->classify($tenant, $batch) as $state) {
                    $stats['documents_'.$state['state']]++;
                }
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

        // Pasada B
        foreach ($ctx->canonicalRows() as $n => $raw) {
            $key = $normalizer->documentKey($raw);
            [$line, $issues] = $normalizer->normalize($raw);
            $skipped = false;

            if ($line !== null) {
                [$branchCode, $branchIssues] = $catalog->checkBranch($line['branch_code']);
                $issues = array_merge($issues, $branchIssues, $branchCode !== null ? $catalog->checkProduct($line['product_sku']) : []);
                $line['branch_code'] = $branchCode;
                $hasError = count(array_filter($issues, fn ($i) => $i->isError())) > 0;
                if (! $hasError) {
                    $assemblerIssues = $assembler->add($line);
                    $skipped = count(array_filter($assemblerIssues, fn ($i) => $i->code === 'duplicate_in_file' && ! $i->isError())) > 0;
                    $issues = array_merge($issues, $assemblerIssues);
                }
            }
            $ctx->issues->addMany($n, $issues);

            $rowError = $ctx->issues->rowHasError($n);
            if ($rowError && $key !== null) {
                $assembler->markInvalid($key);
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
                if (($doc = $assembler->complete($key)) !== null) {
                    $acceptDoc($doc);
                }
            }
            $ctx->progress($n, $rowsTotal, 5, $ctx->commit ? 98 : 95);
        }
        foreach ($assembler->drain() as $doc) {
            $acceptDoc($doc);
        }
        $flush();
        $ctx->issues->flush();

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

        return ['stats' => $stats, 'issues' => $issues];
    }
}
