<?php

declare(strict_types=1);

namespace App\Services\Imports\Processors;

use App\Models\Promotion;
use App\Services\Catalog\CatalogResolver;
use App\Services\Imports\Parsing\ValueParser;
use App\Services\Imports\Support\Issue;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Promociones (covariables para el motor). discount_pct se guarda como fracción (0.15 = 15 %);
 * valores > 1 se interpretan como porcentaje. Idempotente por (nombre, desde, hasta, sucursal,
 * producto, categoría): una fila existente se actualiza en lugar de borrarse y recrearse, de
 * modo que conserva su `known_at` y su historial (`promotion_revisions`, vía PromotionObserver).
 *
 * `known_at` (opcional): cuándo el negocio aprobó/registró la promoción. No puede ser futura;
 * si falta, una promoción nueva toma el momento de la importación y una existente conserva el suyo.
 */
final class PromotionImportProcessor implements ImportProcessor
{
    public function run(ImportRunContext $ctx): array
    {
        $tenant = $ctx->tenant;
        $p = $ctx->parser;
        $timezone = $tenant->timezone ?: 'America/Guayaquil';
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
            $knownAt = null;
            $rawKnown = $raw['known_at'] ?? null;
            if (! ValueParser::isBlank($rawKnown)) {
                $parsed = $p->dateTime($rawKnown)[0] ?? null;
                if ($parsed === null) {
                    $issues[] = Issue::error('invalid_date', 'Fecha de registro de la promoción no válida.', 'known_at', $rawKnown);
                } elseif ($parsed->isFuture()) {
                    $issues[] = Issue::error('future_date', 'La fecha de registro de la promoción no puede ser futura.', 'known_at', $rawKnown);
                } else {
                    $knownAt = Promotion::parseKnownAt($parsed, $timezone);
                }
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
                    'category_id' => $catalog->categoryId($category), 'known_at' => $knownAt,
                ];
            }
        }
        $ctx->issues->flush();

        $stats = ['rows_total' => $total, 'rows_error' => $ctx->issues->rowsWithErrors(), 'rows_warning' => $ctx->issues->rowsWithWarnings(),
            'rows_valid' => count($valid), 'promotions_new' => 0, 'promotions_updated' => 0, 'promotions_unchanged' => 0,
            'normalized_preview' => array_map(fn (array $r) => ['known_at' => $r['known_at']?->toIso8601String()] + $r, array_slice($valid, 0, 20))];
        $existing = [];
        foreach (DB::table('promotions')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->orderBy('id')
            ->get(['name', 'promo_type', 'discount_pct', 'starts_on', 'ends_on', 'branch_id', 'product_id', 'category_id', 'known_at']) as $row) {
            $existing[self::key((array) $row)] ??= $row;
        }
        foreach ($valid as $promo) {
            $current = $existing[self::key($promo)] ?? null;
            $stat = match (true) {
                $current === null => 'promotions_new',
                self::changed($current, $promo) => 'promotions_updated',
                default => 'promotions_unchanged',
            };
            $stats[$stat]++;
            $existing[self::key($promo)] = (object) $promo;
        }

        if ($ctx->commit && $valid !== []) {
            app(TenantContext::class)->runAs($tenant, fn () => DB::transaction(function () use ($valid): void {
                /** @var array<string, list<Promotion>> $byKey */
                $byKey = [];
                foreach (Promotion::query()->orderBy('id')->get() as $promotion) {
                    $byKey[self::key(self::modelKeyAttributes($promotion))][] = $promotion;
                }
                foreach ($valid as $promo) {
                    $key = self::key($promo);
                    $matches = $byKey[$key] ?? [];
                    $promotion = array_shift($matches) ?? new Promotion;
                    $promotion->revisionSource = 'import';
                    $promotion->fill(Arr::except($promo, ['known_at']));
                    if ($promo['known_at'] !== null) {
                        $promotion->setAttribute('known_at', $promo['known_at']);
                    }
                    $promotion->save();
                    // Duplicados previos con la misma clave: baja lógica (queda en el historial).
                    foreach ($matches as $duplicate) {
                        $duplicate->revisionSource = 'import';
                        $duplicate->delete();
                    }
                    $byKey[$key] = [$promotion];
                }
            }));
        }

        return ['stats' => $stats, 'issues' => $ctx->issues->summary()];
    }

    /** @param array<string, mixed> $row */
    private static function key(array $row): string
    {
        return implode('|', [$row['name'], substr((string) $row['starts_on'], 0, 10), substr((string) $row['ends_on'], 0, 10),
            $row['branch_id'] ?? '', $row['product_id'] ?? '', $row['category_id'] ?? '']);
    }

    /** @return array<string, mixed> */
    private static function modelKeyAttributes(Promotion $promotion): array
    {
        return ['name' => $promotion->name, 'starts_on' => $promotion->starts_on?->toDateString(), 'ends_on' => $promotion->ends_on?->toDateString(),
            'branch_id' => $promotion->branch_id, 'product_id' => $promotion->product_id, 'category_id' => $promotion->category_id];
    }

    /** @param array<string, mixed> $promo */
    private static function changed(object $current, array $promo): bool
    {
        $pct = $current->discount_pct !== null ? round((float) $current->discount_pct, 3) : null;
        if ($pct !== $promo['discount_pct'] || $current->promo_type !== $promo['promo_type']) {
            return true;
        }
        if ($promo['known_at'] === null || $current->known_at === null) {
            return false;
        }
        $known = $current->known_at instanceof CarbonImmutable ? $current->known_at : CarbonImmutable::parse((string) $current->known_at);

        return $known->getTimestamp() !== $promo['known_at']->getTimestamp();
    }
}
