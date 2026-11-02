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
    /** Rango de la vida útil declarada (días); 10 años como cota de plausibilidad, a aprobar en el piloto. */
    public const MIN_SHELF_LIFE_DAYS = 1;

    public const MAX_SHELF_LIFE_DAYS = 3650;

    public function run(ImportRunContext $ctx): array
    {
        $tenant = $ctx->tenant;
        $p = $ctx->parser;
        $catalog = new CatalogResolver($tenant, $ctx->options);
        $total = $ctx->countRows();
        $seen = [];
        $valid = [];
        $preview = [];
        // V2.5 · W5-D: cada columna de perecibilidad se escribe SOLO si el archivo la mapea; nunca se deriva ni se pisa la que no viene.
        $mapFlag = ! empty($ctx->mapping['is_perishable']);
        $mapShelf = ! empty($ctx->mapping['shelf_life_days']);

        foreach ($ctx->canonicalRows() as $n => $raw) {
            $issues = [];
            $sku = $p->string($raw['sku'] ?? null, 100);
            $name = $p->string($raw['name'] ?? null, 255);
            if ($sku === null) {
                $issues[] = Issue::error('required', 'Falta el código (SKU).', 'sku');
            }
            // Cambio de código (ADR-V25-24): un SKU que es alias vigente de otro producto actualiza ESE producto; no crea un duplicado.
            if ($sku !== null && ($aliased = $catalog->productIdByAlias($sku)) !== null) {
                $canonical = $catalog->canonicalSku($aliased);
                if ($canonical !== null && CatalogResolver::norm($canonical) !== CatalogResolver::norm($sku)) {
                    $issues[] = Issue::warning('alias_resolved', "El código '{$sku}' es un alias del producto '{$canonical}': se actualiza ese producto en lugar de crear uno nuevo.", 'sku', $sku);
                    $sku = $canonical;
                }
            }
            if ($name === null) {
                $issues[] = Issue::error('required', 'Falta el nombre del producto.', 'name');
            }
            $price = $this->optionalNumber($p, $raw, 'current_price', $issues);
            $cost = $this->optionalNumber($p, $raw, 'current_cost', $issues);
            $priceKnown = $this->knownAt($p, $raw, 'price_known_at', $issues);
            $costKnown = $this->knownAt($p, $raw, 'cost_known_at', $issues);
            [$perishable, $shelfLife] = $this->perishability($p, $raw, $issues, $mapFlag, $mapShelf);
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
                    'is_perishable' => $perishable, 'shelf_life_days' => $shelfLife,
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
            foreach (DB::table('products')->where('tenant_id', $tenant->id)->whereIn('sku', $chunk)->get(['id', 'sku', 'name', 'category_id', 'unit', 'brand', 'current_price', 'current_cost', 'is_active', 'is_perishable', 'shelf_life_days']) as $row) {
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
            } elseif ($e->name !== $row['name'] || (float) $e->current_price !== (float) $row['current_price'] || (float) $e->current_cost !== (float) $row['current_cost'] || $e->unit !== $row['unit'] || $e->brand !== $row['brand'] || (bool) $e->is_active !== $row['is_active'] || ($mapFlag && (bool) $e->is_perishable !== $row['is_perishable']) || ($mapShelf && ($e->shelf_life_days !== null ? (int) $e->shelf_life_days : null) !== $row['shelf_life_days']) || ($row['category'] !== null && $catalog->categoryId($row['category']) !== ($e->category_id !== null ? (int) $e->category_id : null))) {
                $stats['products_updated']++;
                if ($row['is_active'] && ! $e->is_active) {
                    $stats['activations']++;
                }
            } else {
                $stats['products_unchanged']++;
            }
        }

        if ($ctx->commit && $valid !== []) {
            DB::transaction(function () use ($valid, $existing, $catalog, $tenant, $mapFlag, $mapShelf): void {
                $catalog->ensureCategories(array_values(array_filter(array_column($valid, 'category'))));
                $now = now();
                $today = now($tenant->timezone)->toDateString();
                foreach (array_chunk($valid, 1000) as $chunk) {
                    $rows = array_map(fn ($r) => [
                        'tenant_id' => $tenant->id, 'sku' => $r['sku'], 'name' => $r['name'], 'category_id' => $catalog->categoryId($r['category']),
                        'unit' => $r['unit'], 'brand' => $r['brand'], 'current_price' => $r['current_price'], 'current_cost' => $r['current_cost'],
                        'is_active' => $r['is_active'], 'created_at' => $now, 'updated_at' => $now,
                    ] + ($mapFlag ? ['is_perishable' => $r['is_perishable']] : []) + ($mapShelf ? ['shelf_life_days' => $r['shelf_life_days']] : []), $chunk);
                    DB::table('products')->upsert($rows, ['tenant_id', 'sku'], array_merge(
                        ['name', 'category_id', 'unit', 'brand', 'current_price', 'current_cost', 'is_active', 'updated_at'],
                        $mapFlag ? ['is_perishable'] : [],
                        $mapShelf ? ['shelf_life_days'] : [],
                    ));
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

    /**
     * Perecibilidad declarada (V2.5 · W5-D, §10): bandera `is_perishable` y vida útil `shelf_life_days` (1 a 3650 días).
     *
     * - Cada columna se evalúa y escribe SOLO si el archivo la mapea (`$mapFlag`, `$mapShelf`); la que no viene devuelve null y no se toca. Nunca se deriva
     *   una de la otra: un archivo con solo la bandera no borra la vida útil declarada ni uno con solo la vida útil cambia la bandera.
     * - Columna mapeada y celda vacía: bandera = no perecible; vida útil = no declarada (NULL), igual que las demás columnas opcionales.
     * - Valor no reconocido en la bandera, o vida útil no entera / fuera de rango: error (QUARANTINED); no se adivina.
     * - Con AMBAS columnas mapeadas: perecible sin vida útil → advertencia (el plan no proyecta vencimientos sin vida útil ni lotes); vida útil en un
     *   producto no perecible → advertencia (el plan no la usa).
     *
     * @param  list<Issue>  $issues
     * @return array{0: ?bool, 1: ?int} [is_perishable, shelf_life_days]; null = columna no mapeada (no se escribe)
     */
    private function perishability($p, array $raw, array &$issues, bool $mapFlag, bool $mapShelf): array
    {
        $flag = null;
        if ($mapFlag) {
            $flagRaw = $raw['is_perishable'] ?? null;
            $flag = false;
            if ($flagRaw !== null && ! (is_string($flagRaw) && trim($flagRaw) === '')) {
                // `boolean` devuelve el valor por omisión ante un texto que no reconoce: dos valores por omisión distintos delatan un texto desconocido.
                if ($p->boolean($flagRaw, true) !== $p->boolean($flagRaw, false)) {
                    $issues[] = Issue::error('invalid_value', 'Valor no reconocido para «perecible»: use sí o no.', 'is_perishable', $flagRaw);
                } else {
                    $flag = $p->boolean($flagRaw, false);
                }
            }
        }
        $shelf = null;
        $shelfGiven = false;
        if ($mapShelf) {
            $shelfRaw = $raw['shelf_life_days'] ?? null;
            $shelfGiven = $shelfRaw !== null && ! (is_string($shelfRaw) && trim($shelfRaw) === '');
            if ($shelfGiven) {
                $shelf = $p->integer($shelfRaw);
                if ($shelf === null || $shelf < self::MIN_SHELF_LIFE_DAYS || $shelf > self::MAX_SHELF_LIFE_DAYS) {
                    $issues[] = Issue::error('invalid_number', 'La vida útil debe ser un número entero de días entre '.self::MIN_SHELF_LIFE_DAYS.' y '.self::MAX_SHELF_LIFE_DAYS.'.', 'shelf_life_days', $shelfRaw);
                    $shelf = null;
                }
            }
        }
        if ($mapFlag && $mapShelf) {
            if ($flag && ! $shelfGiven) {
                $issues[] = Issue::warning('perishable_without_shelf_life', 'El producto es perecible y no declara vida útil: el plan no proyecta vencimientos sin vida útil ni lotes (no se inventan).', 'shelf_life_days');
            } elseif (! $flag && $shelf !== null) {
                $issues[] = Issue::warning('shelf_life_without_perishable', 'Se declara vida útil en un producto no perecible: el plan no la usa.', 'shelf_life_days', $shelf);
            }
        }

        return [$flag, $shelf];
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
