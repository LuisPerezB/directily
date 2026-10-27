<?php

declare(strict_types=1);

namespace App\Services\Imports\Processors;

use App\Services\Catalog\CatalogResolver;
use App\Services\Imports\Support\Issue;
use Illuminate\Support\Facades\DB;

/**
 * Promociones (covariables conocidas a futuro para el motor). discount_pct se guarda como
 * fracción (0.15 = 15 %); valores > 1 se interpretan como porcentaje. Idempotente por
 * (nombre, desde, hasta, sucursal, producto, categoría).
 */
final class PromotionImportProcessor implements ImportProcessor
{
    public function run(ImportRunContext $ctx): array
    {
        $tenant = $ctx->tenant;
        $p = $ctx->parser;
        $catalog = new CatalogResolver($tenant, array_merge($ctx->options, ['create_missing_products' => false, 'create_missing_branches' => false]));
        $valid = [];
        $total = 0;
        foreach ($ctx->canonicalRows() as $n => $raw) {
            $total = $n;
            $issues = [];
            $name = $p->string($raw['name'] ?? null, 150);
            if ($name === null) {
                $issues[] = Issue::error('required', 'Falta el nombre de la promoción.', 'name');
            }
            $starts = $p->dateTime($raw['starts_on'] ?? null)[0] ?? null;
            $ends = $p->dateTime($raw['ends_on'] ?? null)[0] ?? null;
            if ($starts === null) {
                $issues[] = Issue::error('invalid_date', 'Fecha de inicio no válida.', 'starts_on', $raw['starts_on'] ?? null);
            }
            if ($ends === null) {
                $issues[] = Issue::error('invalid_date', 'Fecha de fin no válida.', 'ends_on', $raw['ends_on'] ?? null);
            }
            if ($starts !== null && $ends !== null && $ends->lt($starts)) {
                $issues[] = Issue::error('invalid_date', 'La fecha de fin es anterior a la de inicio.', 'ends_on', $raw['ends_on']);
            }
            $pct = null;
            $rawPct = $raw['discount_pct'] ?? null;
            if ($rawPct !== null && trim((string) $rawPct) !== '') {
                $pct = $p->percentage($rawPct) ?? $p->number($rawPct);
                if ($pct !== null && $pct > 1) {
                    $pct /= 100;
                }
                if ($pct === null || $pct < 0 || $pct > 1) {
                    $issues[] = Issue::error('invalid_number', 'Descuento no válido.', 'discount_pct', $rawPct);
                }
            }
            $branch = $p->string($raw['branch_code'] ?? null, 50);
            if ($branch !== null) {
                $issues = array_merge($issues, $catalog->checkBranch($branch)[1]);
            }
            $sku = $p->string($raw['product_sku'] ?? null, 100);
            if ($sku !== null) {
                $issues = array_merge($issues, $catalog->checkProduct($sku));
            }
            $category = $p->string($raw['category'] ?? null, 150);
            if ($category !== null && $catalog->categoryId($category) === null) {
                $issues[] = Issue::error('unknown_category', "La categoría '{$category}' no existe.", 'category', $category);
            }
            $type = $p->string($raw['promo_type'] ?? null, 20) ?? 'percentage';
            $ctx->issues->addMany($n, $issues);
            if (! $ctx->issues->rowHasError($n)) {
                $valid[] = [
                    'name' => $name, 'promo_type' => in_array($type, ['percentage', 'fixed_amount', 'bundle', 'other'], true) ? $type : 'other',
                    'discount_pct' => $pct !== null ? round($pct, 3) : null, 'starts_on' => $starts->toDateString(), 'ends_on' => $ends->toDateString(),
                    'branch_id' => $branch !== null ? $catalog->branchId($branch) : null, 'product_id' => $sku !== null ? $catalog->productId($sku) : null,
                    'category_id' => $catalog->categoryId($category),
                ];
            }
        }
        $ctx->issues->flush();
        $stats = ['rows_total' => $total, 'rows_error' => $ctx->issues->rowsWithErrors(), 'rows_warning' => $ctx->issues->rowsWithWarnings(),
            'rows_valid' => count($valid), 'normalized_preview' => array_slice($valid, 0, 20)];

        if ($ctx->commit && $valid !== []) {
            DB::transaction(function () use ($valid, $tenant): void {
                $now = now();
                foreach ($valid as $promo) {
                    $q = DB::table('promotions')->where('tenant_id', $tenant->id)->where('name', $promo['name'])
                        ->where('starts_on', $promo['starts_on'])->where('ends_on', $promo['ends_on']);
                    foreach (['branch_id', 'product_id', 'category_id'] as $col) {
                        $promo[$col] === null ? $q->whereNull($col) : $q->where($col, $promo[$col]);
                    }
                    $q->delete();
                    DB::table('promotions')->insert($promo + ['tenant_id' => $tenant->id, 'created_at' => $now, 'updated_at' => $now]);
                }
            });
        }

        return ['stats' => $stats, 'issues' => $ctx->issues->summary()];
    }
}
