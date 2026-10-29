<?php

declare(strict_types=1);

namespace App\Services\Imports\Processors;

use App\Services\Catalog\CatalogResolver;
use App\Services\Imports\Parsing\ValueParser;
use App\Services\Imports\Support\Issue;
use Illuminate\Support\Facades\DB;

/** Almacenes por sucursal. Upsert por (empresa, código). La capacidad exige su unidad física; vacía = desconocida. */
final class WarehouseImportProcessor implements ImportProcessor
{
    public function run(ImportRunContext $ctx): array
    {
        $tenant = $ctx->tenant;
        $p = $ctx->parser;
        $catalog = new CatalogResolver($tenant, array_merge($ctx->options, ['create_missing_branches' => false]));
        $total = $ctx->countRows();
        $seen = [];
        $valid = [];
        foreach ($ctx->canonicalRows() as $n => $raw) {
            $issues = [];
            $code = $p->string($raw['code'] ?? null, 50);
            $name = $p->string($raw['name'] ?? null, 150);
            if ($code === null) {
                $issues[] = Issue::error('required', 'Falta el código del almacén.', 'code');
            } elseif (isset($seen[CatalogResolver::norm($code)])) {
                $issues[] = Issue::error('duplicate_in_file', "El almacén '{$code}' aparece más de una vez.", 'code', $code);
            }
            if ($name === null) {
                $issues[] = Issue::error('required', 'Falta el nombre del almacén.', 'name');
            }
            $branchCode = $p->string($raw['branch_code'] ?? null, 50);
            $branchId = null;
            if ($branchCode === null) {
                $issues[] = Issue::error('required', 'Falta la sucursal del almacén.', 'branch_code');
            } elseif (($branchId = $catalog->branchId($branchCode)) === null) {
                $issues[] = Issue::error('unknown_branch', "La sucursal '{$branchCode}' no existe en la empresa.", 'branch_code', $branchCode);
            }
            $capacity = null;
            if (! ValueParser::isBlank($raw['storage_capacity'] ?? null)) {
                $capacity = $p->number($raw['storage_capacity']);
                if ($capacity === null || $capacity < 0) {
                    $issues[] = Issue::error('invalid_number', 'La capacidad debe ser un número no negativo.', 'storage_capacity', $raw['storage_capacity']);
                    $capacity = null;
                }
            }
            $unit = $p->string($raw['capacity_unit'] ?? null, 20);
            if ($capacity !== null && $unit === null) {
                $issues[] = Issue::error('capacity_unit_required', 'La capacidad requiere su unidad física (m3, pallets, kg...).', 'capacity_unit');
            }
            $ctx->issues->addMany($n, $issues);
            if (! $ctx->issues->rowHasError($n)) {
                $seen[CatalogResolver::norm((string) $code)] = true;
                $valid[] = ['code' => $code, 'name' => $name, 'branch_id' => $branchId, 'storage_capacity' => $capacity !== null ? round($capacity, 4) : null,
                    'capacity_unit' => $capacity !== null ? $unit : $unit, 'is_active' => $p->boolean($raw['is_active'] ?? null, true)];
            }
            $ctx->progress($n, $total, 5, 60);
        }
        $ctx->issues->flush();

        $stats = ['rows_total' => $total, 'rows_error' => $ctx->issues->rowsWithErrors(), 'rows_warning' => $ctx->issues->rowsWithWarnings(), 'rows_valid' => count($valid),
            'warehouses_new' => 0, 'warehouses_updated' => 0, 'warehouses_unchanged' => 0, 'products_new' => 0, 'branches_new' => 0, 'normalized_preview' => array_slice($valid, 0, 20)];
        $existing = [];
        foreach (DB::table('warehouses')->where('tenant_id', $tenant->id)->get() as $row) {
            $existing[CatalogResolver::norm($row->code)] = $row;
        }
        foreach ($valid as $item) {
            $e = $existing[CatalogResolver::norm($item['code'])] ?? null;
            if ($e === null) {
                $stats['warehouses_new']++;
            } elseif ($e->name !== $item['name'] || (int) $e->branch_id !== $item['branch_id'] || (bool) $e->is_active !== $item['is_active'] || $e->capacity_unit !== $item['capacity_unit']
                || ($e->storage_capacity === null) !== ($item['storage_capacity'] === null) || ($item['storage_capacity'] !== null && abs((float) $e->storage_capacity - $item['storage_capacity']) > 1e-9)) {
                $stats['warehouses_updated']++;
            } else {
                $stats['warehouses_unchanged']++;
            }
        }
        if ($ctx->commit && $valid !== []) {
            $now = now();
            DB::transaction(fn () => DB::table('warehouses')->upsert(array_map(fn (array $i) => $i + ['tenant_id' => $tenant->id, 'created_at' => $now, 'updated_at' => $now], $valid),
                ['tenant_id', 'code'], ['name', 'branch_id', 'storage_capacity', 'capacity_unit', 'is_active', 'updated_at']));
        }

        return ['stats' => $stats, 'issues' => $ctx->issues->summary()];
    }
}
