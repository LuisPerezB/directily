<?php

declare(strict_types=1);

namespace App\Services\Imports\Processors;

use App\Services\Catalog\CatalogResolver;
use App\Services\Imports\Support\Issue;
use Illuminate\Support\Facades\DB;

/** Existencias: upsert idempotente por (tenant, sucursal, producto, fecha). */
final class InventoryImportProcessor implements ImportProcessor
{
    public function run(ImportRunContext $ctx): array
    {
        $tenant = $ctx->tenant;
        $p = $ctx->parser;
        $catalog = new CatalogResolver($tenant, $ctx->options);
        $total = $ctx->countRows();
        $seen = [];
        $valid = [];
        $preview = [];
        $maxDate = now($tenant->timezone)->addDay()->toDateString();
        $stats = ['rows_total' => $total, 'negative_stock_rows' => 0, 'stockout_rows' => 0, 'date_from' => null, 'date_to' => null];

        foreach ($ctx->canonicalRows() as $n => $raw) {
            $issues = [];
            $date = null;
            if (($parsed = $p->dateTime($raw['snapshot_date'] ?? null)) === null) {
                $issues[] = $raw['snapshot_date'] ?? null ? Issue::error('invalid_date', 'Fecha de corte no válida.', 'snapshot_date', $raw['snapshot_date']) : Issue::error('required', 'Falta la fecha de corte.', 'snapshot_date');
            } else {
                $date = $parsed[0]->toDateString();
                if ($date > $maxDate) {
                    $issues[] = Issue::error('future_date', 'La fecha de corte está en el futuro.', 'snapshot_date', $date);
                }
            }
            [$branchCode, $branchIssues] = $catalog->checkBranch($p->string($raw['branch_code'] ?? null, 50));
            $issues = array_merge($issues, $branchIssues);
            $sku = $p->string($raw['product_sku'] ?? null, 100);
            if ($sku === null) {
                $issues[] = Issue::error('required', 'Falta el código de producto.', 'product_sku');
            } else {
                $issues = array_merge($issues, $catalog->checkProduct($sku));
            }
            $values = [];
            foreach (['on_hand' => true, 'reserved' => false, 'in_transit' => false, 'unit_cost' => false] as $field => $required) {
                $rawValue = $raw[$field] ?? null;
                if ($rawValue === null || (is_string($rawValue) && trim($rawValue) === '')) {
                    if ($required) {
                        $issues[] = Issue::error('required', 'Falta la existencia.', $field);
                    }
                    $values[$field] = $field === 'unit_cost' ? null : 0.0;
                    continue;
                }
                $number = $p->number($rawValue);
                if ($number === null) {
                    $issues[] = Issue::error('invalid_number', 'Valor numérico no válido.', $field, $rawValue);
                } elseif ($number < 0 && $field !== 'on_hand') {
                    $issues[] = Issue::error('invalid_number', 'El valor no puede ser negativo.', $field, $rawValue);
                }
                $values[$field] = $number !== null ? round($number, 4) : null;
            }
            if (($values['on_hand'] ?? 0) < 0) {
                $issues[] = Issue::warning('negative_stock', 'Existencia negativa: se considera quiebre de stock.', 'on_hand', $values['on_hand']);
            }
            if ($date !== null && $branchCode !== null && $sku !== null) {
                $natural = $date.'|'.CatalogResolver::norm($branchCode).'|'.CatalogResolver::norm($sku);
                if (isset($seen[$natural])) {
                    $issues[] = Issue::error('duplicate_in_file', 'Existencia repetida para la misma sucursal, producto y fecha.', 'product_sku', $sku);
                }
            }
            $ctx->issues->addMany($n, $issues);
            if (! $ctx->issues->rowHasError($n)) {
                $seen[$natural] = true;
                $row = ['snapshot_date' => $date, 'branch_code' => $branchCode, 'product_sku' => $sku] + $values;
                $valid[] = $row;
                $stats['negative_stock_rows'] += $values['on_hand'] < 0 ? 1 : 0;
                $stats['stockout_rows'] += $values['on_hand'] <= 0 ? 1 : 0;
                $stats['date_from'] = $stats['date_from'] === null || $date < $stats['date_from'] ? $date : $stats['date_from'];
                $stats['date_to'] = $stats['date_to'] === null || $date > $stats['date_to'] ? $date : $stats['date_to'];
                if (count($preview) < 20) {
                    $preview[] = $row;
                }
            }
            $ctx->progress($n, $total, 5, 60);
        }
        $ctx->issues->flush();

        $stats += ['rows_error' => $ctx->issues->rowsWithErrors(), 'rows_warning' => $ctx->issues->rowsWithWarnings(), 'rows_valid' => count($valid),
            'products_new' => $catalog->productsToCreate(), 'branches_new' => $catalog->branchesToCreate(), 'normalized_preview' => $preview];

        if ($ctx->commit && $valid !== []) {
            $catalog->ensureBranches(array_values(array_unique(array_column($valid, 'branch_code'))));
            $missing = [];
            foreach ($valid as $r) {
                if (! $catalog->hasProduct($r['product_sku'])) {
                    $missing[$r['product_sku']] = ['name' => null, 'category' => null];
                }
            }
            $catalog->ensureProducts($missing);
            $now = now();
            foreach (array_chunk($valid, 1000) as $chunk) {
                DB::transaction(fn () => DB::table('inventory_snapshots')->upsert(array_map(fn ($r) => [
                    'tenant_id' => $tenant->id,
                    'branch_id' => $catalog->branchId($r['branch_code']),
                    'product_id' => $catalog->productId($r['product_sku']),
                    'snapshot_date' => $r['snapshot_date'],
                    'on_hand' => $r['on_hand'], 'reserved' => $r['reserved'], 'in_transit' => $r['in_transit'], 'unit_cost' => $r['unit_cost'],
                    'import_job_id' => $ctx->job->id, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk), ['tenant_id', 'branch_id', 'product_id', 'snapshot_date'], ['on_hand', 'reserved', 'in_transit', 'unit_cost', 'import_job_id', 'updated_at']));
            }
            $stats['rows_written'] = count($valid);
        }

        return ['stats' => $stats, 'issues' => $ctx->issues->summary()];
    }
}
