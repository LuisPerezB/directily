<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Tenant;
use App\Services\Catalog\CatalogResolver;
use Illuminate\Support\Facades\DB;

/**
 * Escritura idempotente de documentos de venta (importador de archivos y POST /sales/batch).
 *
 * - Clave natural: (tenant_id, document_type, document_number).
 * - Documento nuevo → INSERT. Existente con distinto source_hash → UPDATE de cabecera y
 *   reemplazo completo de sus líneas. Mismo hash → sin cambios (no se escribe).
 * - Todo por lotes, dentro de una transacción por lote.
 */
final class SalesDocumentWriter
{
    /**
     * Clasifica documentos contra la base de datos.
     *
     * @param  list<array<string, mixed>>  $documents
     * @return array<string, array{state: string, id: ?int}> doc_key => estado (new|updated|unchanged)
     */
    public function classify(Tenant $tenant, array $documents): array
    {
        $byType = [];
        foreach ($documents as $doc) {
            $byType[$doc['document_type']][] = $doc['document_number'];
        }
        $existing = [];
        foreach ($byType as $type => $numbers) {
            foreach (array_chunk(array_values(array_unique($numbers)), 1000) as $chunk) {
                $rows = DB::table('sales')
                    ->where('tenant_id', $tenant->id)
                    ->where('document_type', $type)
                    ->whereIn('document_number', $chunk)
                    ->get(['id', 'document_number', 'source_hash']);
                foreach ($rows as $row) {
                    $existing[$type.'|'.mb_strtoupper($row->document_number)] = ['id' => (int) $row->id, 'hash' => $row->source_hash];
                }
            }
        }

        $result = [];
        foreach ($documents as $doc) {
            $found = $existing[$doc['doc_key']] ?? null;
            $result[$doc['doc_key']] = [
                'state' => $found === null ? 'new' : ($found['hash'] === $doc['source_hash'] ? 'unchanged' : 'updated'),
                'id' => $found['id'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Escribe un lote de documentos ya validados.
     *
     * @param  list<array<string, mixed>>  $documents
     * @param  array{import_job_id?: ?int, data_source_id?: ?int}  $context
     * @return array{new: int, updated: int, unchanged: int, products_created: int, branches_created: int, lines_written: int}
     */
    public function write(Tenant $tenant, array $documents, CatalogResolver $catalog, array $context = []): array
    {
        $counts = ['new' => 0, 'updated' => 0, 'unchanged' => 0, 'products_created' => 0, 'branches_created' => 0, 'lines_written' => 0];
        if ($documents === []) {
            return $counts;
        }

        return DB::transaction(function () use ($tenant, $documents, $catalog, $context, $counts): array {
            // Serializa escrituras de ventas por empresa (importaciones/API concurrentes).
            DB::statement('SELECT pg_advisory_xact_lock(?, ?)', [7421, $tenant->id]);
            $states = $this->classify($tenant, $documents);
            $toWrite = [];
            foreach ($documents as $doc) {
                $state = $states[$doc['doc_key']]['state'];
                $counts[$state]++;
                if ($state !== 'unchanged') {
                    $toWrite[] = $doc;
                }
            }
            if ($toWrite === []) {
                return $counts;
            }

            // 1) Catálogos
            $counts['branches_created'] = $catalog->ensureBranches(array_values(array_unique(array_map(fn ($d) => $d['branch_code'], $toWrite))));
            $products = [];
            $customers = [];
            foreach ($toWrite as $doc) {
                foreach ($doc['lines'] as $line) {
                    $products[$line['product_sku']] ??= ['name' => $line['product_name'], 'category' => $line['category']];
                }
                if ($doc['customer_code'] !== null) {
                    $customers[$doc['customer_code']] ??= ['segment' => $doc['customer_segment'], 'province' => $doc['customer_province']];
                }
            }
            $counts['products_created'] = $catalog->ensureProducts($products);
            $customerIds = $catalog->ensureCustomers($customers);

            // 2) Cabeceras (upsert con RETURNING para obtener ids)
            $now = now()->format('Y-m-d H:i:sP');
            $columns = ['tenant_id', 'branch_id', 'data_source_id', 'import_job_id', 'document_type', 'document_number', 'status', 'issued_at',
                'sale_date', 'customer_id', 'channel', 'currency', 'subtotal', 'discount_total', 'tax_total', 'total', 'source_hash', 'source_recorded_at', 'created_at', 'updated_at'];
            $ids = [];
            foreach (array_chunk($toWrite, 500) as $chunk) {
                $bindings = [];
                $placeholders = [];
                foreach ($chunk as $doc) {
                    $branchId = $catalog->branchId($doc['branch_code']);
                    array_push($bindings,
                        $tenant->id, $branchId, $context['data_source_id'] ?? null, $context['import_job_id'] ?? null,
                        $doc['document_type'], $doc['document_number'], $doc['status'], $doc['issued_at'], $doc['sale_date'],
                        $doc['customer_code'] !== null ? ($customerIds[$doc['customer_code']] ?? null) : null,
                        $doc['channel'], $doc['currency'], $doc['subtotal'], $doc['discount_total'], $doc['tax_total'], $doc['total'],
                        $doc['source_hash'], $doc['source_recorded_at'] ?? null, $now, $now,
                    );
                    $placeholders[] = '('.implode(',', array_fill(0, count($columns), '?')).')';
                }
                $update = implode(', ', array_map(fn ($c) => "{$c} = EXCLUDED.{$c}", [
                    'branch_id', 'import_job_id', 'status', 'issued_at', 'sale_date', 'customer_id', 'channel', 'currency',
                    'subtotal', 'discount_total', 'tax_total', 'total', 'source_hash', 'source_recorded_at', 'updated_at',
                ]));
                $sql = 'INSERT INTO sales ('.implode(',', $columns).') VALUES '.implode(',', $placeholders)
                    .' ON CONFLICT (tenant_id, document_type, document_number) DO UPDATE SET '.$update
                    .', data_source_id = COALESCE(EXCLUDED.data_source_id, sales.data_source_id)'
                    .' RETURNING id, document_type, document_number';
                foreach (DB::select($sql, $bindings) as $row) {
                    $ids[$row->document_type.'|'.mb_strtoupper($row->document_number)] = (int) $row->id;
                }
            }

            // 3) Líneas: se reemplazan por completo en documentos actualizados.
            $updatedIds = [];
            foreach ($toWrite as $doc) {
                if ($states[$doc['doc_key']]['state'] === 'updated') {
                    $updatedIds[] = $ids[$doc['doc_key']];
                }
            }
            foreach (array_chunk($updatedIds, 1000) as $chunk) {
                DB::table('sale_items')->where('tenant_id', $tenant->id)->whereIn('sale_id', $chunk)->delete();
            }

            $items = [];
            foreach ($toWrite as $doc) {
                $saleId = $ids[$doc['doc_key']];
                $branchId = $catalog->branchId($doc['branch_code']);
                foreach ($doc['lines'] as $line) {
                    $items[] = [
                        'tenant_id' => $tenant->id,
                        'sale_id' => $saleId,
                        'line_number' => $line['line_number'],
                        'product_id' => $catalog->productId($line['product_sku']),
                        'branch_id' => $branchId,
                        'sale_date' => $doc['sale_date'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'discount_amount' => $line['discount'],
                        'net_amount' => $line['net_amount'],
                        'tax_amount' => $line['tax'],
                        'unit_cost' => $line['unit_cost'],
                        'is_return' => $line['is_return'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    if (count($items) >= 2000) {
                        DB::table('sale_items')->insert($items);
                        $counts['lines_written'] += count($items);
                        $items = [];
                    }
                }
            }
            if ($items !== []) {
                DB::table('sale_items')->insert($items);
                $counts['lines_written'] += count($items);
            }

            return $counts;
        });
    }
}
