<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Tenant;
use App\Services\Catalog\CatalogResolver;
use App\Services\Imports\Parsing\ValueParser;
use App\Services\Plans\PlanLimitService;

/**
 * Ingesta de ventas por API REST (conector). Reutiliza EXACTAMENTE la normalización,
 * ensamblado y escritura del importador de archivos: mismo resultado para el mismo dato.
 */
final class SalesBatchService
{
    public function __construct(private readonly SalesDocumentWriter $writer, private readonly PlanLimitService $plans) {}

    /**
     * @param  list<array<string, mixed>>  $documents
     * @param  array<string, mixed>  $options
     * @return array{created: int, updated: int, unchanged: int, rejected: int, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    public function ingest(Tenant $tenant, array $documents, ?int $dataSourceId = null, array $options = []): array
    {
        $options = array_merge(['create_missing_products' => true, 'create_missing_branches' => false, 'create_missing_categories' => true], $options);
        $parser = new ValueParser($tenant->timezone ?: 'America/Guayaquil', $options);
        $normalizer = new SalesLineNormalizer($parser, $tenant->currency ?: 'USD', $tenant->timezone ?: 'America/Guayaquil');
        $catalog = new CatalogResolver($tenant, $options);
        $errors = [];
        $warnings = [];
        $valid = [];
        $seen = [];
        $lines = 0;

        foreach ($documents as $index => $document) {
            $assembler = new SalesDocumentAssembler;
            $docErrors = [];
            $header = array_intersect_key($document, array_flip([
                'document_number', 'document_type', 'status', 'issued_at', 'branch_code', 'currency', 'channel',
                'customer_code', 'customer_segment', 'customer_province',
            ]));
            foreach (($document['items'] ?? []) as $itemIndex => $item) {
                $raw = $header + array_intersect_key($item, array_flip([
                    'product_sku', 'product_name', 'category', 'quantity', 'unit_price', 'discount', 'tax', 'net_amount', 'unit_cost', 'line_number',
                ]));
                [$line, $issues] = $normalizer->normalize($raw);
                if ($line !== null) {
                    [$branchCode, $branchIssues] = $catalog->checkBranch($line['branch_code']);
                    $issues = array_merge($issues, $branchIssues, $branchCode !== null ? $catalog->checkProduct($line['product_sku']) : []);
                    $line['branch_code'] = $branchCode;
                    if (count(array_filter($issues, fn ($i) => $i->isError())) === 0) {
                        $issues = array_merge($issues, $assembler->add($line));
                    }
                }
                foreach ($issues as $issue) {
                    $entry = ['index' => $index, 'item' => $itemIndex] + $issue->toArray();
                    if ($issue->isError()) {
                        $docErrors[] = $entry;
                    } else {
                        $warnings[] = $entry;
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
        }

        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => count($documents) - count($valid), 'errors' => $errors, 'warnings' => array_slice($warnings, 0, 200)];
        if ($valid === []) {
            return $result;
        }
        if ($catalog->productsToCreate() > 0) {
            $this->plans->assertCan($tenant, 'max_active_products', $catalog->productsToCreate());
        }
        $this->plans->assertCan($tenant, 'max_import_rows_per_month', $lines);

        $written = $this->writer->write($tenant, $valid, $catalog, ['data_source_id' => $dataSourceId]);
        $this->plans->recordUsage($tenant, 'import_rows', $written['lines_written']);
        $this->plans->recordUsage($tenant, 'api_requests');
        $result['created'] = $written['new'];
        $result['updated'] = $written['updated'];
        $result['unchanged'] = $written['unchanged'];

        return $result;
    }
}
