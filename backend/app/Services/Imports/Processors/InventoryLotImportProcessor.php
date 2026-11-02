<?php

declare(strict_types=1);

namespace App\Services\Imports\Processors;

use App\Services\Catalog\CatalogResolver;
use App\Services\Imports\Support\Issue;
use Illuminate\Support\Facades\DB;

/**
 * Lotes de inventario (V2.5 · W5-D, §10): upsert idempotente por (tenant, sucursal, producto, lote, fecha de corte).
 *
 * Alimenta la perecibilidad y la salida FEFO (primero vence, primero sale) del motor de Supply. Cada fila inválida se REPORTA con su
 * código y su clase de calidad (`import_errors` y `data_quality_issues`): nunca se descarta en silencio y nunca se corrige a ciegas.
 *
 * - Cantidad negativa (`negative_lot_quantity`) o no numérica: QUARANTINED; la fila no se escribe (un lote negativo no existe y
 *   corregir el signo cambiaría el stock).
 * - Vencimiento no interpretable (`invalid_date`) o implausible (`expiry_implausible`: más de {@see self::MAX_EXPIRY_YEARS} años
 *   después del corte): QUARANTINED; la fila no se escribe porque un vencimiento adivinado movería pérdidas y compras.
 * - Lote ya vencido al corte (`lot_expired_on_snapshot`), sin vencimiento en un producto perecible (`lot_expiry_missing`), vencimiento
 *   posterior a la vida útil declarada (`expiry_exceeds_shelf_life`) y lotes que suman más que la existencia del snapshot
 *   (`lots_exceed_on_hand`): WARNING; la fila se escribe y el motor decide con la regla documentada en docs/supply/SUPPLY_CORE.md.
 *
 * La existencia del snapshot (`inventory_snapshots.on_hand`) sigue siendo la verdad de la cantidad física; los lotes aportan el
 * VENCIMIENTO. La conciliación entre ambos se hace en el motor (los lotes se limitan a la existencia) y aquí solo se avisa.
 *
 * Semántica de REEMPLAZO por foto: un archivo reemplaza la foto de cada (sucursal, producto, fecha de corte) que trae. Un lote de esa foto que un archivo
 * corregido ya no trae se ELIMINA (`lots_replaced`, avisado en la validación con su conteo); un lote cuya fila del archivo fue rechazada NO se elimina (una
 * fila inválida nunca borra un lote bueno); las fotos de otras fechas y los productos que el archivo no trae no se tocan.
 * El código de lote se guarda en MAYÚSCULAS (`l-001` y `L-001` son el mismo lote) y la cantidad debe caber en la columna (< 10^12).
 */
final class InventoryLotImportProcessor implements ImportProcessor
{
    /** Años máximos entre el corte y el vencimiento (un lote que vence más allá es casi seguro un error de captura; a aprobar en el piloto). */
    public const MAX_EXPIRY_YEARS = 15;

    /** Cantidad máxima de un lote (exclusiva): la columna es decimal(16,4), es decir, 12 dígitos enteros; un valor mayor sería un fallo del job al confirmar. */
    public const MAX_QUANTITY = 1_000_000_000_000.0;

    /** Tolerancia al comparar la suma de lotes con la existencia del snapshot (unidades). */
    private const ON_HAND_TOLERANCE = 0.0001;

