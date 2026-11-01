<?php

declare(strict_types=1);

namespace App\Services\Imports\Processors;

use App\Services\Catalog\CatalogResolver;
use App\Services\DataCore\Units\UnitNormalizer;
use App\Services\Imports\Parsing\ValueParser;
use App\Services\Imports\Support\Issue;
use Illuminate\Support\Facades\DB;

/**
 * Conversiones de unidades DECLARADAS (cantidad_destino = cantidad_origen × factor). Ninguna conversión se infiere:
 * este es el único lugar donde una unidad distinta se vuelve convertible. Idempotente por
 * (producto|general, unidad origen, unidad destino, vigente desde).
 */
final class UnitConversionImportProcessor implements ImportProcessor
{
    public function run(ImportRunContext $ctx): array
    {
        $tenant = $ctx->tenant;
        $p = $ctx->parser;
        $catalog = new CatalogResolver($tenant, array_merge($ctx->options, ['create_missing_products' => false]));
        $total = $ctx->countRows();
        $valid = [];
        $seen = [];
        $declared = []; // clave de pares en ambos sentidos => factor (para detectar contradicciones)
        foreach (DB::table('unit_conversions')->where('tenant_id', $tenant->id)->get(['product_id', 'from_unit', 'to_unit', 'factor', 'valid_from']) as $row) {
            $declared[$this->pairKey($row->product_id !== null ? (int) $row->product_id : null, $row->from_unit, $row->to_unit, $row->valid_from)] = (float) $row->factor;
        }

        foreach ($ctx->canonicalRows() as $n => $raw) {
            $issues = [];
            $natural = null;
            $sku = $p->string($raw['product_sku'] ?? null, 100);
            $productId = null;
            if ($sku !== null) {
                $productId = $catalog->productId($sku);
                if ($productId === null) {
                    $issues[] = Issue::error('unknown_product', "El producto '{$sku}' no existe en la empresa.", 'product_sku', $sku);
                }
            }
            $from = UnitNormalizer::normalize($p->string($raw['from_unit'] ?? null, 20));
            $to = UnitNormalizer::normalize($p->string($raw['to_unit'] ?? null, 20));
            if ($from === null) {
                $issues[] = Issue::error('required', 'Falta la unidad de origen.', 'from_unit');
            }
            if ($to === null) {
                $issues[] = Issue::error('required', 'Falta la unidad de destino.', 'to_unit');
            }
            if ($from !== null && $to !== null && $from === $to) {
                $issues[] = Issue::error('invalid_value', 'La unidad de origen y la de destino son la misma: no hay nada que convertir.', 'to_unit', $to);
            }
            $factor = null;
            if (ValueParser::isBlank($raw['factor'] ?? null)) {
                $issues[] = Issue::error('required', 'Falta el factor de conversión.', 'factor');
            } elseif (($factor = $p->number($raw['factor'])) === null || $factor <= 0 || $factor > 1e9) {
                $issues[] = Issue::error('invalid_number', 'El factor debe ser un número mayor que cero.', 'factor', $raw['factor']);
                $factor = null;
            }
            $validFrom = null;
            if (! ValueParser::isBlank($raw['valid_from'] ?? null)) {
                $validFrom = ($parsed = $p->dateTime($raw['valid_from'])) !== null ? $parsed[0]->toDateString() : null;
                if ($validFrom === null) {
                    $issues[] = Issue::error('invalid_date', 'Fecha de vigencia no válida.', 'valid_from', $raw['valid_from']);
                }
            }
            if ($from !== null && $to !== null && $factor !== null) {
                $natural = $this->pairKey($productId, $from, $to, $validFrom);
                if (isset($seen[$natural])) {
                    $issues[] = Issue::error('duplicate_in_file', 'Conversión repetida en el archivo (mismo producto, unidades y vigencia).', 'from_unit', $from);
                }
                $inverse = $declared[$this->pairKey($productId, $to, $from, $validFrom)] ?? null;
                if ($inverse !== null && abs($factor * $inverse - 1.0) > 1e-6) {
                    $issues[] = Issue::error('conversion_conflict', 'Ya existe la conversión en sentido contrario con un factor que no es el inverso.', 'factor', $raw['factor']);
                }
            }
            $ctx->issues->addMany($n, $issues);
            if (! $ctx->issues->rowHasError($n)) {
                if ($natural !== null) {
                    $seen[$natural] = true;
                }
                $declared[$natural ?? ''] = $factor;
                $valid[] = ['product_id' => $productId, 'from_unit' => $from, 'to_unit' => $to, 'factor' => $factor, 'valid_from' => $validFrom, 'source' => $p->string($raw['source'] ?? null, 30) ?? 'import'];
            }
            $ctx->progress($n, $total, 5, 60);
        }
        $ctx->issues->flush();

        $stats = ['rows_total' => $total, 'rows_error' => $ctx->issues->rowsWithErrors(), 'rows_warning' => $ctx->issues->rowsWithWarnings(), 'rows_valid' => count($valid),
            'conversions_new' => 0, 'conversions_updated' => 0, 'conversions_unchanged' => 0, 'products_new' => 0, 'branches_new' => 0, 'normalized_preview' => array_slice($valid, 0, 20)];
        $current = [];
        foreach (DB::table('unit_conversions')->where('tenant_id', $tenant->id)->get(['id', 'product_id', 'from_unit', 'to_unit', 'factor', 'valid_from']) as $row) {
            $current[$this->pairKey($row->product_id !== null ? (int) $row->product_id : null, $row->from_unit, $row->to_unit, $row->valid_from)] = $row;
        }
        $plan = [];
        foreach ($valid as $item) {
            $existing = $current[$this->pairKey($item['product_id'], $item['from_unit'], $item['to_unit'], $item['valid_from'])] ?? null;
            if ($existing === null) {
                $stats['conversions_new']++;
                $plan[] = [null, $item];
            } elseif (abs((float) $existing->factor - $item['factor']) > 1e-9 * max(1.0, $item['factor'])) {
                $stats['conversions_updated']++;
                $plan[] = [(int) $existing->id, $item];
            } else {
                $stats['conversions_unchanged']++;
            }
        }
        if ($ctx->commit && $plan !== []) {
            DB::transaction(function () use ($plan, $tenant): void {
                $now = now();
                foreach ($plan as [$id, $item]) {
                    if ($id === null) {
                        DB::table('unit_conversions')->insert($item + ['tenant_id' => $tenant->id, 'created_at' => $now, 'updated_at' => $now]);
                    } else {
                        DB::table('unit_conversions')->where('tenant_id', $tenant->id)->where('id', $id)->update(['factor' => $item['factor'], 'source' => $item['source'], 'updated_at' => $now]);
                    }
                }
            });
        }

        return ['stats' => $stats, 'issues' => $ctx->issues->summary()];
    }

    private function pairKey(?int $productId, string $from, string $to, ?string $validFrom): string
    {
        return implode('|', [$productId ?? 0, $from, $to, $validFrom ?? '']);
    }
}
