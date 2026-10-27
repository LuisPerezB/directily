<?php

declare(strict_types=1);

namespace App\Services\Imports\Processors;

use App\Services\Catalog\CatalogResolver;
use App\Services\Imports\Support\Issue;
use Illuminate\Support\Facades\DB;

/** Plazos de abastecimiento: un registro vigente por (producto, proveedor, sucursal). */
final class LeadTimeImportProcessor implements ImportProcessor
{
    public function run(ImportRunContext $ctx): array
    {
        $tenant = $ctx->tenant;
        $p = $ctx->parser;
        $catalog = new CatalogResolver($tenant, array_merge($ctx->options, ['create_missing_products' => false]));
        $suppliers = [];
        foreach (DB::table('suppliers')->where('tenant_id', $tenant->id)->pluck('id', 'code') as $code => $id) {
            $suppliers[CatalogResolver::norm((string) $code)] = (int) $id;
        }
        $total = $ctx->countRows();
        $valid = [];
        $newSuppliers = [];
        $seen = [];

        foreach ($ctx->canonicalRows() as $n => $raw) {
            $issues = [];
            $sku = $p->string($raw['product_sku'] ?? null, 100);
            $supplier = $p->string($raw['supplier_code'] ?? null, 50);
            $branch = $p->string($raw['branch_code'] ?? null, 50);
            if ($sku === null && $supplier === null) {
                $issues[] = Issue::error('required', 'Indique el producto o el proveedor.', 'product_sku');
            }
            if ($sku !== null) {
                $issues = array_merge($issues, $catalog->checkProduct($sku));
            }
            if ($branch !== null) {
                $issues = array_merge($issues, $catalog->checkBranch($branch)[1]);
            }
            if ($supplier !== null && ! isset($suppliers[CatalogResolver::norm($supplier)]) && ! isset($newSuppliers[CatalogResolver::norm($supplier)])) {
                $newSuppliers[CatalogResolver::norm($supplier)] = $supplier;
                $issues[] = Issue::warning('unknown_supplier', "El proveedor '{$supplier}' no existe y se creará.", 'supplier_code', $supplier);
            }
            $days = $p->integer($raw['lead_time_days'] ?? null);
            if ($days === null || $days < 1 || $days > 365) {
                $issues[] = Issue::error('invalid_number', 'El plazo debe ser un entero entre 1 y 365 días.', 'lead_time_days', $raw['lead_time_days'] ?? null);
            }
            $std = $p->number($raw['lead_time_std_days'] ?? null);
            $key = implode('|', [CatalogResolver::norm((string) $sku), CatalogResolver::norm((string) $supplier), CatalogResolver::norm((string) $branch)]);
            if (isset($seen[$key])) {
                $issues[] = Issue::error('duplicate_in_file', 'Plazo repetido para la misma combinación producto/proveedor/sucursal.', 'product_sku', $sku);
            }
            $ctx->issues->addMany($n, $issues);
            if (! $ctx->issues->rowHasError($n)) {
                $seen[$key] = true;
                $valid[] = ['product_sku' => $sku, 'supplier_code' => $supplier, 'branch_code' => $branch, 'lead_time_days' => $days, 'lead_time_std_days' => $std !== null ? round(abs($std), 2) : null];
            }
        }
        $ctx->issues->flush();

        $stats = ['rows_total' => $total, 'rows_error' => $ctx->issues->rowsWithErrors(), 'rows_warning' => $ctx->issues->rowsWithWarnings(),
            'rows_valid' => count($valid), 'suppliers_new' => count($newSuppliers), 'normalized_preview' => array_slice($valid, 0, 20)];

        if ($ctx->commit && $valid !== []) {
            DB::transaction(function () use ($valid, $newSuppliers, &$suppliers, $catalog, $tenant): void {
                $now = now();
                if ($newSuppliers !== []) {
                    DB::table('suppliers')->insertOrIgnore(array_map(fn ($code) => [
                        'tenant_id' => $tenant->id, 'code' => $code, 'name' => 'Proveedor '.$code, 'country' => 'EC', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                    ], array_values($newSuppliers)));
                    foreach (DB::table('suppliers')->where('tenant_id', $tenant->id)->pluck('id', 'code') as $code => $id) {
                        $suppliers[CatalogResolver::norm((string) $code)] = (int) $id;
                    }
                }
                foreach ($valid as $r) {
                    $attributes = [
                        'tenant_id' => $tenant->id,
                        'product_id' => $r['product_sku'] !== null ? $catalog->productId($r['product_sku']) : null,
                        'supplier_id' => $r['supplier_code'] !== null ? ($suppliers[CatalogResolver::norm($r['supplier_code'])] ?? null) : null,
                        'branch_id' => $r['branch_code'] !== null ? $catalog->branchId($r['branch_code']) : null,
                    ];
                    $query = DB::table('supplier_lead_times');
                    foreach ($attributes as $column => $value) {
                        $value === null ? $query->whereNull($column) : $query->where($column, $value);
                    }
                    $query->delete();
                    DB::table('supplier_lead_times')->insert($attributes + [
                        'lead_time_days' => $r['lead_time_days'], 'lead_time_std_days' => $r['lead_time_std_days'],
                        'source' => 'import', 'valid_from' => now($tenant->timezone)->toDateString(), 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            });
        }

        return ['stats' => $stats, 'issues' => $ctx->issues->summary()];
    }
}