    public function run(ImportRunContext $ctx): array
    {
        $tenant = $ctx->tenant;
        $p = $ctx->parser;
        $catalog = new CatalogResolver($tenant, $ctx->options);
        $total = $ctx->countRows();
        $seen = [];
        $present = [];
        $valid = [];
        $preview = [];
        $maxDate = now($tenant->timezone)->addDay()->toDateString();
        $perishable = $this->perishabilityBySku($tenant->id);
        $stats = [
            'rows_total' => $total, 'lots_expired_on_snapshot' => 0, 'lots_without_expiry' => 0, 'lots_with_expiry' => 0,
            'date_from' => null, 'date_to' => null,
        ];

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
            $lot = $p->string($raw['lot_code'] ?? null, 60);
            $lot = $lot !== null ? mb_strtoupper($lot) : null;       // «l-001» y «L-001» son el mismo lote
            if ($lot === null) {
                $issues[] = Issue::error('required', 'Falta el código de lote.', 'lot_code');
            }

            $quantity = null;
            $rawQuantity = $raw['quantity'] ?? null;
            if ($rawQuantity === null || (is_string($rawQuantity) && trim($rawQuantity) === '')) {
                $issues[] = Issue::error('required', 'Falta la cantidad del lote.', 'quantity');
            } else {
                $quantity = $p->number($rawQuantity);
                if ($quantity === null) {
                    $issues[] = Issue::error('invalid_number', 'Cantidad no válida.', 'quantity', $rawQuantity);
                } elseif ($quantity < 0) {
                    $issues[] = Issue::error('negative_lot_quantity', 'La cantidad de un lote no puede ser negativa: la fila no se importa.', 'quantity', $rawQuantity);
                    $quantity = null;
                } elseif ($quantity >= self::MAX_QUANTITY) {
                    $issues[] = Issue::error('invalid_number', 'La cantidad excede el máximo admitido (menos de 1.000.000.000.000): la fila no se importa.', 'quantity', $rawQuantity);
                    $quantity = null;
                } else {
                    $quantity = round($quantity, 4);
                }
            }

            $expiry = null;
            $rawExpiry = $raw['expiry_date'] ?? null;
            $info = $sku !== null ? ($perishable[CatalogResolver::norm($sku)] ?? null) : null;
            if ($rawExpiry === null || (is_string($rawExpiry) && trim($rawExpiry) === '')) {
                if ($info !== null && $info['is_perishable']) {
                    $issues[] = Issue::warning('lot_expiry_missing', 'El producto es perecible y el lote no declara vencimiento: el motor lo trata como existencia sin vencimiento (no se inventa).', 'expiry_date');
                }
            } elseif (($parsedExpiry = $p->dateTime($rawExpiry)) === null) {
                $issues[] = Issue::error('invalid_date', 'Fecha de vencimiento no válida.', 'expiry_date', $rawExpiry);
            } else {
                $expiry = $parsedExpiry[0]->toDateString();
                if ($date !== null) {
                    if ($expiry > date('Y-m-d', strtotime($date.' +'.self::MAX_EXPIRY_YEARS.' years'))) {
                        $issues[] = Issue::error('expiry_implausible', 'El vencimiento está a más de '.self::MAX_EXPIRY_YEARS.' años del corte: se presume un error de captura y la fila no se importa.', 'expiry_date', $expiry);
                        $expiry = null;
                    } elseif ($expiry <= $date) {
                        $issues[] = Issue::warning('lot_expired_on_snapshot', 'El lote ya estaba vencido en la fecha de corte: no es vendible y el motor lo excluye de la existencia utilizable.', 'expiry_date', $expiry);
                    } elseif ($info !== null && $info['shelf_life_days'] !== null
                        && $expiry > date('Y-m-d', strtotime($date.' +'.$info['shelf_life_days'].' days'))) {
                        $issues[] = Issue::warning('expiry_exceeds_shelf_life', 'El vencimiento excede la vida útil declarada del producto: revise la vida útil o la fecha.', 'expiry_date', $expiry);
                    }
                }
            }

            $natural = null;
            if ($date !== null && $branchCode !== null && $sku !== null && $lot !== null) {
                $natural = $date.'|'.CatalogResolver::norm($branchCode).'|'.CatalogResolver::norm($sku).'|'.CatalogResolver::norm($lot);
                if (isset($seen[$natural])) {
                    $issues[] = Issue::error('duplicate_in_file', 'Lote repetido para la misma sucursal, producto y fecha.', 'lot_code', $lot);
                }
            }
            if ($date !== null && $branchCode !== null && $sku !== null && $lot !== null) {
                // El lote ESTÁ en el archivo (aunque su fila se rechace): un reemplazo no debe borrar un lote cuya fila corregida aún no llegó bien.
                $tripleKey = $date.'|'.CatalogResolver::norm($branchCode).'|'.CatalogResolver::norm($sku);
                $present[$tripleKey] ??= ['date' => $date, 'branch' => $branchCode, 'sku' => $sku, 'codes' => []];
                $present[$tripleKey]['codes'][CatalogResolver::norm($lot)] = true;
            }
            $ctx->issues->addMany($n, $issues, $sku !== null && $lot !== null ? $sku.'|'.$lot : null);
            if (! $ctx->issues->rowHasError($n)) {
                $seen[$natural] = true;
                $row = ['snapshot_date' => $date, 'branch_code' => $branchCode, 'product_sku' => $sku, 'lot_code' => $lot,
                    'quantity' => $quantity, 'expiry_date' => $expiry, 'source_row' => $n];
                $valid[] = $row;
                $stats['date_from'] = $stats['date_from'] === null || $date < $stats['date_from'] ? $date : $stats['date_from'];
                $stats['date_to'] = $stats['date_to'] === null || $date > $stats['date_to'] ? $date : $stats['date_to'];
                if ($expiry === null) {
                    $stats['lots_without_expiry']++;
                } else {
                    $stats['lots_with_expiry']++;
                    $stats['lots_expired_on_snapshot'] += $expiry <= $date ? 1 : 0;
                }
                if (count($preview) < 20) {
                    $preview[] = array_diff_key($row, ['source_row' => true]);
                }
            }
            $ctx->progress($n, $total, 5, 60);
        }
        $ctx->issues->flush();

        $exceeding = $this->lotsExceedingOnHand($tenant->id, $valid, $catalog);
        if ($exceeding > 0) {
            $ctx->findings->aggregate('lots_exceed_on_hand', 'warning', $exceeding,
                'La suma de lotes supera la existencia del snapshot del mismo día: el motor limita los lotes a la existencia.', 'quantity');
        }

        $replaced = $this->replaceSnapshots($tenant->id, $present, $catalog, false);
        if ($replaced > 0) {
            $ctx->findings->aggregate('lots_replaced', 'warning', $replaced,
                'Foto de lotes reemplazada: lotes de la misma foto (sucursal × producto × fecha) que el archivo no trae se eliminan.', 'lot_code');
        }

