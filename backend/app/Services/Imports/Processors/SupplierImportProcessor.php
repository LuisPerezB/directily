<?php

declare(strict_types=1);

namespace App\Services\Imports\Processors;

use App\Services\Catalog\CatalogResolver;
use App\Services\Imports\Support\Issue;
use Illuminate\Support\Facades\DB;

/** Proveedores: upsert por (tenant, código). */
final class SupplierImportProcessor implements ImportProcessor
{
    public function run(ImportRunContext $ctx): array
    {
        $tenant = $ctx->tenant;
        $p = $ctx->parser;
        $existing = [];
        foreach (DB::table('suppliers')->where('tenant_id', $tenant->id)->pluck('id', 'code') as $code => $id) {
            $existing[CatalogResolver::norm((string) $code)] = (int) $id;
        }
        $valid = [];
        $seen = [];
        $total = 0;
        foreach ($ctx->canonicalRows() as $n => $raw) {
            $total = $n;
            $issues = [];
            $code = $p->string($raw['code'] ?? null, 50);
            $name = $p->string($raw['name'] ?? null, 200);
            if ($code === null) {
                $issues[] = Issue::error('required', 'Falta el código del proveedor.', 'code');
            } elseif (isset($seen[CatalogResolver::norm($code)])) {
                $issues[] = Issue::error('duplicate_in_file', "El código '{$code}' está repetido.", 'code', $code);
            }
            if ($name === null) {
                $issues[] = Issue::error('required', 'Falta el nombre del proveedor.', 'name');
            }
            $taxId = $p->string($raw['tax_id'] ?? null, 20);
            if ($taxId !== null && preg_match('/^\d{10}(\d{3})?$/', $taxId) !== 1) {
                $issues[] = Issue::warning('invalid_tax_id', 'RUC no válido; se ignora.', 'tax_id', $taxId);
                $taxId = null;
            }
            $days = null;
            if (($raw['default_lead_time_days'] ?? null) !== null && trim((string) $raw['default_lead_time_days']) !== '') {
                $days = $p->integer($raw['default_lead_time_days']);
                if ($days === null || $days < 1 || $days > 365) {
                    $issues[] = Issue::error('invalid_number', 'El plazo debe ser un entero entre 1 y 365.', 'default_lead_time_days', $raw['default_lead_time_days']);
                }
            }
            $ctx->issues->addMany($n, $issues);
            if (! $ctx->issues->rowHasError($n)) {
                $seen[CatalogResolver::norm((string) $code)] = true;
                $country = mb_strtoupper((string) ($p->string($raw['country'] ?? null, 2) ?? 'EC'));
                $valid[] = ['code' => $code, 'name' => $name, 'tax_id' => $taxId, 'country' => strlen($country) === 2 ? $country : 'EC', 'default_lead_time_days' => $days];
            }
        }
        $ctx->issues->flush();
        $new = count(array_filter($valid, fn ($s) => ! isset($existing[CatalogResolver::norm($s['code'])])));
        $stats = ['rows_total' => $total, 'rows_error' => $ctx->issues->rowsWithErrors(), 'rows_warning' => $ctx->issues->rowsWithWarnings(),
            'rows_valid' => count($valid), 'suppliers_new' => $new, 'suppliers_updated' => count($valid) - $new, 'normalized_preview' => array_slice($valid, 0, 20)];

        if ($ctx->commit && $valid !== []) {
            $now = now();
            DB::table('suppliers')->upsert(array_map(fn ($s) => $s + ['tenant_id' => $tenant->id, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now], $valid),
                ['tenant_id', 'code'], ['name', 'tax_id', 'country', 'default_lead_time_days', 'updated_at']);
        }

        return ['stats' => $stats, 'issues' => $ctx->issues->summary()];
    }
}
