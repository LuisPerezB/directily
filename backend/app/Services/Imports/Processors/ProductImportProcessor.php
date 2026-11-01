<?php

declare(strict_types=1);

namespace App\Services\Imports\Processors;

use App\Services\Catalog\CatalogResolver;
use App\Services\Imports\Support\Issue;
use Illuminate\Support\Facades\DB;

/**
 * Productos: upsert por (tenant, sku). Si cambia el precio o el costo vigente se agrega
 * una fila a product_price_history / purchase_cost_history (no se reescriben ventas pasadas).
 */
final class ProductImportProcessor implements ImportProcessor
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

        foreach ($ctx->canonicalRows() as $n => $raw) {
            $issues = [];
            $sku = $p->string($raw['sku'] ?? null, 100);
            $name = $p->string($raw['name'] ?? null, 255);
            if ($sku === null) {
                $issues[] = Issue::error('required', 'Falta el código (SKU).', 'sku');
            }
            if ($name === null) {
                $issues[] = Issue::error('required', 'Falta el nombre del producto.', 'name');
            }
            $price = $this->optionalNumber($p, $raw, 'current_price', $issues);
            $cost = $this->optionalNumber($p, $raw, 'current_cost', $issues);
            $priceKnown = $this->knownAt($p, $raw, 'price_known_at', $issues);
            $costKnown = $this->knownAt($p, $raw, 'cost_known_at', $issues);
            if ($sku !== null && isset($seen[CatalogResolver::norm($sku)])) {
                $issues[] = Issue::error('duplicate_in_file', "El SKU '{$sku}' aparece más de una vez en el archivo.", 'sku', $sku);
            }
            $ctx->issues->addMany($n, $issues);
            if (! $ctx->issues->rowHasError($n)) {
                $seen[CatalogResolver::norm((string) $sku)] = true;
                $row = [
                    'sku' => $sku, 'name' => $name,
                    'category' => $p->string($raw['category'] ?? null, 150),
                    'unit' => $p->string($raw['unit'] ?? null, 20) ?? 'unidad',
                    'brand' => $p->string($raw['brand'] ?? null, 100),
                    'current_price' => $price, 'current_cost' => $cost,
                    'price_known_at' => $priceKnown, 'cost_known_at' => $costKnown,
                    'is_active' => $p->boolean($raw['is_active'] ?? null, true),
                ];
                $valid[] = $row;
                if (count($preview) < 20) {
                    $preview[] = $row;
                }
            }
            $ctx->progress($n, $total, 5, 60);
        }
        $ctx->issues->flush();

        $existing = [];
        foreach (array_chunk(array_column($valid, 'sku'), 1000) as $chunk) {
            foreach (DB::table('products')->where('tenant_id', $tenant->id)->whereIn('sku', $chunk)->get(['id', 'sku', 'name', 'category_id', 'unit', 'brand', 'current_price', 'current_cost', 'is_active']) as $row) {
                $existing[CatalogResolver::norm($row->sku)] = $row;
            }
        }
        $stats = ['rows_total' => $total, 'rows_error' => $ctx->issues->rowsWithErrors(), 'rows_warning' => $ctx->issues->rowsWithWarnings(),
            'rows_valid' => count($valid), 'products_new' => 0, 'products_updated' => 0, 'products_unchanged' => 0,
            'activations' => 0, 'normalized_preview' => $preview];

        foreach ($valid as $row) {
            $e = $existing[CatalogResolver::norm($row['sku'])] ?? null;
            if ($e === null) {
                $stats['products_new']++;
                if ($row['is_active']) {
                    $stats['activations']++;
                }
            } elseif ($e->name !== $row['name'] || (float) $e->current_price !== (float) $row['current_price'] || (float) $e->current_cost !== (float) $row['current_cost'] || $e->unit !== $row['unit'] || $e->brand !== $row['brand'] || (bool) $e->is_active !== $row['is_active'] || ($row['category'] !== null && $catalog->categoryId($row['category']) !== ($e->category_id !== null ? (int) $e->category_id : null))) {
                $stats['products_updated']++;
                if ($row['is_active'] && ! $e->is_active) {
                    $stats['activations']++;
                }
            } else {
                $stats['products_unchanged']++;
            }
        }

        if ($ctx->commit && $valid !== []) {
            DB::transaction(function () use ($valid, $existing, $catalog, $tenant): void {
                $catalog->ensureCategories(array_values(array_filter(array_column($valid, 'category'))));
                $now = now();
                $today = now($tenant->timezone)->toDateString();
                foreach (array_chunk($valid, 1000) as $chunk) {
                    $rows = array_map(fn ($r) => [
                        'tenant_id' => $tenant->id, 'sku' => $r['sku'], 'name' => $r['name'], 'category_id' => $catalog->categoryId($r['category']),
                        'unit' => $r['unit'], 'brand' => $r['brand'], 'current_price' => $r['current_price'], 'current_cost' => $r['current_cost'],
                        'is_active' => $r['is_active'], 'created_at' => $now, 'updated_at' => $now,
                    ], $chunk);
                    DB::table('products')->upsert($rows, ['tenant_id', 'sku'], ['name', 'category_id', 'unit', 'brand', 'current_price', 'current_cost', 'is_active', 'updated_at']);
                }
                $ids = [];
                foreach (array_chunk(array_column($valid, 'sku'), 1000) as $chunk) {
                    $ids += DB::table('products')->where('tenant_id', $tenant->id)->whereIn('sku', $chunk)->pluck('id', 'sku')->all();
                }
                $prices = [];
                $costs = [];
                foreach ($valid as $r) {
                    $e = $existing[CatalogResolver::norm($r['sku'])] ?? null;
                    $id = $ids[$r['sku']] ?? null;
                    if ($id === null) {
                        continue;
                    }
                    if ($r['current_price'] !== null && ($e === null || (float) $e->current_price !== (float) $r['current_price'])) {
                        $prices[] = ['tenant_id' => $tenant->id, 'product_id' => $id, 'price' => $r['current_price'], 'currency' => $tenant->currency, 'valid_from' => $today, 'source' => 'import', 'known_at' => $r['price_known_at'], 'created_at' => $now];
                    }
                    if ($r['current_cost'] !== null && ($e === null || (float) $e->current_cost !== (float) $r['current_cost'])) {
                        $costs[] = ['tenant_id' => $tenant->id, 'product_id' => $id, 'unit_cost' => $r['current_cost'], 'currency' => $tenant->currency, 'valid_from' => $today, 'source' => 'import', 'known_at' => $r['cost_known_at'], 'unit' => $r['unit'], 'created_at' => $now];
                    }
                }
                foreach (array_chunk($prices, 1000) as $chunk) {
                    DB::table('product_price_history')->insert($chunk);
                }
                foreach (array_chunk($costs, 1000) as $chunk) {
                    DB::table('purchase_cost_history')->insert($chunk);
                }
            });
        }

        return ['stats' => $stats, 'issues' => $ctx->issues->summary()];
    }

    /**
     * Disponibilidad en la fuente de un precio/costo (tres fechas): NULL = desconocida; nunca la fecha de la carga.
     *
     * @param  list<Issue>  $issues
     */
    private function knownAt($p, array $raw, string $field, array &$issues): ?string
    {
        $value = $raw[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $parsed = $p->dateTime($value);
        if ($parsed === null || $parsed[0]->isFuture()) {
            $issues[] = Issue::error($parsed === null ? 'invalid_date' : 'future_date', $parsed === null ? 'Fecha no válida.' : 'La fecha desde la que se conocía el dato no puede ser futura.', $field, $value);

            return null;
        }

        return $parsed[0]->utc()->format('Y-m-d H:i:sP');
    }

    /** @param list<Issue> $issues */
    private function optionalNumber($p, array $raw, string $field, array &$issues): ?float
    {
        $value = $raw[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $number = $p->number($value);
        if ($number === null || $number < 0) {
            $issues[] = Issue::error('invalid_number', 'Valor numérico no válido.', $field, $value);

            return null;
        }

        return round($number, 4);
    }
}