        $stats += ['rows_error' => $ctx->issues->rowsWithErrors(), 'lots_replaced' => $replaced, 'rows_warning' => $ctx->issues->rowsWithWarnings(), 'rows_valid' => count($valid),
            'series_exceeding_on_hand' => $exceeding, 'products_new' => $catalog->productsToCreate(), 'branches_new' => $catalog->branchesToCreate(),
            'normalized_preview' => $preview];

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
            // Reemplazo de la foto + escritura en UNA transacción: o se aplica todo o nada (un fallo no deja la foto sin sus lotes).
            DB::transaction(function () use ($valid, $tenant, $catalog, $now, $present): void {
                $this->replaceSnapshots($tenant->id, $present, $catalog, true);
                foreach (array_chunk($valid, 1000) as $chunk) {
                    DB::table('inventory_lots')->upsert(array_map(fn ($r) => [
                        'tenant_id' => $tenant->id,
                        'branch_id' => $catalog->branchId($r['branch_code']),
                        'product_id' => $catalog->productId($r['product_sku']),
                        'lot_code' => $r['lot_code'],
                        'snapshot_date' => $r['snapshot_date'],
                        'expiry_date' => $r['expiry_date'],
                        'quantity' => $r['quantity'],
                        'created_at' => $now, 'updated_at' => $now,
                    ], $chunk), ['tenant_id', 'branch_id', 'product_id', 'lot_code', 'snapshot_date'], ['expiry_date', 'quantity', 'updated_at']);
                }
            });
            $stats['rows_written'] = count($valid);
        }

        return ['stats' => $stats, 'issues' => $ctx->issues->summary()];
    }

    /**
     * Semántica de reemplazo por foto: lotes de cada (sucursal, producto, fecha) presente en el archivo que el archivo no trae. Con `$delete` los elimina; sin
     * él (validación) solo los cuenta. Devuelve cuántos son. Una serie cuya sucursal o producto aún no existe no tiene lotes que reemplazar.
     *
     * @param  array<string, array{date: string, branch: string, sku: string, codes: array<string, true>}>  $present
     */
    private function replaceSnapshots(int $tenantId, array $present, CatalogResolver $catalog, bool $delete): int
    {
        $total = 0;
        foreach ($present as $t) {
            $branchId = $catalog->branchId($t['branch']);
            $productId = $catalog->productId($t['sku']);
            if ($branchId === null || $productId === null) {
                continue;
            }
            $query = DB::table('inventory_lots')->where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('product_id', $productId)
                ->where('snapshot_date', $t['date'])->whereNotIn(DB::raw('UPPER(lot_code)'), array_keys($t['codes']));
            $total += $delete ? $query->delete() : $query->count();
        }

        return $total;
    }

    /**
     * Perecibilidad declarada de los productos de la empresa (sku normalizado => bandera y vida útil).
     *
     * @return array<string, array{is_perishable: bool, shelf_life_days: ?int}>
     */
    private function perishabilityBySku(int $tenantId): array
    {
        $out = [];
        foreach (DB::table('products')->where('tenant_id', $tenantId)->get(['sku', 'is_perishable', 'shelf_life_days']) as $row) {
            $out[CatalogResolver::norm($row->sku)] = [
                'is_perishable' => (bool) $row->is_perishable,
                'shelf_life_days' => $row->shelf_life_days !== null ? (int) $row->shelf_life_days : null,
            ];
        }

        return $out;
    }

    /**
     * Series (sucursal × producto × fecha) cuyos lotes suman más que la existencia del snapshot de ese mismo día.
     *
     * @param  list<array<string, mixed>>  $valid
     */
    private function lotsExceedingOnHand(int $tenantId, array $valid, CatalogResolver $catalog): int
    {
        $sums = [];
        $byDate = [];
        foreach ($valid as $r) {
            $branchId = $catalog->branchId($r['branch_code']);
            $productId = $catalog->productId($r['product_sku']);
            if ($branchId === null || $productId === null) {
                continue; // sucursal o producto que se creará en la confirmación: aún no hay snapshot que comparar
            }
            $key = $branchId.'|'.$productId.'|'.$r['snapshot_date'];
            $sums[$key] = ($sums[$key] ?? 0.0) + (float) $r['quantity'];
            $byDate[$r['snapshot_date']][$productId] = true;
        }
        $exceeding = 0;
        foreach ($byDate as $date => $products) {
            foreach (array_chunk(array_keys($products), 1000) as $chunk) {
                $rows = DB::table('inventory_snapshots')->where('tenant_id', $tenantId)->where('snapshot_date', $date)
                    ->whereIn('product_id', $chunk)->get(['branch_id', 'product_id', 'on_hand']);
                foreach ($rows as $row) {
                    $sum = $sums[$row->branch_id.'|'.$row->product_id.'|'.$date] ?? null;
                    if ($sum !== null && $sum > (float) $row->on_hand + self::ON_HAND_TOLERANCE) {
                        $exceeding++;
                    }
                }
            }
        }

        return $exceeding;
    }
}
