<?php

declare(strict_types=1);

namespace App\Services\Imports\Processors;

use App\Services\Catalog\CatalogResolver;
use App\Services\Imports\Support\Issue;
use Illuminate\Support\Facades\DB;

/** Sucursales: upsert por (tenant, código). */
final class BranchImportProcessor implements ImportProcessor
{
    public function run(ImportRunContext $ctx): array
    {
        $tenant = $ctx->tenant;
        $p = $ctx->parser;
        $existing = [];
        foreach (DB::table('branches')->where('tenant_id', $tenant->id)->pluck('id', 'code') as $code => $id) {
            $existing[CatalogResolver::norm((string) $code)] = (int) $id;
        }
        $valid = [];
        $seen = [];
        $total = 0;
        foreach ($ctx->canonicalRows() as $n => $raw) {
            $total = $n;
            $issues = [];
            $code = $p->string($raw['code'] ?? null, 50);
            $name = $p->string($raw['name'] ?? null, 150);
            if ($code === null) {
                $issues[] = Issue::error('required', 'Falta el código de la sucursal.', 'code');
            } elseif (isset($seen[CatalogResolver::norm($code)])) {
                $issues[] = Issue::error('duplicate_in_file', "El código '{$code}' está repetido.", 'code', $code);
            }
            if ($name === null) {
                $issues[] = Issue::error('required', 'Falta el nombre de la sucursal.', 'name');
            }
            $ctx->issues->addMany($n, $issues);
            if (! $ctx->issues->rowHasError($n)) {
                $seen[CatalogResolver::norm((string) $code)] = true;
                $valid[] = [
                    'code' => $code, 'name' => $name,
                    'province' => $p->string($raw['province'] ?? null, 80), 'canton' => $p->string($raw['canton'] ?? null, 80),
                    'commercial_zone' => $p->string($raw['commercial_zone'] ?? null, 80), 'address' => $p->string($raw['address'] ?? null, 255),
                ];
            }
        }
        $ctx->issues->flush();
        $new = count(array_filter($valid, fn ($b) => ! isset($existing[CatalogResolver::norm($b['code'])])));
        $stats = ['rows_total' => $total, 'rows_error' => $ctx->issues->rowsWithErrors(), 'rows_warning' => $ctx->issues->rowsWithWarnings(),
            'rows_valid' => count($valid), 'branches_new' => $new, 'branches_updated' => count($valid) - $new, 'normalized_preview' => array_slice($valid, 0, 20)];

        if ($ctx->commit && $valid !== []) {
            $now = now();
            DB::table('branches')->upsert(array_map(fn ($b) => $b + ['tenant_id' => $tenant->id, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now], $valid),
                ['tenant_id', 'code'], ['name', 'province', 'canton', 'commercial_zone', 'address', 'updated_at']);
        }

        return ['stats' => $stats, 'issues' => $ctx->issues->summary()];
    }
}
